#!/usr/bin/php5
<?php
/*
 *      PlayerUI Copyright (C) 2013 Andrea Coiutti & Simone De Gregori
 *		 Tsunamp Team
 *      http://www.tsunamp.com
 *
 *  This Program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3, or (at your option)
 *  any later version.
 *
 *  This Program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with RaspyFi; see the file COPYING.  If not, see
 *  <http://www.gnu.org/licenses/>.
 *
 *
 *	UI-design/JS code by: 	Andrea Coiutti (aka ACX)
 * PHP/JS code by:			Simone De Gregori (aka Orion)
 *
 * file:							player_wrk.php
 * version:						1.0
 *
 */

// common include
include('/var/www/inc/player_lib.php');
ini_set('display_errors', '1');
ini_set('error_log','/var/log/php_errors.log');
$db = 'sqlite:/var/www/db/player.db';

// --- DEMONIZE ---
	$lock = fopen('/run/player_wrk.pid', 'c+');
	if (!flock($lock, LOCK_EX | LOCK_NB)) {
		die('already running');
	}

	switch ($pid = pcntl_fork()) {
		case -1:
			die('unable to fork');
		case 0: // this is the child process
			break;
		default: // otherwise this is the parent process
			fseek($lock, 0);
			ftruncate($lock, 0);
			fwrite($lock, $pid);
			fflush($lock);
			exit;
	}

	if (posix_setsid() === -1) {
		 die('could not setsid');
	}

	fclose(STDIN);
	fclose(STDOUT);
	fclose(STDERR);

	$stdIn = fopen('/dev/null', 'r'); // set fd/0
	$stdOut = fopen('/dev/null', 'w'); // set fd/1
	$stdErr = fopen('php://stdout', 'w'); // a hack to duplicate fd/1 to 2

	pcntl_signal(SIGTSTP, SIG_IGN);
	pcntl_signal(SIGTTOU, SIG_IGN);
	pcntl_signal(SIGTTIN, SIG_IGN);
	pcntl_signal(SIGHUP, SIG_IGN);
// --- DEMONIZE --- //

// --- INITIALIZE ENVIRONMENT --- //
// change /run and session files for correct session file locking
sysCmd('chmod 777 /run');

// reset DB permission
sysCmd('chmod -R 777 /var/www/db');

// initialize CLI session
session_save_path('/run');

// inpect session
playerSession('open',$db,'','');

// reset session file permissions
sysCmd('chmod 777 /run/sess*');

// mount all sources
wrk_sourcemount($db,'mountall');

// start MPD daemon
sysCmd("service mpd start");

// check Architecture
$arch = wrk_getHwPlatform();
if ($arch != $_SESSION['hwplatformid']) {
// reset playerID if architectureID not match. This condition "fire" another first-install process
playerSession('write',$db,'playerid','');
}
// --- INITIALIZE ENVIRONMENT --- //

