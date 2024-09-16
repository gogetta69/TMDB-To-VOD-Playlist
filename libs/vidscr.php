<?php

// Original source for this code: https://github.com/cool-dev-guy/vidsrc-api

$GLOBALS['VIDSRC_KEY'] = "WXrUARXb1aDLaZjI";
$GLOBALS['SOURCES'] = ['Vidplay', 'Filemoon', "F2Cloud"];
$GLOBALS['vidsrcBase'] = "https://vidsrc.to";
$GLOBALS['vidplayBase'] = "https://vidplay.online";

function fetchData($url, $headers = [], $redirect = true) {
	
	global $timeOut, $HTTP_PROXY, $USE_HTTP_PROXY;
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $redirect);
		if (isset($HTTP_PROXY) && isset($USE_HTTP_PROXY) && $USE_HTTP_PROXY === true) {
			curl_setopt($ch, CURLOPT_PROXY, $HTTP_PROXY);       
		}
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_info = curl_getinfo($ch);
        curl_close($ch);

        if ($status_code != 200) {
            throw new Exception("Failed to fetch $url, status code: $status_code");
        }

        return ['body' => $response, 'headers' => $header_info];
    } catch (Exception $e) {
        return false;
    }
}

function decodeBase64UrlSafe($s) {
    try {
        $standardizedInput = str_replace(['_', '-'], ['/', '+'], $s);
        $binaryData = base64_decode($standardizedInput);
        return $binaryData;
    } catch (Exception $e) {
        return false;
    }
}

function adecode($str) {
    try {
        $keyBytes = array_map('ord', str_split('WXrUARXb1aDLaZjI'));
        $j = 0;
        $s = range(0, 255);

        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + $keyBytes[$i % count($keyBytes)]) % 256;
            list($s[$i], $s[$j]) = [$s[$j], $s[$i]];
        }

        $decoded = [];
        $i = 0;
        $k = 0;

        for ($index = 0; $index < strlen($str); $index++) {
            $i = ($i + 1) % 256;
            $k = ($k + $s[$i]) % 256;
            list($s[$i], $s[$k]) = [$s[$k], $s[$i]];
            $t = ($s[$i] + $s[$k]) % 256;
            $decoded[] = ord($str[$index]) ^ $s[$t];
        }

        return implode(array_map('chr', $decoded));
    } catch (Exception $e) {
        return false;
    }
}

function decryptSourceUrl($sourceUrl) {
    $encoded = decodeBase64UrlSafe($sourceUrl);
    if ($encoded === false) return false;
    $decoded = adecode($encoded);
    if ($decoded === false) return false;
    return urldecode($decoded);
}

function get_source($source_id, $SOURCE_NAME) {
    global $vidsrcBase;
    $api_request = fetchData("$vidsrcBase/ajax/embed/source/$source_id");
    if ($api_request === false) return false;
    try {
        $data = json_decode($api_request['body'], true);
        $encrypted_source_url = $data['result']['url'];
        $decoded_url = decryptSourceUrl($encrypted_source_url);
        if ($decoded_url === false) return false;
        return ["decoded" => $decoded_url, "title" => $SOURCE_NAME];
    } catch (Exception $e) {
        return false;
    }
}

function decode_data($key, $data) {
    try {
        $key_bytes = array_map('ord', str_split($key));
        $s = range(0, 255);
        $j = 0;

        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + $key_bytes[$i % count($key_bytes)]) % 256;
            list($s[$i], $s[$j]) = [$s[$j], $s[$i]];
        }

        $decoded = [];
        $i = 0;
        $k = 0;

        for ($index = 0; $index < strlen($data); $index++) {
            $i = ($i + 1) % 256;
            $k = ($k + $s[$i]) % 256;
            list($s[$i], $s[$k]) = [$s[$k], $s[$i]];
            $t = ($s[$i] + $s[$k]) % 256;
            $decoded[] = chr(ord($data[$index]) ^ $s[$t]);
        }

        return implode('', $decoded);
    } catch (Exception $e) {
        return false;
    }
}

