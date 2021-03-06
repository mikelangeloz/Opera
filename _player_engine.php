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
 * file:							player_engine.php
 * version:						1.0
 *
 */

include('inc/connection.php');
playerSession('open',$db,'','');

if (!$mpd) {
    	echo 'Error Connecting MPD Daemon';

} else {
		// fetch MPD status
		$status = _parseStatusResponse(MpdStatus($mpd));

		// check for CMediaFix
		if (isset($_SESSION['cmediafix']) && $_SESSION['cmediafix'] == 1) {
			$_SESSION['lastbitdepth'] = $status['audio'];

		}

		// check for Ramplay
		if (isset($_SESSION['ramplay']) && $_SESSION['ramplay'] == 1) {
			// record "lastsongid" in PHP SESSION
			$_SESSION['lastsongid'] = $status['songid'];

			// controllo per cancellazione ramplay
				// if (!rp_checkPLid($_SESSION['lastsongid'],$mpd)) {
				// rp_deleteFile($_SESSION['lastsongid'],$mpd);
				// }
			// recupero id nextsong e metto in sessione
			$_SESSION['nextsongid'] = $status['nextsongid'];

		}

		// register player STATE in SESSION
		$_SESSION['state'] = $status['state'];

		// Unlock SESSION file
		session_write_close();

		// -----  check and compare GUI state with Backend state  ----  //
		if ($_GET['state'] == $status['state']) {
		// If the playback state is the same as specified in the ajax call
			// Wait until the status changes and then return new status
			$status = monitorMpdState($mpd);

		}
		// -----  check and compare GUI state with Backend state  ----  //

		$curTrack = getTrackInfo($mpd,$status['song']);

		if (isset($curTrack[0]['Title'])) {
			$status['currentartist'] = $curTrack[0]['Artist'];
			$status['currentsong'] = $curTrack[0]['Title'];
			$status['currentalbum'] = $curTrack[0]['Album'];
			$status['fileext'] = parseFileStr($curTrack[0]['file'],'.');
			$string = $curTrack[0]['file'];
			$plorp = substr(strrchr($string,'/'), 1);
            $asd = substr($string, 0, - strlen($plorp));
			$status['currentalbumart'] = '<img src="';
			$status['currentalbumart'] .= 'http://';
			$status['currentalbumart'] .= $_SERVER['SERVER_ADDR'];
			$status['currentalbumart'] .= ':3001/albumart?';
			//$status['currentalbumart'] .= 'web=';
			//$status['currentalbumart'] .= $curTrack[0]['Artist'];
			//$status['currentalbumart'] .= '/';
			//$status['currentalbumart'] .= $curTrack[0]['Album'];
			//$status['currentalbumart'] .= '/extralarge&';
      $status['currentalbumart'] .= 'path=';
            $status['currentalbumart'] .= '/mnt/';
            $status['currentalbumart'] .= urlencode($asd);
            $status['currentalbumart'] .= '">';


		} else {
			$string = $curTrack[0]['file'];
            $plorp = substr(strrchr($string,'/'), 1);
            $asd = substr($string, 0, - strlen($plorp));
			$path = parseFileStr($curTrack[0]['file'],'/');
			$status['fileext'] = parseFileStr($curTrack[0]['file'],'.');
			$status['currentartist'] = "";
			$status['currentsong'] = $song;
			$status['currentalbum'] = $path;
			$status['currentalbumart'] = '<img src="';
            $status['currentalbumart'] .= 'http://';
            $status['currentalbumart'] .= $_SERVER['SERVER_ADDR'];
            $status['currentalbumart'] .= ':3001/albumart?';
			$status['currentalbumart'] .= 'path=';
            $status['currentalbumart'] .= '/mnt/';
            $status['currentalbumart'] .= urlencode($asd);
            $status['currentalbumart'] .= '">';
		}

		// CMediaFix
		if (isset($_SESSION['cmediafix']) && $_SESSION['cmediafix'] == 1 && $status['state'] == 'play' ) {
			$status['lastbitdepth'] = $_SESSION['lastbitdepth'];

			if ($_SESSION['lastbitdepth'] != $status['audio']) {
				sendMpdCommand($mpd,'cmediafix');

			}

		}

		// Ramplay
		if (isset($_SESSION['ramplay']) && $_SESSION['ramplay'] == 1) {
				// set consume mode ON
				// if ($status['consume'] == 0) {
				// sendMpdCommand($mpd,'consume 1');
				// $status['consume'] = 1;
				// }

			// copio il pezzo in /dev/shm
			$path = rp_copyFile($status['nextsongid'],$mpd);

			// lancio update mdp locazione ramplay
			rp_updateFolder($mpd);

			// lancio addandplay canzone
			rp_addPlay($path,$mpd,$status['playlistlength']);

		}

		// JSON response for GUI
		echo json_encode($status);

	closeMpdSocket($mpd);

}

?>