// --- PLAYER FIRST INSTALLATION PROCESS --- //
	if (isset($_SESSION['playerid']) && $_SESSION['playerid'] == '') {
	// register HW architectureID and playerID
	wrk_setHwPlatform($db);
	// destroy actual session
	playerSession('destroy',$db,'','');
	// reload session data
	playerSession('open',$db,'','');
	// reset ENV parameters
	wrk_sysChmod();

	// reset netconf to defaults
	$value = array('ssid' => '', 'encryption' => '', 'password' => '');
	$dbh = cfgdb_connect($db);
	cfgdb_update('cfg_wifisec',$dbh,'',$value);
	$file = '/etc/network/interfaces';
	$fp = fopen($file, 'w');
	$netconf = "auto lo\n";
	$netconf .= "iface lo inet loopback\n";
	$netconf .= "\n";
	$netconf .= "auto eth0\n";
	$netconf .= "iface eth0 inet dhcp\n";
	$netconf .= "\n";
	//$netconf .= "auto wlan0\n";
	//$netconf .= "iface wlan0 inet dhcp\n";
	fwrite($fp, $netconf);
	fclose($fp);
	// update hash
	$hash = md5_file('/etc/network/interfaces');
	playerSession('write',$db,'netconfhash',$hash);
	// restart wlan0 interface
		if (strpos($netconf, 'wlan0') != false) {
		$cmd = "ip addr list wlan0 |grep \"inet \" |cut -d' ' -f6|cut -d/ -f1";
		$ip_wlan0 = sysCmd($cmd);
			if (!empty($ip_wlan0[0])) {
			$_SESSION['netconf']['wlan0']['ip'] = $ip_wlan0[0];
			} else {
				if (wrk_checkStrSysfile('/proc/net/wireless','wlan0')) {
				$_SESSION['netconf']['wlan0']['ip'] = '--- NO IP ASSIGNED ---';
				} else {
				$_SESSION['netconf']['wlan0']['ip'] = '--- NO INTERFACE PRESENT ---';
				}
			}
		}
	sysCmd('service networking restart');

	// reset sourcecfg to defaults
	wrk_sourcecfg($db,'reset');
	sendMpdCommand($mpd,'update');

	// reset mpdconf to defaults
	$mpdconfdefault = cfgdb_read('',$dbh,'mpdconfdefault');
	foreach($mpdconfdefault as $element) {
		cfgdb_update('cfg_mpd',$dbh,$element['param'],$element['value_default']);
	}
		// tell worker to write new MPD config
	wrk_mpdconf('/etc',$db);
		// update hash
	$hash = md5_file('/etc/mpd.conf');
	playerSession('write',$db,'mpdconfhash',$hash);
	sysCmd('service mpd restart');
	$dbh = null;

	// disable minidlna / samba / MPD startup
	sysCmd("update-rc.d -f minidlna remove");
	sysCmd("update-rc.d -f ntp remove");
	sysCmd("update-rc.d -f smbd remove");
	sysCmd("update-rc.d -f nmbd remove");
	sysCmd("update-rc.d -f mpd remove");
	sysCmd("echo 'manual' > /etc/init/minidlna.override");
	sysCmd("echo 'manual' > /etc/init/ntp.override");
	sysCmd("echo 'manual' > /etc/init/smbd.override");
	sysCmd("echo 'manual' > /etc/init/nmbd.override");
	sysCmd("echo 'manual' > /etc/init/mpd.override");
	// system ENV files check and replace
	wrk_sysEnvCheck($arch,1);
	// stop services
	sysCmd('service minidlna stop');
	sysCmd('service minidlna ntp');
	sysCmd('service samba stop');
	sysCmd('service mpd stop');
	sysCmd('/usr/sbin/smbd -D --configfile=/var/www/_OS_SETTINGS/etc/samba/smb.conf');
	sysCmd('/usr/sbin/nmbd -D --configfile=/var/www/_OS_SETTINGS/etc/samba/smb.conf');
// --- PLAYER FIRST INSTALLATION PROCESS --- //

// --- NORMAL STARTUP --- //
} else {
	// check ENV files
	if ($arch != '--') {
	wrk_sysEnvCheck($arch,0);
	}
// start samba
sysCmd('/usr/sbin/smbd -D --configfile=/var/www/_OS_SETTINGS/etc/samba/smb.conf');
sysCmd('/usr/sbin/nmbd -D --configfile=/var/www/_OS_SETTINGS/etc/samba/smb.conf');
}

// inizialize worker session vars
//if (!isset($_SESSION['w_queue']) OR $_SESSION['w_queue'] == 'workerrestart') { $_SESSION['w_queue'] = ''; }
$_SESSION['w_queue'] = '';
$_SESSION['w_queueargs'] = '';
$_SESSION['w_lock'] = 0;
//if (!isset($_SESSION['w_active'])) { $_SESSION['w_active'] = 0; }
$_SESSION['w_active'] = 0;
$_SESSION['w_jobID'] = '';
// inizialize debug
$_SESSION['debug'] = 0;
$_SESSION['debugdata'] = '';

// initialize OrionProfile
if ($_SESSION['dev'] == 0) {
$cmd = "/var/www/command/orion_optimize.sh ".$_SESSION['orionprofile']." startup" ;
sysCmd($cmd);
}

//Additional Fix for WLAN power management


// check current eth0 / wlan0 IP Address
$cmd1 = "ip addr list eth0 |grep \"inet \" |cut -d' ' -f6|cut -d/ -f1";
$cmd2 = "ip addr list wlan0 |grep \"inet \" |cut -d' ' -f6|cut -d/ -f1";
$cmd3 = "ip addr list eth0:0 |grep \"inet \" |cut -d' ' -f6|cut -d/ -f1";
$ip_eth0 = sysCmd($cmd1);
$ip_wlan0 = sysCmd($cmd2);
$ip_fallback = "192.168.10.110";

// check IP for minidlna assignment.
if (isset($ip_eth0) && !empty($ip_eth0) && isset($ip_wlan0) && !empty($ip_wlan0)) {
$ip = $ip_eth0[0];
} else  if (isset($ip_eth0) && !empty($ip_eth0)) {
$ip = $ip_eth0[0];
} else if (isset($ip_wlan0) && !empty($ip_wlan0)) {
$ip = $ip_wlan0[0];
} else {
$ip = $ip_fallback;
}

// record current IP addresses in PHP session
if (!empty($ip_eth0[0])) {
$_SESSION['netconf']['eth0']['ip'] = $ip_eth0[0];
}
if (!empty($ip_wlan0[0])) {
$_SESSION['netconf']['wlan0']['ip'] = $ip_wlan0[0];
}