function get_futoken($key, $url) {
    global $vidplayBase;
    $response = fetchData("$vidplayBase/futoken", ["Referer: $url"]);
    if ($response === false) return false;
    try {
        preg_match("/var\s+k\s*=\s*'([^']+)'/", $response['body'], $matches);
        $fu_key = $matches[1];
        $fu_token = "$fu_key," . implode(',', array_map(function ($i) use ($fu_key, $key) {
            return ord($fu_key[$i % strlen($fu_key)]) + ord($key[$i]);
        }, range(0, strlen($key) - 1)));
        return $fu_token;
    } catch (Exception $e) {
        return false;
    }
}

function handle($url) {
    global $vidplayBase;

    $URL = explode("?", $url);
    $SRC_URL = $URL[0];
    $SUB_URL = $URL[1];

    // GET SUB
    // Implement subtitle fetching here
    $subtitles = [];

    // DECODE SRC
    $key_req = fetchData('https://raw.githubusercontent.com/joshholly/vidsrc-keys/main/keys.json');
    if ($key_req === false) return false;
    try {
        list($key1, $key2) = json_decode($key_req['body'], true);
        $decoded_id = decode_data($key1, explode('/e/', $SRC_URL)[1]);
        $encoded_result = decode_data($key2, $decoded_id);
        $encoded_base64 = base64_encode($encoded_result);
        $key = str_replace('/', '_', $encoded_base64);

        // GET FUTOKEN
        $data = get_futoken($key, $url);
        if ($data === false) return false;

        // GET SRC
        $req = fetchData("$vidplayBase/mediainfo/$data?$SUB_URL&autostart=true", ["Referer: $url"]);
        if ($req === false) return false;
        $req_data = json_decode($req['body'], true);

        // RETURN IT
        if (is_array($req_data['result'])) {
            return $req_data['result']['sources'][0]['file'] ?? false;
        } else {
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
}

function get_stream($source_url, $SOURCE_NAME) {
    global $SOURCES;
    try {
        if ($SOURCE_NAME == $SOURCES[0] || $SOURCE_NAME == $SOURCES[2]) {
            return handle($source_url);
        } elseif ($SOURCE_NAME == $SOURCES[1]) {
            // Assuming filemoon_handle is similar to vidplay_handle
            return handle($source_url);
        } else {
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
}

function vidplayExtract($dbid, $s = null, $e = null) {
    global $vidsrcBase, $SOURCES;
	
    $media = ($s !== null && $e !== null) ? 'tv' : 'movie';
    $id_url = "$vidsrcBase/embed/$media/$dbid" . (($s && $e) ? "/$s/$e" : '');
    $id_request = fetchData($id_url);
    if ($id_request === false) return false;
    try {
        $soup = new DOMDocument();
        @$soup->loadHTML($id_request['body']);
        $xpath = new DOMXPath($soup);
        $sources_code = $xpath->query("//a[@data-id]")->item(0)->getAttribute('data-id');
        if ($sources_code == null) {
            return false;
        } else {
            $source_id_request = fetchData("$vidsrcBase/ajax/embed/episode/$sources_code/sources");
            if ($source_id_request === false) return false;
            $source_id = json_decode($source_id_request['body'], true)['result'];
            $source_results = [];
            foreach ($source_id as $source) {
                if (in_array($source['title'], $SOURCES)) {
                    $source_results[] = ['id' => $source['id'], 'title' => $source['title']];
                }
            }

            foreach ($source_results as $R) {
                $source_data = get_source($R['id'], $R['title']);
                if ($source_data === false) continue;
                $stream_data = get_stream($source_data['decoded'], $source_data['title']);
                if ($stream_data !== false) {
                    return $stream_data;
                }
            }
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
}

/* // Example usage
try {
    // For movies
    $streamsData = vidplayExtract('105'); // Example TMDB ID for a movie
    print_r($streamsData);

    // For TV shows
    //$streamsData = vidplayExtract('72844', 1, 1); // Example TMDB ID with season and episode
    //print_r($streamsData);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
} */
?>