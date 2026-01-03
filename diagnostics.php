<?php
/**
 * Diagnostics Script
 * Checks environment and Cloud Storage access
 */

require 'vendor/autoload.php';
require 'CloudManager.php';

echo "=== DIAGNOSTICS CHECK ===\n\n";

// Check 1: Environment Variables
echo "1. ENVIRONMENT VARIABLES:\n";
echo "   CLOUD_STORAGE_BUCKET: " . (getenv('CLOUD_STORAGE_BUCKET') ?: 'NOT SET') . "\n";
echo "   GOOGLE_APPLICATION_CREDENTIALS: " . (getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: 'NOT SET') . "\n\n";

// Check 2: CloudManager Initialization
echo "2. CLOUDMANAGER INITIALIZATION:\n";
try {
    $cloudManager = new CloudManager();
    echo "   ✅ CloudManager initialized successfully\n\n";
} catch (Exception $e) {
    echo "   ❌ Failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Check 3: List First 3 Files
echo "3. LISTING FIRST 3 FILES FROM BUCKET:\n";
try {
    $result = $cloudManager->listFiles('');
    
    if ($result['status'] === 'success') {
        $files = array_slice($result['files'], 0, 3);
        
        if (empty($files)) {
            echo "   ⚠️  Bucket is empty (no files found)\n";
        } else {
            echo "   ✅ Successfully accessed bucket!\n";
            echo "   Found " . count($result['files']) . " total files\n";
            echo "   First 3 files:\n";
            foreach ($files as $i => $file) {
                echo "   " . ($i + 1) . ". " . $file['name'] . " (" . $file['size'] . " bytes)\n";
            }
        }
    } else {
        echo "   ❌ Failed to list files\n";
        echo "   Error: " . $result['message'] . "\n";
    }
} catch (Exception $e) {
    echo "   ❌ Exception: " . $e->getMessage() . "\n";
}

echo "\n=== DIAGNOSTICS COMPLETE ===\n";
