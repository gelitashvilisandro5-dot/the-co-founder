<?php
require 'vendor/autoload.php';
$API_KEY = getenv('GEMINI_API_KEY');
if (!$API_KEY && file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, null);
        if (trim((string) $k) === 'GEMINI_API_KEY') {
            $API_KEY = trim((string) $v);
            break;
        }
    }
}

if (!$API_KEY) {
    http_response_code(500);
    echo "Missing GEMINI_API_KEY\n";
    exit(1);
}

$url = "https://generativelanguage.googleapis.com/v1beta/models?key=$API_KEY";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n";