//start minidlna only if selected by user
if (isset($_SESSION['minidlna']) && $_SESSION['minidlna'] == 1) {

// Start minidlna service
sysCmd(' /usr/local/sbin/minidlnad -f /etc/minidlna.conf');
}
// check /etc/network/interfaces integrity
hashCFG('check_net',$db);
// check /etc/mpd.conf integrity
hashCFG('check_mpd',$db);
// check /etc/auto.nas integrity
// hashCFG('check_source',$db);

// unlock session files
playerSession('unlock',$db,'','');

// Cmediafix startup check
if (isset($_SESSION['cmediafix']) && $_SESSION['cmediafix'] == 1) {
	$mpd = openMpdSocket('localhost', 6600) ;
	sendMpdCommand($mpd,'cmediafix');
	closeMpdSocket($mpd);
}
// Utilities to start with Volumio

// Shairport for Airplay Capability
//Retrieve Output Device
	$dbh = cfgdb_connect($db);
	$query_cfg = "SELECT param,value_player FROM cfg_mpd WHERE value_player!=''";
	$mpdcfg = sdbquery($query_cfg,$dbh);
	$dbh = null;
	foreach ($mpdcfg as $cfg) {
		if ($cfg['param'] == 'audio_output_format' && $cfg['value_player'] == 'disabled'){
		$output .= '';
		} else if ($cfg['param'] == 'device') {
		$device = $cfg['value_player'];
		var_export($device);
		}  else {
		$output .= $cfg['param']." \t\"".$cfg['value_player']."\"\n";
		}
	}

playerSession('open',$db);
$hostname = $_SESSION['hostname'];

// Start Shairport with Volumio name, stopping Mpd on start, with Selected output device
if (isset($_SESSION['shairport']) && $_SESSION['shairport'] == 1) {
	$tempfile = '/tmp/.restart_mpd'; // if this file exists, start playing mpd after shairport stopped
	$cmd = '/usr/local/bin/shairport -a "'.$hostname.'" -w -B "(/usr/bin/mpc | grep -q playing && touch \''.$tempfile.'\'); /usr/bin/mpc stop" -E "test -f \''.$tempfile.'\' && /usr/bin/mpc play && rm -f \''.$tempfile.'\'" -o alsa -- -d plughw:'.$device.' > /dev/null 2>&1 &';
	sysCmd($cmd);
}

// Djmount daemon start for DLNA Browsing
if (isset($_SESSION['djmount']) && $_SESSION['djmount'] == 1) {
	$cmd = 'djmount -o allow_other,nonempty,iocharset=utf-8 /mnt/UPNP > /dev/null 2>&1 &';
	sysCmd($cmd);
}

// Mpdupnpcli for UPNP control
if (isset($_SESSION['upnpmpdcli']) && $_SESSION['upnpmpdcli'] == 1) {
	$cmd = '/usr/bin/upmpdcli -f "'.$hostname.'" -l 0 > /dev/null 2>&1 &';
	sysCmd($cmd);
}

// SPOP Daemon for Spotify Plaback
if (isset($_SESSION['spotify']) && $_SESSION['spotify'] == 1) {
	$cmd = ' spopd -c /etc/spopd.conf > /dev/null 2>&1 &';
	sysCmd($cmd);
}

//Startup Sound
if (isset($_SESSION['startupsound']) && $_SESSION['startupsound'] == 1) {
$cmd = 'mpg123 -a hw:'.$device.' /var/www/inc/Sounds/startup.mp3 > /dev/null 2>&1 &';
sysCmd($cmd);
}


// --- NORMAL STARTUP --- //

