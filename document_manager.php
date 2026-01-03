<?php
/**
 * Document Manager API
 * 
 * Manages documents in Google Cloud Storage
 * Endpoints: upload, list, download, delete, search
 */

require 'vendor/autoload.php';
require 'CloudManager.php';

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Initialize CloudManager
try {
    $cloudManager = new CloudManager();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'CloudManager initialization failed: ' . $e->getMessage()]);
    exit;
}

// Get action from query parameter
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'upload':
        handleUpload($cloudManager);
        break;
    
    case 'list':
        handleList($cloudManager);
        break;
    
    case 'download':
        handleDownload($cloudManager);
        break;
    
    case 'delete':
        handleDelete($cloudManager);
        break;
    
    case 'search':
        handleSearch($cloudManager);
        break;
    
    case 'metadata':
        handleMetadata($cloudManager);
        break;
    
    default:
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid action',
            'available_actions' => ['upload', 'list', 'download', 'delete', 'search', 'metadata']
        ]);
}

/**
 * Upload a document
 */
function handleUpload($cloudManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        return;
    }
    
    // Check if file was uploaded
    if (isset($_FILES['file'])) {
        $file = $_FILES['file'];
        $content = file_get_contents($file['tmp_name']);
        $filename = 'documents/' . basename($file['name']);
        
        $metadata = [
            'contentType' => $file['type'],
            'originalName' => $file['name'],
            'uploadedAt' => date('c')
        ];
    } 
    // Or check for JSON body
    else {
        $inputJSON = file_get_contents('php://input');
        $input = json_decode($inputJSON, true);
        
        if (!isset($input['content']) || !isset($input['filename'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing content or filename']);
            return;
        }
        
        $content = $input['content'];
        $filename = 'documents/' . $input['filename'];
        $metadata = $input['metadata'] ?? [];
        $metadata['uploadedAt'] = date('c');
    }
    
    $result = $cloudManager->uploadFile($content, $filename, $metadata);
    echo json_encode($result);
}

/**
 * List all documents
 */
function handleList($cloudManager) {
    $folder = $_GET['folder'] ?? 'documents/';
    $result = $cloudManager->listFiles($folder);
    echo json_encode($result);
}

/**
 * Download a specific document
 */
function handleDownload($cloudManager) {
    $filename = $_GET['filename'] ?? '';
    
    if (empty($filename)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing filename parameter']);
        return;
    }
    
    $result = $cloudManager->downloadFile($filename);
    echo json_encode($result);
}

/**
 * Delete a document
 */
function handleDelete($cloudManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        return;
    }
    
    $filename = $_GET['filename'] ?? '';
    
    if (empty($filename)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing filename parameter']);
        return;
    }
    
    $result = $cloudManager->deleteFile($filename);
    echo json_encode($result);
}

/**
 * Search documents by pattern
 */
function handleSearch($cloudManager) {
    $pattern = $_GET['pattern'] ?? '';
    $folder = $_GET['folder'] ?? 'documents/';
    
    if (empty($pattern)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing pattern parameter']);
        return;
    }
    
    $result = $cloudManager->searchFiles($pattern, $folder);
    echo json_encode($result);
}

/**
 * Get file metadata
 */
function handleMetadata($cloudManager) {
    $filename = $_GET['filename'] ?? '';
    
    if (empty($filename)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing filename parameter']);
        return;
    }
    
    $result = $cloudManager->getFileMetadata($filename);
    echo json_encode($result);
}
