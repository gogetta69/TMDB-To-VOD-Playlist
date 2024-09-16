<?php

// Original source for this code: https://github.com/Zenda-Cross/vega-app

function fetchUrl($url) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);      
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $output = curl_exec($ch);
        curl_close($ch);
        if ($output === false) {
            throw new Exception('Failed to fetch URL');
        }
        return $output;
    } catch (Exception $e) {
        return false;
    }
}

function xorEncryptDecrypt($key, $data) {
    try {
        $keyChars = array_map('ord', str_split($key));
        $dataChars = array_map('ord', str_split($data));
        $result = [];
        foreach ($dataChars as $index => $char) {
            $result[] = chr($char ^ $keyChars[$index % count($keyChars)]);
        }
        return implode('', $result);
    } catch (Exception $e) {
        return false;
    }
}

function fetchKeyFromImage() {
    $response = fetchUrl('https://vidsrc.rip/images/skip-button.png');
    if ($response === false) {
        return false;
    }
    return $response;
}

function generateVRF($sourceIdentifier, $tmdbId) {
    try {
        $key = fetchKeyFromImage();
        if ($key === false) {
            throw new Exception('Failed to fetch key from image');
        }
        $input = "/api/source/{$sourceIdentifier}/{$tmdbId}";
        $decodedInput = urldecode($input);
        $xorResult = xorEncryptDecrypt($key, $decodedInput);
        if ($xorResult === false) {
            throw new Exception('XOR Encryption failed');
        }
        $vrf = urlencode(base64_encode($xorResult));
        return $vrf;
    } catch (Exception $e) {
        return false;
    }
}

function useVRF($sourceIdentifier, $tmdbId, $season = null, $episode = null) {
    try {
        $vrf = generateVRF($sourceIdentifier, $tmdbId);
        if ($vrf === false) {
            throw new Exception('Failed to generate VRF');
        }
        $params = '';
        if (!is_null($season) && !is_null($episode)) {
            $params = "&s={$season}&e={$episode}";
        }
        $apiUrl = "/api/source/{$sourceIdentifier}/{$tmdbId}?vrf={$vrf}{$params}";
        return $apiUrl;
    } catch (Exception $e) {
        return false;
    }
}

function getVidSrcRip($tmdbId, $type, &$stream, $season = null, $episode = null) {
    try {
        $sources = ['flixhq', 'vidsrcuk', 'vidsrcicu'];
        $baseUrl = base64_decode('aHR0cHM6Ly92aWRzcmMucmlw');
        foreach ($sources as $source) {
            $apiUrl = useVRF($source, $tmdbId, ($type == 'series' ? $season : null), ($type == 'series' ? $episode : null));
            if ($apiUrl === false) {
                throw new Exception('Failed to use VRF');
            }
            $response = json_decode(fetchUrl($baseUrl . $apiUrl), true);
            if ($response === false || empty($response['sources'])) {
                continue; // Skip to next source if current source fails
            }
            if (count($response['sources']) > 0) {
                $stream[] = [
                    'server' => $source,
                    'type' => strpos($response['sources'][0]['file'], '.mp4') !== false ? 'mp4' : 'm3u8',
                    'link' => $response['sources'][0]['file']
                ];
            }
        }
        return $stream;
    } catch (Exception $e) {
        return false;
    }
}

/*// Usage
$tvStream = [];
if (getVidSrcRip('87917', 'series', $tvStream, '1', '3')) {
    print_r($tvStream);
} else {
    echo "Failed to retrieve TV series data.\n";
}*/

/* // Usage for a movie
$movieStream = [];
if (getVidSrcRip('105', 'movies', $movieStream)) {
    print_r($movieStream);
} else {
    echo "Failed to retrieve movie data.\n";
} */


?>
