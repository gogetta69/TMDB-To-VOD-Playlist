<?php

require_once 'config.php';


function getLiveStream($streamId)
{
    if (!isset($streamId)) {
        echo "Missing 'streamId' parameter";
        return;
    }	

    $jsonFilePath = "channels/live_playlist.json";
    $jsonContent = file_get_contents($jsonFilePath);
    $data = json_decode($jsonContent, true);

	$urlParam = '';
	$categoryId = '';

	foreach ($data as $item) {
		if (isset($item['stream_id']) && $item['stream_id'] == $streamId) {
			$urlParam = $item['video_url'];
			$categoryId = $item['category_id'];
			break;
		}
	}	
	

	if (stripos($urlParam, 'thetvapp.to') !== false)   {
		$urlparts = getTheTvAppStream($urlParam, $streamId);
		
		$headers = 'User-Agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0"';
		$headers .= '|Origin="https://thetvapp.to"';
		$headers .= '|Referer="https://thetvapp.to/"';

		$base = locateBaseURL();        
		$urlparts = $base . 'hls_proxy.php?url=' . urlencode($urlparts) . '&data=' . urlencode(base64_encode($headers)) . '&streamId=' . $streamId;  
		
		header('Location: ' . $urlparts, true, 302);
		exit;
	}
	
	if (stripos($urlParam, '.pluto.tv') !== false) {
		$parsedUrl = parse_url($urlParam);
		parse_str($parsedUrl['query'], $queryParams);		
		
		$newDeviceId = generateUuidV4();
		$queryParams['deviceId'] = $newDeviceId;
		
		$newSid = generateShortHexSid();
		$queryParams['sid'] = $newSid;
		
		$newQueryString = http_build_query($queryParams);
		
		$newUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'] . '?' . $newQueryString;

		header('Location: ' . $newUrl, true, 302);
		exit;
	}

	
	if (stripos($urlParam, 'DaddyLive|') !== false) {
		$parts = explode('|', $urlParam);
		
		

		$userAgent = $_SERVER['HTTP_USER_AGENT'];

	/* 		// Check if the User-Agent contains 'TiviMate' case-insensitively
		if (stripos($userAgent, 'TiviMate') !== false) {			
			header("HTTP/1.0 500 Internal Server Error");
			exit;
		}	 */
			
			if (count($parts) >= 3) {
				$data = [
					'url' => $parts[1],				
					'ref' => implode('|', array_slice($parts, 2))
				];

				if ($data) {
					$base = locateBaseURL();        
					$urlparts = $base . 'hls_proxy.php?url=' . urlencode($data['url']) . '&data=' . base64_encode($data['ref']) . '&streamId=' . $streamId;            

					header('Location: ' . $urlparts, true, 301); 
				} else {
					header("HTTP/1.0 404 Not Found");
				}
			} else {
				header("HTTP/1.0 404 Not Found");
			}
			exit;
	}

	if (stripos($urlParam, 'topembed.') !== false) {

		if (stripos($urlParam, 'topembed.pw') !== false) {
			
			$ch = curl_init($urlParam);
    
			$headers = [
				'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/129.0',
				'Referer: https://topembed.pw/'
			];

			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

			$response = curl_exec($ch);
			if (curl_errno($ch)) {
				return false;
			}

			preg_match("/source: '([^']+)'/", $response, $matches);
			if (!isset($matches[1])) {
				return false;
			} else {
				$urlParam = $matches[1];
			}
			
		}		
		
		$headers = 'User-Agent="Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1"';
		$headers .= '|Origin="https://topembed.pw"';
		$headers .= '|Referer="https://topembed.pw/"';

		$base = locateBaseURL();        
		$urlparts = $base . 'hls_proxy.php?url=' . urlencode($urlParam) . '&data=' . urlencode(base64_encode($headers)) . '&streamId=' . $streamId;  
		
		header('Location: ' . $urlparts, true, 302);
		exit;
		
	}	

	
    header('Location: ' . $urlParam, true, 302);
	exit;
}

//Daddy Live functions.
function getDaddyLiveSource($url) {
    $url = str_replace(["/cast/", "/stream/"], "/embed/", $url);

	    
    $ch = curl_init($url);
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/117.0',
        'Referer: https://dlhd.sx/'
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        return false;
    }

    preg_match('/<iframe src="([^"]+)"/', $response, $matches);
    if (!isset($matches[1])) {
        return false;
    }

    $iframe_src = $matches[1];
    
    $parsed_host = parse_url($iframe_src, PHP_URL_SCHEME) . '://' . parse_url($iframe_src, PHP_URL_HOST) . '/';
    
    $ch = curl_init($iframe_src);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response2 = curl_exec($ch);
    if (curl_errno($ch)) {
        return false;
    }

    preg_match('/(?<=source:\').*?\.m3u8(?=\')/', $response2, $matches);
    if (!isset($matches[0])) {
        return false;
    }

    $parsedUrl = $matches[0];
    
    return [
        'url' => $parsedUrl,
        'ref' => '|Referer="' . $parsed_host . '"'
    ];
}

