<?php
/**
 * CloudManager Usage Example
 * 
 * This file demonstrates how to use the CloudManager class
 * to upload Gemini API responses to Google Cloud Storage.
 */

require 'vendor/autoload.php';
require 'CloudManager.php';

// Initialize CloudManager
try {
    $cloudManager = new CloudManager();
    echo "✓ CloudManager initialized successfully\n";
    echo "Bucket: " . getenv('CLOUD_STORAGE_BUCKET') . "\n\n";
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

// Example 1: Upload a Gemini API response
echo "=== Example 1: Upload Gemini Response ===\n";
$geminiResponse = "This is a sample response from the Gemini API.\nIt can contain multiple lines of text.\nThe CloudManager will save it as a .txt file.";
$result = $cloudManager->uploadGeminiResponse($geminiResponse, 'test_response');
print_r($result);
echo "\n";

// Example 2: Upload custom content
echo "=== Example 2: Upload Custom File ===\n";
$customContent = "Custom file content for testing";
$result = $cloudManager->uploadFile($customContent, 'custom/test_file.txt', [
    'contentType' => 'text/plain',
    'customMetadata' => 'test'
]);
print_r($result);
echo "\n";

// Example 3: List files in gemini_logs folder
echo "=== Example 3: List Files ===\n";
$result = $cloudManager->listFiles('gemini_logs/');
if ($result['status'] === 'success') {
    echo "Files in gemini_logs/:\n";
    foreach ($result['files'] as $file) {
        echo "  - " . $file['name'] . " (" . $file['size'] . " bytes)\n";
    }
} else {
    echo "Error: " . $result['message'] . "\n";
}
echo "\n";

// Example 4: Upload Gemini response with custom folder
echo "=== Example 4: Custom Folder Upload ===\n";
$result = $cloudManager->uploadGeminiResponse(
    "API response for user queries", 
    'user_query_response',
    'gemini_responses/user_queries/'
);
print_r($result);
echo "\n";

echo "=== All tests completed ===\n";
