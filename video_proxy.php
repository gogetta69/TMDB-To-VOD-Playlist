<?php
error_reporting(0);
set_time_limit(0);
ob_end_clean();

if (isset($_GET['data']) && !empty($_GET['data'])) {
    $decodedData = base64_decode($_GET['data']);
    $parts = explode('|', $decodedData);
    $url = array_shift($parts);
	   
    $httpOptions = [
        'http' => [
            'method' => 'GET',
            'header' => []
        ]
    ];

    foreach ($parts as $headerData) {
        list($header, $value) = explode('=', $headerData);
        $httpOptions['http']['header'][] = "$header: $value";
    }

    if (isset($_SERVER['HTTP_RANGE'])) {
        $httpOptions['http']['header'][] = "Range: " . $_SERVER['HTTP_RANGE'];
    }

    $context = stream_context_create($httpOptions);

    // Follow redirects manually
    $maxRedirects = 10;
    $statusCode = null;
    $contentType = null;
    for ($i = 0; $i < $maxRedirects; $i++) {
        $headers = get_headers($url, 1, $context);
        if ($headers === false) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Failed to fetch headers.";
            exit;
        }

        $statusLine = $headers[0];
        preg_match('{HTTP/\S+ (\d{3})}', $statusLine, $match);
        $statusCode = $match[1];

        if (isset($headers['Content-Type'])) {
            $contentType = is_array($headers['Content-Type']) ? end($headers['Content-Type']) : $headers['Content-Type'];
        }

        if (in_array($statusCode, ['301', '302'])) {
            $url = $headers['Location'];
            if (is_array($url)) {
                $url = end($url);
            }
        } else {
            break;
        }
    }

    if (!in_array($statusCode, ['200', '206'])) {
        http_response_code($statusCode);
        header('Content-Type: ' . ($contentType ?: 'text/plain'));
        echo "Failed with status code: $statusCode";
        exit;
    }

    $headers = get_headers($url, 1, $context);

    header($headers[0]);
    if (isset($headers['Content-Type'])) {
        header('Content-Type: ' . $headers['Content-Type']);
    }
    if (isset($headers['Content-Length'])) {
        header('Content-Length: ' . $headers['Content-Length']);
    }
    if (isset($headers['Accept-Ranges'])) {
        header('Accept-Ranges: ' . $headers['Accept-Ranges']);
    }
    if (isset($headers['Content-Range'])) {
        header('Content-Range: ' . $headers['Content-Range']);
    }

    if ($_SERVER['REQUEST_METHOD'] == 'HEAD') {
        exit;
    }

    $fp = fopen($url, 'rb', false, $context);
    if ($fp === false) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Failed to open URL.";
        exit;
    }
    
    while (!feof($fp)) {
        echo fread($fp, 1024 * 256);
        flush();
    }
    fclose($fp);
} else {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "Missing the data parameter.";
}

?>
