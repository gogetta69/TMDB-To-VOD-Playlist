<?php
set_time_limit(1200);
ini_set('memory_limit', '1024M');


$hoursPast = 24;    
// configurable days forward (default = 3)
$daysForward = isset($_GET['days']) ? max(1, intval($_GET['days'])) : 3;

$lastUpdatedFile  = __DIR__ . '/channels/last_updated_epg.txt';
$cacheGzFile      = __DIR__ . '/channels/epg.xml.gz';
$epgUrls = [
    "https://raw.githubusercontent.com/lubby1234/epgs/main/merged2_epg.xml.gz"
];

/* ---- Refresh every 3 h ---------------------------------------- */
if (!file_exists($lastUpdatedFile) || (time() - file_get_contents($lastUpdatedFile)) > 10800) {

    $tmpXml = tempnam(sys_get_temp_dir(), 'epg_'); // temp raw XML
    $writer = new XMLWriter();
    $writer->openURI($tmpXml);
    $writer->startDocument('1.0', 'UTF-8');
    $writer->startElement('tv');

    foreach ($epgUrls as $url) {
        $tmpFile = streamFixAndSave($url);

        if (!$tmpFile) continue;

        $xr = new XMLReader();
        if ($xr->open($tmpFile, null, LIBXML_NONET | LIBXML_NOWARNING)) {
            while ($xr->read()) {
                if ($xr->nodeType === XMLReader::ELEMENT) {
                    if ($xr->name === 'channel') {
                        $writer->writeRaw($xr->readOuterXML());
                    } elseif ($xr->name === 'programme') {
                        $startAttr = $xr->getAttribute('start');
                        $stopAttr  = $xr->getAttribute('stop');

                        if (keepProgramme($startAttr, $stopAttr)) {
                            $writer->writeRaw($xr->readOuterXML());
                        }
                    }
                }
            }
            $xr->close();
        }
        unlink($tmpFile);
    }

    $writer->endElement(); // </tv>
    $writer->endDocument();
    $writer->flush();

    // gzip compress the merged XML
    $xmlContent = file_get_contents($tmpXml);
    $gzData = gzencode($xmlContent, 9); // max compression
    file_put_contents($cacheGzFile, $gzData);

    unlink($tmpXml);
    file_put_contents($lastUpdatedFile, time());
}

/* ---- Serve the cached gzipped EPG ----------------------------------- */
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="epg.xml.gz"');
readfile($cacheGzFile);
exit;


/* ---------------- helper funcs -------------------------------- */

function streamFixAndSave(string $url): ?string {

    $tmpFile = tempnam(sys_get_temp_dir(), 'epg_');
    $out = fopen($tmpFile, 'w');
    if (!$out) return null;

    if (substr($url, -3) === '.gz') {
        $in = @gzopen($url, 'r');
        if (!$in) { fclose($out); unlink($tmpFile); return null; }
        while (!gzeof($in)) {
            $line = gzgets($in, 8192);
            if ($line === false) break;
            $line = fixXMLIssues($line);
            fwrite($out, $line);
        }
        gzclose($in);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 30]]);
        $in = @fopen($url, 'r', false, $ctx);
        if (!$in) { fclose($out); unlink($tmpFile); return null; }
        while (!feof($in)) {
            $line = fgets($in, 8192);
            if ($line === false) break;
            $line = fixXMLIssues($line);
            fwrite($out, $line);
        }
        fclose($in);
    }

    fclose($out);
    return $tmpFile;
}

function fixXMLIssues(string $xml): string {
    $xml = str_replace('&amp;amp;', '&amp;', $xml);
    $xml = preg_replace('/<\/programme>\s*<programme/', "</programme>\n<programme", $xml);
    $xml = preg_replace('/[^\x20-\x7E]/', '', $xml);
    return $xml;
}

function keepProgramme(?string $start, ?string $stop): bool {
    global $daysForward, $hoursPast; 

    if (!$start) return false;

    $startTs = strtotime(substr($start, 0, 14));
    if (!$startTs) return false;

    $now        = time();
    $minAllowed = $now - $hoursPast * 3600;  // drop anything > N hours in the past
    $maxAllowed = $now + $daysForward * 86400;  // keep up to N days ahead

    return ($startTs >= $minAllowed && $startTs <= $maxAllowed);
}

?>