// The TV App functions.
function getTheTvAppStream($url, $streamId) {
    global $HeadlessVidX_ServerPort;
	
	$key = $streamId . '_thetvapp_url';	
	$cachedUrl = readFromCache($key);
 
	try {
		
		if ($cachedUrl !== null && $cachedUrl !== '_running_') {
			header("HTTP/1.1 302 Moved Temporarily");
			header("Location: $cachedUrl");
			exit();
		}
		
		$output = @file_get_contents('http://' . $HeadlessVidX_ServerPort . '/thetvapp?url=' . urlencode($url));

		if ($output === FALSE) {
			throw new Exception("Failed to retrieve content.");
		}

		$jsonOutput = json_decode($output, true);

		if ($jsonOutput && isset($jsonOutput['status']) && $jsonOutput['status'] === 'ok' && isset($jsonOutput['url']) && $jsonOutput['url'] !== false) {
			
			writeToCache($key, $jsonOutput['url'], '7200', false);	
			return $jsonOutput['url'];
		} else {
			throw new Exception("Invalid JSON response or missing 'url' field.");
		}
	} catch (Exception $e) {
		http_response_code(500);
		echo "Couldn't get the stream url.";
		exit;
	}

    http_response_code(500);
    echo "Couldn't get the stream url.";
    exit;
}
	
function decryptString($key, $encString) {
    $l = base64_decode($encString);
    if ($l === false) {
        return false;
    }

    $o = '';
    for ($c = 0; $c < strlen($l); $c++) {
        $o .= chr(ord($l[$c]) ^ ord($key[$c % strlen($key)]));
    }
    return $o;
}

function findKey($data, $encString) {
            // Keys have been as low as 1 character in length.
            if (preg_match_all('/(?<=")[A-Z0-9a-z]{1,200}(?=")/', $data, $keyMatches)) {
                foreach ($keyMatches[0] as $i) {
                    $decodedEncString = base64_decode($encString, true);
                    if ($decodedEncString === false) {
                        
                        return false;
                    }
                    $o = '';
                    for ($c = 0; $c < strlen($decodedEncString); $c++) {
                        $o .= chr(ord($decodedEncString[$c]) ^ ord($i[$c % strlen($i)]));
                    }
                    // Check if the decoded string contains the specific substring
                    if (strpos($o, 'thetvapp.to') !== false) {
                       
                        return $i;
                    }
                }
            }
    
    return false;
}

function writeToCache($key, $value, $expires = null, $report=true)
{
    global $expirationDuration;	

	if ($expires === null) {
        $expires = $expirationDuration;
    }   
    $cacheFilePath = 'cache.json';
    if (!file_exists($cacheFilePath)) {
        file_put_contents($cacheFilePath, '{}');
    }
    $now = time();
    $expirationTime = $now + $expires;
    $serializedValue = json_encode($value);
    $cacheData = json_decode(file_get_contents($cacheFilePath), true) ? : [];
    $cacheData[$key] = ['value' => $serializedValue, 'expirationTime' => $expirationTime, ];
    file_put_contents($cacheFilePath, json_encode($cacheData));

    if ($GLOBALS['DEBUG'] && $report == true) {
        echo 'Added to Cache - Key: ' . $key . ' Value: ' . json_encode($value) .
            "</br></br>";
    }
	
}

function readFromCache($key, $report=true){
    $cacheFilePath = 'cache.json';
    if (!file_exists($cacheFilePath)) {
        file_put_contents($cacheFilePath, '{}');
    }

    $cacheData = json_decode(file_get_contents($cacheFilePath), true) ? : [];

    if (isset($cacheData[$key])) {
        $parsedData = $cacheData[$key];
        $now = time();
        if ($now <= $parsedData['expirationTime']) {
            $deserializedValue = json_decode($parsedData['value'], true);

            if ($GLOBALS['DEBUG'] && $report == true && $deserializedValue !== '_running_') {
                echo 'Read from Cache - Key: ' . $key . ' - Value: ' . json_encode($deserializedValue) .
                    "</br></br>";
            }

            return $deserializedValue;
        } else {
            unset($cacheData[$key]);

            file_put_contents($cacheFilePath, json_encode($cacheData));
        }
    }
    return null;
}

	function generateUuidV4() {
		$data = random_bytes(16);
		
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);		
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
		
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	function generateShortHexSid($length = 12) {
		return bin2hex(random_bytes($length / 2));
	}


getLiveStream($_GET['streamId']);


?>