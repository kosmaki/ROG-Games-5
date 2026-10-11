<?php
/**
 * Small local proxy for talking to the Geometry Dash servers.
 *
 * Usage:
 *   php -S 0.0.0.0:5678 gdproxy.php
 *
 * Then hit it like:
 *   http://192.168.1.50:5678/proxy?url=https://www.boomlings.com/database/accounts/login.php
 *
 * The target URL may be any full URL. CORS headers are added to every
 * response so a remotely hosted game can call this proxy from a browser.
 */

const HOST = '0.0.0.0';
const PORT = 5678;

// Headers that should not be blindly forwarded between HTTP connections.
// content-encoding is included so an upstream compression header cannot make
// the browser try to decode an already-decoded proxy response.
$HOP_BY_HOP = [
    'host',
    'content-length',
    'connection',
    'accept-encoding',
    'content-encoding',
    'transfer-encoding',
];

function corsHeaders(): array {
    return [
        'Access-Control-Allow-Origin: *',
        'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS',
        'Access-Control-Allow-Headers: *',
        'Access-Control-Max-Age: 86400',
    ];
}

function isHopByHop(string $name): bool {
    global $HOP_BY_HOP;
    return in_array(strtolower($name), $HOP_BY_HOP, true);
}

function sendResponse(int $status, string $body, array $headers = []): never {
    http_response_code($status);

    foreach (corsHeaders() as $header) {
        header($header);
    }

    foreach ($headers as $header) {
        header($header);
    }

    echo $body;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    sendResponse(204, '');
}

$targetUrl = $_GET['url'] ?? '';
if ($targetUrl === '') {
    sendResponse(400, "Missing 'url' query parameter");
}

if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    sendResponse(400, 'Invalid target URL');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = file_get_contents('php://input');

// Forward incoming headers except connection-specific/compression headers.
// Most importantly, explicitly tell Boomlings not to request gzip.
$forwardHeaders = [];
$incomingHeaders = function_exists('getallheaders') ? getallheaders() : [];

foreach ($incomingHeaders as $name => $value) {
    if (isHopByHop($name)) {
        continue;
    }
    $forwardHeaders[] = $name . ': ' . $value;
}

// Geometry Dash's GD servers work better here with an empty User-Agent.
$forwardHeaders[] = 'User-Agent:';

// Force the proxy -> Boomlings request (and all other upstream requests) to
// request an uncompressed response. This prevents gzip/content-encoding from
// being passed through to the browser incorrectly.
$forwardHeaders[] = 'Accept-Encoding: identity';

error_log(sprintf(
    '[proxy] %s %s content-type=%s body=%d bytes',
    $method,
    $targetUrl,
    $incomingHeaders['Content-Type'] ?? '<none>',
    strlen($body)
));

$ch = curl_init($targetUrl);
if ($ch === false) {
    sendResponse(500, 'Failed to initialize cURL');
}

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $forwardHeaders,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_USERAGENT => '',
    // Do not ask cURL itself to negotiate gzip/deflate/br.
    CURLOPT_ENCODING => 'identity',
]);

$response = curl_exec($ch);
if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    error_log('[proxy] error fetching ' . $targetUrl . ': ' . $error);
    sendResponse(502, 'Proxy error: ' . $error);
}

$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$rawHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);
curl_close($ch);

$responseHeaders = [];
foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) as $line) {
    if ($line === '' || str_starts_with($line, 'HTTP/')) {
        continue;
    }

    $colon = strpos($line, ':');
    if ($colon === false) {
        continue;
    }

    $name = trim(substr($line, 0, $colon));
    $value = trim(substr($line, $colon + 1));

    if (isHopByHop($name) || str_starts_with(strtolower($name), 'access-control-')) {
        continue;
    }

    $responseHeaders[] = $name . ': ' . $value;
}

error_log(sprintf(
    '[proxy] %d from %s: %s',
    $status,
    $targetUrl,
    substr($responseBody, 0, 100)
));

sendResponse($status, $responseBody, $responseHeaders);
