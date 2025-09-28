<?php
error_reporting(0);
set_time_limit(0);

require_once 'config.php';
$proxyUrl = locateBaseURL() . "hls_proxy.php";

if (empty($_GET['url']) && empty($_GET['url2'])) {
    http_response_code(400);
    echo "Missing url parameters";
    exit;
}

function fetchContent($url, $additionalHeaders = [], $isMaster = false, $headersOnly = false) {
    $decodedData = base64_decode($_GET['data'] ?? '');
    $parts = explode('|', $decodedData);
    $maxRedirects = 2;
    $headers = [];
    $origin = '';

    if (!$isMaster) {
        $parsedUrl = parse_url($url);
        $origin = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    }

    foreach ($parts as $headerData) {
        if (strpos($headerData, '=') !== false) {
            list($header, $value) = explode('=', $headerData, 2);
            $header = trim($header);
            $value = trim($value, "'\"");
            if (!$isMaster && ($header === 'Origin')) continue;
            $headers[] = $header . ": " . $value;
        }
    }

    if (!$isMaster) {
        $headers[] = 'Origin: ' . $origin;
    }

    if (isset($_SERVER['HTTP_RANGE'])) {
        $headers[] = "Range: " . $_SERVER['HTTP_RANGE'];
        $headers[] = "Accept-Encoding: identity";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, $maxRedirects);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    if ($headersOnly) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

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
        if (in_array($statusCode, [301,302,303,307,308])) {
            $redirectCount++;
            $finalUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        } else {
            break;
        }
    } while ($redirectCount < $maxRedirects);

    curl_close($ch);

    return [
        'content'     => $headersOnly ? '' : $response,
        'finalUrl'    => $finalUrl,
        'statusCode'  => $statusCode,
        'contentType' => $contentType
    ];
}

function isMasterRequest($queryParams) {
    return isset($queryParams['url']) && !isset($queryParams['url2']);
}

function absolutify(string $path, string $baseUrl): string {
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:(?=//)|//)#i', $path)) {
        return $path;
    }
    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        throw new RuntimeException("Invalid base URL: $baseUrl");
    }
    $origin = $base['scheme'] . '://' . $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '');
    $basePath = $base['path'] ?? '/';
    $lastSeg  = basename($basePath);
    $isDirStyle = (strpos($lastSeg, '.') === false);
    $baseDir = $isDirStyle
        ? rtrim($basePath, '/') . '/'
        : preg_replace('#/[^/]*$#', '/', $basePath);
    $target = ($path[0] === '/') ? $path : $baseDir . $path;
    $parts = [];
    foreach (explode('/', $target) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    $resolvedPath = '/' . implode('/', $parts);
    $absUrl = $origin . $resolvedPath;
    $inherit = empty(parse_url($absUrl, PHP_URL_QUERY))
            && !empty($base['query']);
    if ($inherit) {
        $absUrl .= '?' . $base['query'];
        if (!empty($base['fragment'])) $absUrl .= '#' . $base['fragment'];
    }
    return $absUrl;
}

/* -----------------------------------------------------------
   rewriteUrls  –  unchanged except for using absolutify()
   ----------------------------------------------------------- */
