<?php

set_time_limit(300);
ini_set('memory_limit', '512M');

$epgUrls = [
    "http://m3u4u.com/xml/jwmzn1wx72cj6m8dn721",   
    "https://raw.githubusercontent.com/matthuisman/i.mjh.nz/master/PlutoTV/us.xml",
    "https://epg.pw/xmltv/epg_ZA.xml"
    // "channels/thetvapp_sports_epg.xml" // Disabled until a better sync method is found.
];

$mergedEpgFile = "channels/epg.xml";
$lastUpdatedFile = "channels/last_updated_epg.txt";

if (!file_exists($lastUpdatedFile) || (time() - file_get_contents($lastUpdatedFile)) > 10800) {
    $mergedXml = new SimpleXMLElement('<tv/>');

    foreach ($epgUrls as $url) {
        $epgContent = @file_get_contents($url);
       
        if ($epgContent === false) {
            continue;
        }

        // Check if the content is gzipped and decompress it
        if (substr($url, -3) == '.gz') {
            $epgContent = @gzdecode($epgContent);
            if ($epgContent === false) {
                continue;
            }
        }

        $xml = new SimpleXMLElement($epgContent);

        // Merge the data
        foreach ($xml->channel as $channel) {
            $dom = dom_import_simplexml($mergedXml);
            $dom2 = dom_import_simplexml($channel);
            $dom->appendChild($dom->ownerDocument->importNode($dom2, true));
        }

        foreach ($xml->programme as $programme) {
            $dom = dom_import_simplexml($mergedXml);
            $dom2 = dom_import_simplexml($programme);
            $dom->appendChild($dom->ownerDocument->importNode($dom2, true));
        }
    }

    // Save the merged EPG
    file_put_contents($mergedEpgFile, $mergedXml->asXML());
    file_put_contents($lastUpdatedFile, time());
}

echo @file_get_contents($mergedEpgFile);



?>
