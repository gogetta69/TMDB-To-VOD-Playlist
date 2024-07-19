<?php
error_reporting(0);
set_time_limit(0);

require_once 'config.php';
$proxyUrl = locateBaseURL() . "hls_proxy.php";

// Function to fetch content from a URL with optional additional headers
function fetchContent($url, $additionalHeaders = [], $isMaster) {
    $decodedData = base64_decode($_GET['data']);
    $parts = explode('|', $decodedData);
    $maxRedirects = 2;
    $headers = [];
    $referer = '';
    $origin = '';
	

    // Check if playlist is master and parse the domain and protocol
    if (!$isMaster) {
        $parsedUrl = parse_url($url);
        $origin = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];       
    }
	
    foreach ($parts as $headerData) {
        if (strpos($headerData, '=') !== false) {
            list($header, $value) = explode('=', $headerData, 2);
            $header = trim($header);
            $value = trim($value, "'\"");			
            
            if (!$isMaster && ($header === 'Origin')) {
                continue; 
            }

            $headers[] = $header . ": " . $value;
        }
    }

    // Add Origin if not the master playlist
    if (!$isMaster) {
        $headers[] = 'Origin: ' . $origin;
    }

    if (isset($_SERVER['HTTP_RANGE'])) {
        $headers[] = "Range: " . $_SERVER['HTTP_RANGE'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, $maxRedirects);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	
    $redirectCount = 0;
    $response = '';
    $finalUrl = $url;
    $contentType = '';
    $statusCode = 0;

    do {
        curl_setopt($ch, CURLOPT_URL, $finalUrl);
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if (in_array($statusCode, [301, 302, 303, 307, 308])) {
            $redirectCount++;
            $finalUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        } else {
            break;
        }
    } while ($redirectCount < $maxRedirects);

    curl_close($ch);
    
    return [
        'content' => $response,
        'finalUrl' => $finalUrl,
        'statusCode' => $statusCode,
        'contentType' => $contentType
    ];
}

function isMasterRequest($queryParams) {
    return isset($queryParams['url']) && !isset($queryParams['url2']);
}

function rewriteUrls($content, $baseUrl, $proxyUrl, $data) {
    $lines = explode("\n", $content);
    $rewrittenLines = [];
    $isNextLineUri = false;

    foreach ($lines as $line) {
        if (empty(trim($line)) || $line[0] === '#') {
            if (preg_match('/URI="([^"]+)"/i', $line, $matches)) {
                $uri = $matches[1];
                if (strpos($uri, 'hls_proxy.php') === false) {
                    $rewrittenUri = $proxyUrl . '?url=' . urlencode($uri) . '&data=' . urlencode($data);
                    if (strpos($line, '#EXT-X-KEY') !== false) {
                        $rewrittenUri .= '&key=true';
                    }
                    $line = str_replace($uri, $rewrittenUri, $line);
                }
            }
            $rewrittenLines[] = $line;

            if (strpos($line, '#EXT-X-STREAM-INF') !== false) {
                $isNextLineUri = true;
            }
            continue;
        }

        $urlParam = $isNextLineUri ? 'url' : 'url2';

        if (!filter_var($line, FILTER_VALIDATE_URL)) {
            $line = rtrim($baseUrl, '/') . '/' . ltrim($line, '/');
        }

        if (strpos($line, 'hls_proxy.php') === false) {
            $fullUrl = $proxyUrl . "?$urlParam=" . urlencode($line) . '&data=' . urlencode($data) . (($urlParam === 'url') ? '&type=/index.m3u8' : '&type=/index.ts');
            $rewrittenLines[] = $fullUrl;
        } else {
            $rewrittenLines[] = $line;
        }

        $isNextLineUri = false;
    }
    return implode("\n", $rewrittenLines);
}

function fetchEncryptionKey($url, $data) {
    if (isset($_GET['key']) && $_GET['key'] === 'true') {
		
		// Handler for daddylive
		if (strpos($url, 'premium') !== false && strpos($url, 'number') !== false) {
			$url = str_replace('key2.', 'key.', $url);
		}
        $decodedData = base64_decode($data);
        $parts = explode('|', $decodedData);
        $maxRedirects = 5;
        $headers = [];

        foreach ($parts as $headerData) {
            if (strpos($headerData, '=') !== false) {
                list($header, $value) = explode('=', $headerData, 2);
                $headers[] = trim($header) . ": " . trim($value, "'\"");
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, $maxRedirects);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        curl_close($ch);

        $etag = '"' . md5($response) . '"';
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag) {
            header("HTTP/1.1 304 Not Modified");
            exit;
        }

        header('Content-Type: application/octet-stream');
        header('Cache-Control: max-age=3600');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
        header('ETag: ' . $etag);

        echo $response;
        exit;
    }
}

// Check if the URL is already proxied and just redirect
function handleProxiedUrl() {
    if (isset($_GET['url'])) {
        $decodedUrl = urldecode($_GET['url']);        
       
        if (strpos($decodedUrl, '=https://') !== false || strpos($decodedUrl, '=http://') !== false) {
            $data = $_GET['data'] ?? '';
            if (!empty($data)) {
                $decodedData = base64_decode($data);
                $headers = explode('|', $decodedData);

                foreach ($headers as $header) {
                    if (!empty($header)) {
                        list($headerName, $headerValue) = explode('=', $header, 2);
                        if (!empty($headerName) && !empty($headerValue)) {
                            header("$headerName: $headerValue");
                        }
                    }
                }
            }

            header("Location: $decodedUrl");
            exit;
        }
    }
}

handleProxiedUrl();
// Main processing logic
$isMaster = isMasterRequest($_GET);
$data = $_GET['data'] ?? '';
$requestUrl = $isMaster ? ($_GET['url'] ?? '') : ($_GET['url2'] ?? '');
fetchEncryptionKey($requestUrl, $_GET['data']);
$result = fetchContent($requestUrl, $data, $isMaster);

if ($result['content'] === ''){
	http_response_code('404');
	echo '404 / Not Found!';
	echo $result['content'];
	exit;
}	

if ($result['statusCode'] >= 400) {
    http_response_code($result['statusCode']);
    switch ($result['statusCode']) {
        case 400:
            echo 'Bad Request!';
            break;
        case 401:
            echo 'Unauthorized!';
            break;
        case 403:
            echo 'Forbidden!';
            break;
        case 404:
            echo 'Not Found!';
            break;
        default:
            echo 'An error occurred! Status code: ' . $result['statusCode'];
            break;
    }
    exit;
}

$content = $result['content'];
$finalUrl = $result['finalUrl'];
$baseUrl = dirname($finalUrl);

$statusCode = $result['statusCode'];
$contentType = $result['contentType'];

if ($isMaster) {
    $content = rewriteUrls($content, $baseUrl, $proxyUrl, $data);
} 

if (isset($contentType) && (stripos($contentType, 'mpeg') !== false || stripos($contentType, 'video') !== false)) {
    header('Content-Type: ' . $contentType);
} else {   
    header('Content-Type: application/x-mpegURL');
}

http_response_code($statusCode);
echo $content;
?>
