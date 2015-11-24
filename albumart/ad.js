//Discovery 
var mdns = require('mdns');

var txt_record = {
                        volumioName: 'opera',
                        UUID: 'adfsda3546y4g'
                };

// advertise a http server on port 4321
var ad = mdns.createAdvertisement(mdns.tcp('volumio'),3000, {txtRecord: txt_record});
ad.start();
