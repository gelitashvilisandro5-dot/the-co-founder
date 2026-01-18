<?php
require 'vendor/autoload.php';
// HARDCODED KEY AS REQUESTED (Replace with your actual key in env)
$API_KEY = 'YOUR_GEMINI_API_KEY'; 
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=$API_KEY";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n";
