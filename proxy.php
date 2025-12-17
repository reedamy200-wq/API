<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Log the request
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'ip' => $_SERVER['REMOTE_ADDR']
];

file_put_contents('proxy_log.txt', json_encode($logData) . PHP_EOL, FILE_APPEND);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

// Validate required fields
$required = ['server', 'tc', 'emote_id', 'uids'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing field: $field"]);
        exit;
    }
}

try {
    $server = rtrim($data['server'], '/');
    $tc = urlencode(trim($data['tc']));
    $emote_id = urlencode(trim($data['emote_id']));
    $uids = $data['uids'];
    
    // Build URL with correct order
    $url = "{$server}/join?tc={$tc}&emote_id={$emote_id}";
    
    // Add UIDs
    foreach ($uids as $index => $uid) {
        if (!empty($uid)) {
            $url .= "&uid" . ($index + 1) . "=" . urlencode(trim($uid));
        }
    }
    
    // Log the URL
    file_put_contents('proxy_log.txt', "URL: $url" . PHP_EOL, FILE_APPEND);
    
    // Make request using cURL with better options
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'DROX-Bot/2.0',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Connection: close'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Log result
    $logResult = [
        'url' => $url,
        'http_code' => $httpCode,
        'error' => $error,
        'response_length' => strlen($response)
    ];
    
    file_put_contents('proxy_log.txt', "RESULT: " . json_encode($logResult) . PHP_EOL, FILE_APPEND);
    
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'cURL error: ' . $error,
            'url_used' => $url
        ]);
    } elseif ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            'success' => true,
            'message' => 'Emote sent successfully via proxy',
            'status_code' => $httpCode,
            'url' => $url,
            'response_preview' => substr($response, 0, 200)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Server returned error code: ' . $httpCode,
            'url' => $url,
            'response_preview' => substr($response, 0, 200)
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>