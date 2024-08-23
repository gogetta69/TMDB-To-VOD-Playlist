<?php

set_time_limit(300);

$remoteEpgUrl = 'https://raw.githubusercontent.com/gogetta69/public-files/main/epg.xml';
$localEpgFilePath = 'channels/epg.xml';
$lastUpdatedFile = 'channels/last_updated_epg.txt';

$content = @file_get_contents($remoteEpgUrl);

if ($content === false) {
   
    // Fallback: Check if we need to update the local EPG file
    if (!file_exists($lastUpdatedFile) || (time() - file_get_contents($lastUpdatedFile)) > 10800) {
        $epgUrls = [
            "http://m3u4u.com/xml/jwmzn1wx72cj6m8dn721",
            "https://raw.githubusercontent.com/gogetta69/public-files/main/Pluto-TV/us.xml",
            "https://epg.pw/xmltv/epg_ZA.xml",
            "https://epg.pw/api/epg.xml?channel_id=9025",
            "https://epg.pw/api/epg.xml?channel_id=8862",
            "https://epg.pw/api/epg.xml?channel_id=8306"
        ];

        $mergedXml = new SimpleXMLElement('<tv/>');

        foreach ($epgUrls as $url) {
            $epgContent = fetchEPGContent($url);

            if ($epgContent === false) {
                continue;
            }

            // Check if the content is gzipped and decompress it
            if (substr($url, -3) == '.gz') {
                $epgContent = @gzdecode($epgContent);
                if ($epgContent === false) {
                    error_log("Failed to decompress gzipped content from URL: $url");
                    continue;
                }
            }

            // Fix the XML issues before parsing
            $epgContent = fixXMLIssues($epgContent);

            // Check if the XML content is valid
            if (!preg_match('/<tv[^>]*>.*<\/tv>/s', $epgContent)) {
                error_log("Invalid XML structure from URL: $url");
                continue;
            }

            $xml = @simplexml_load_string($epgContent);
            if ($xml === false) {
                error_log("Failed to parse XML from URL: $url");
                continue;
            }

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
        file_put_contents($localEpgFilePath, $mergedXml->asXML());
        file_put_contents($lastUpdatedFile, time());
    }

    // Use the local EPG file as the fallback content
    $content = @file_get_contents($localEpgFilePath);
    if ($content === false) {
        // Log an error if reading the local file also fails
        error_log('Failed to fetch the local EPG file.');
        header('HTTP/1.1 500 Internal Server Error');
        echo '<error>Unable to fetch the EPG data</error>';
        exit;
    }
} else {
    // Save the fetched remote content to the local file for future use
    if (file_put_contents($localEpgFilePath, $content) === false) {
        // Log an error if saving the content fails
        error_log('Failed to save the remote EPG content to the local file.');
    } else {
        file_put_contents($lastUpdatedFile, time());
	}
}

// Output the EPG content
header('Content-Type: application/xml');
echo $content;
exit;

function fetchEPGContent($url) {
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 30
        )
    ));
    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        error_log("Failed to fetch URL: $url");
        return false;
    }
    return $content;
}

function fixXMLIssues($xmlContent) {
    // Fix common encoding issues
    $xmlContent = str_replace('&amp;amp;', '&amp;', $xmlContent);

    // Ensure each <programme> tag is properly closed and separated
    $xmlContent = preg_replace('/<\/programme>\s*<programme/', "</programme>\n<programme", $xmlContent);

    // Clean up any hidden or special characters
    $xmlContent = preg_replace('/[^\x20-\x7E]/', '', $xmlContent);

    return $xmlContent;
}
?>