function rewriteUrls(string $content, string $baseUrl, string $proxyUrl, string $data): string
{
    $lines   = preg_split('/\r?\n/', $content);
    $out     = [];
    $nextVar = false;   // next plain line after #EXT-X-STREAM-INF

    foreach ($lines as $raw) {
        $line = rtrim($raw, "\r\n");

        if ($line === '' || $line[0] === '#') {
            if (preg_match('/URI="([^"]+)"/i', $line, $m)) {
                $uri = absolutify($m[1], $baseUrl);                // now keeps /kpice
                if (strpos($uri, $proxyUrl) !== 0) {
                    $prox = $proxyUrl . '?url=' . rawurlencode($uri)
                          . '&data=' . rawurlencode($data);
                    if (stripos($line, '#EXT-X-KEY') !== false) {
                        $prox .= '&key=true';
                    }
                    $line = str_replace($m[1], $prox, $line);
                }
            }
            $out[]   = $line;
            $nextVar = stripos($line, '#EXT-X-STREAM-INF') !== false;
            continue;
        }

        /* segment or sub-playlist */
        $abs = absolutify($line, $baseUrl);
        if (strpos($abs, $proxyUrl) !== 0) {
            $param = $nextVar ? 'url' : 'url2';
            $out[] = $proxyUrl . "?$param=" . rawurlencode($abs)
                    . '&data=' . rawurlencode($data);
        } else {
            $out[] = $abs;
        }
        $nextVar = false;
    }
    return implode("\n", $out);
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

// ───────────────────────────────────────────────
// Direct file streaming helper
// ───────────────────────────────────────────────
function streamRegularFile($finalUrl, $data) {
    $decodedData = base64_decode($data);
    $parts = explode('|', $decodedData);
    $headers = [];
    foreach ($parts as $headerData) {
        if (strpos($headerData, '=') !== false) {
            list($header, $value) = explode('=', $headerData, 2);
            $headers[] = trim($header) . ": " . trim($value, "'\"");
        }
    }
    if (isset($_SERVER['HTTP_RANGE'])) {
        $headers[] = "Range: " . $_SERVER['HTTP_RANGE'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headers,
            'ignore_errors' => true,
            'follow_location' => 1
        ]
    ]);

    $head = get_headers($finalUrl, 1, $context);
    if ($head !== false) {
        if (isset($head['Content-Type'])) {
            $ct = is_array($head['Content-Type']) ? end($head['Content-Type']) : $head['Content-Type'];
            header('Content-Type: ' . $ct);
        } else {
            header('Content-Type: video/mp4');
        }
        if (isset($head['Content-Length'])) {
            $len = is_array($head['Content-Length']) ? end($head['Content-Length']) : $head['Content-Length'];
            header('Content-Length: ' . $len);
        }
        if (isset($head['Accept-Ranges'])) {
            header('Accept-Ranges: ' . (is_array($head['Accept-Ranges']) ? end($head['Accept-Ranges']) : $head['Accept-Ranges']));
        }
        if (isset($head['Content-Range'])) {
            header('Content-Range: ' . (is_array($head['Content-Range']) ? end($head['Content-Range']) : $head['Content-Range']));
        }
        http_response_code(isset($_SERVER['HTTP_RANGE']) ? 206 : 200);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

    $fp = fopen($finalUrl, 'rb', false, $context);
    if ($fp === false) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Failed to open video URL.";
        exit;
    }
    while (!feof($fp)) {
        echo fread($fp, 262144); // 256KB chunks
        flush();
    }
    fclose($fp);
}

// ───────────────────────────────────────────────
// Main
// ───────────────────────────────────────────────
handleProxiedUrl();

$isMaster   = isMasterRequest($_GET);
$data       = $_GET['data'] ?? '';
$requestUrl = $isMaster ? ($_GET['url'] ?? '') : ($_GET['url2'] ?? '');

// 1) HEAD probe
$headResult = fetchContent($requestUrl, $data, $isMaster, true);
$finalUrl    = $headResult['finalUrl'];
$contentType = $headResult['contentType'];
$statusCode  = $headResult['statusCode'];

$ext = strtolower(pathinfo(parse_url($finalUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
$directExtensions = ['mp4','mkv','mov','avi','webm','flv','ogg','wmv'];

$looksLikeVideo =
    (stripos($contentType, 'video/') === 0 &&
     stripos($contentType, 'video/mp2t') === false &&
     stripos($contentType, 'video/x-mpegurl') === false)
    ||
    ($contentType === 'application/octet-stream' && in_array($ext, $directExtensions, true));

if ($looksLikeVideo) {
    streamRegularFile($finalUrl, $data);
    exit;
}

// 2) Otherwise fetch full body (playlist etc.)
$result = fetchContent($requestUrl, $data, $isMaster, false);
if ($result['statusCode'] >= 400) {
    http_response_code($result['statusCode']);
    echo "Error " . $result['statusCode'];
    exit;
}

$content     = $result['content'];
$finalUrl    = $result['finalUrl'];
$baseUrl     = dirname($finalUrl);
$contentType = $result['contentType'];
$statusCode  = $result['statusCode'];

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