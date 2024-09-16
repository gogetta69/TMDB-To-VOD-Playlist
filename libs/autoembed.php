<?php
function autoembedExtract($type, $tmdbId, $season = null, $episode = null, $region = 'us') {
    $baseUrl = 'https://player.autoembed.cc/embed/';
    $allowedRegions = [];

    // Allow both 'gb' and 'us' for English-speaking regions
    if ($region === 'en' || $region === 'gb' || $region === 'us') {
        $allowedRegions = ['gb', 'us'];
    } else {
        $allowedRegions = [$region]; 
    }

    // Build the URL based on the type
    if ($type === 'movie') {
        $url = $baseUrl . "movie/$tmdbId?server=1";
    } elseif ($type === 'series' && $season !== null && $episode !== null) {
        $url = $baseUrl . "tv/$tmdbId/$season/$episode?server=1";
    } else {
        throw new Exception('Invalid parameters for fetching file link.');
    }

    try {
        $response = @file_get_contents($url);
        if ($response === FALSE) {
            throw new Exception('Error fetching the URL: ' . $url);
        }

        // Match all data-server values
        preg_match_all('/data-server="([^"]+)"/', $response, $serverMatches);

        // Match all flag src values
        preg_match_all('/<img src="([^"]+)"/', $response, $flagMatches);

        // Check if matches are found
        if (empty($serverMatches[1]) || empty($flagMatches[1])) {
            error_log('No matches for data-server or flag src found.');
            return false;
        }

        // Process each server and filter based on region
        foreach ($serverMatches[1] as $index => $server) {
            $flagUrl = $flagMatches[1][$index];
            $flagRegion = strtolower(substr($flagUrl, strpos($flagUrl, '/flagsapi.com/') + 14, 2));

            // Skip if the flag's region is not allowed
            if (!in_array($flagRegion, $allowedRegions)) {
                continue;
            }

            // Decode the server link
            $decodedUrl = base64_decode($server);
            $serverResponse = @file_get_contents($decodedUrl);

            if ($serverResponse === FALSE) {
                continue;
            }

/*             // Skip file links containing "file": [{"title": "Hindi"
            if (strpos($serverResponse, '"file": [{"title": "Hindi"') !== false) {
                continue;
            } */

            // Match the file link (both formats)
            preg_match('/file:\s*"([^"]+)"|"file":\s*"([^"]+)"/', $serverResponse, $fileMatch);

            if (!empty($fileMatch[1])) {
                return $fileMatch[1]; 
            } elseif (!empty($fileMatch[2])) {
                return $fileMatch[2];
            }
        }

        return false; 
    } catch (Exception $e) {
        return false;
    }
}



/* // Example usage
$type = 'movie'; // or 'series'
$tmdbId = 105;
$season = 1;
$episode = 1;

$fileLink = autoembedExtract($type, $tmdbId, $season, $episode);
if ($fileLink) {
    echo "File link found: $fileLink";
} else {
    echo "No file link found.";
} */
?>