// --- WORKER MAIN LOOP --- //
while (1) {
sleep(7);
session_start();
	// monitor loop
	if ($_SESSION['w_active'] == 1 && $_SESSION['w_lock'] == 0) {
	$_SESSION['w_lock'] = 1;

	// switch command queue for predefined jobs
	switch($_SESSION['w_queue']) {

		case 'reboot':
		$cmd = 'mpc stop && reboot';
		sysCmd($cmd);
		break;

		case 'poweroff':
		$cmd = 'mpc stop && poweroff';
		sysCmd($cmd);
		break;

		case 'mpdrestart':
		sysCmd('killall mpd');
		sleep(1);
		sysCmd('service mpd start');
		break;

		case 'phprestart':
		$cmd = 'service php5-fpm restart';
		sysCmd($cmd);
		break;

		case 'workerrestart':
		$cmd = 'killall player_wrk.php';
		sysCmd($cmd);
		break;

		case 'updateui':
		$cmd = 'git --work-tree=/var/www --git-dir=/var/www/.git pull origin master';
		sysCmd($cmd);
		break;

		case 'syschmod':
		wrk_syschmod();
		break;

		case 'backup':
		$_SESSION[$_SESSION['w_jobID']] = wrk_backup();
		break;

		case 'totalbackup':
		$_SESSION[$_SESSION['w_jobID']] = wrk_backup('dev');
		break;

		case 'restore':
		$path = "/run/".$_SESSION['w_queueargs'];
		wrk_restore($path);
		break;

		case 'orionprofile':
		if ($_SESSION['dev'] == 1) {
		$_SESSION['w_queueargs'] = 'dev';
		}
		$cmd = "/var/www/command/orion_optimize.sh ".$_SESSION['w_queueargs'];
		sysCmd($cmd);
		break;

		case 'netcfg':
		$file = '/etc/network/interfaces';
		$fp = fopen($file, 'w');
		$netconf = "auto lo\n";
		$netconf .= "iface lo inet loopback\n";
		//$netconf .= "\n";
		//$netconf .= "auto eth0\n";
		$netconf = $netconf.$_SESSION['w_queueargs'];
		fwrite($fp, $netconf);
		fclose($fp);
		// update hash
		$hash = md5_file('/etc/network/interfaces');
		playerSession('write',$db,'netconfhash',$hash);
		// restart wlan0 interface
			if (strpos($netconf, 'wlan0') != false) {
			$cmd = "ip addr list wlan0 |grep \"inet \" |cut -d' ' -f6|cut -d/ -f1";
			$ip_wlan0 = sysCmd($cmd);
				if (!empty($ip_wlan0[0])) {
				$_SESSION['netconf']['wlan0']['ip'] = $ip_wlan0[0];
				} else {
					if (wrk_checkStrSysfile('/proc/net/wireless','wlan0')) {
					$_SESSION['netconf']['wlan0']['ip'] = '--- NO IP ASSIGNED ---';
					} else {
					$_SESSION['netconf']['wlan0']['ip'] = '--- NO INTERFACE PRESENT ---';
					}
				}
			}
		sysCmd('service networking restart');
		break;

		case 'netcfgman':
		$file = '/etc/network/interfaces';
		$fp = fopen($file, 'w');
		fwrite($fp, $_SESSION['w_queueargs']);
		fclose($fp);

		break;

		case 'mpdcfg':
		wrk_mpdconf('/etc',$db);
		// update hash
		$hash = md5_file('/etc/mpd.conf');
		playerSession('write',$db,'mpdconfhash',$hash);
		sysCmd('killall mpd');
		sysCmd('service mpd start');
		break;

		case 'mpdcfgman':
		// write mpd.conf file
		$fh = fopen('/etc/mpd.conf', 'w');
		fwrite($fh, $_SESSION['w_queueargs']);
		fclose($fh);
		sysCmd('killall mpd');
		sysCmd('service mpd start');
		break;

		case 'sourcecfg':
		wrk_sourcecfg($db,$_SESSION['w_queueargs']);
		// rel 1.0 autoFS
		// if (sysCmd('service autofs restart')) {
		// sleep(3);
		// $mpd = openMpdSocket('localhost', 6600);
		// sendMpdCommand($mpd,'update');
		// closeMpdSocket($mpd);
		// }
		break;

		// rel 1.0 autoFS
		// case 'sourcecfgman':
		// if ($_SESSION['w_queueargs'] == 'sourcecfgreset') {
		// wrk_sourcecfg($db,'reset');
		// } else {
		// wrk_sourcecfg($db,'manual',$_SESSION['w_queueargs']);
		// }
		// if (sysCmd('service autofs restart')) {
		// sysCmd('service autofs restart');
		// sleep(3);
		// $mpd = openMpdSocket('localhost', 6600);
		// sendMpdCommand($mpd,'update');
		// closeMpdSocket($mpd);
		// }
		// break;

		case 'enableapc':
		// apc.ini
		$file = "/etc/php5/fpm/conf.d/20-apc.ini";
		$fileData = file($file);
		$newArray = array();
		foreach($fileData as $line) {
		  // find the line that starts with 'presentation_url"
		  if (substr($line, 0, 8) == 'apc.stat') {
			// replace apc.stat with selected value
			$line = "apc.stat = ".$_SESSION['w_queueargs']."\n";
		  }
		  $newArray[] = $line;
		}
		// Commit changes to /etc/php5/fpm/conf.d/20-apc.ini
		$fp = fopen($file, 'w');
		fwrite($fp, implode("",$newArray));
		fclose($fp);
		// Restart PHP service
		sysCmd('service php5-fpm restart');
		playerSession('write',$db,'enableapc',$_SESSION['w_queueargs']);
		break;

	}
	// reset locking and command queue
	$_SESSION['w_queue'] = '';
	$_SESSION['w_queueargs'] = '';
	$_SESSION['w_jobID'] = '';
	$_SESSION['w_active'] = 0;
	$_SESSION['w_lock'] = 0;
	}
session_write_close();
}
// --- WORKER MAIN LOOP --- //
?>
