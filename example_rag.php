<?php
/**
 * RAG System Usage Examples
 * 
 * Demonstrates how to use the RAG system to query Gemini with document context
 */

require 'vendor/autoload.php';
require 'CloudManager.php';

echo "=== RAG System Example ===\n\n";

// Initialize CloudManager
try {
    $cloudManager = new CloudManager();
    echo "✓ CloudManager initialized\n";
    echo "Bucket: " . getenv('CLOUD_STORAGE_BUCKET') . "\n\n";
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

// Example 1: Upload a sample document
echo "=== Step 1: Upload Sample Document ===\n";
$sampleDocument = <<<EOT
Product Roadmap 2025

Q1 2025:
- Launch AI-powered chatbot
- Integrate Cloud Storage for knowledge base
- Implement RAG system

Q2 2025:
- Add multi-language support
- Enhance UI with dark mode
- Mobile app development

Q3 2025:
- Enterprise features
- Advanced analytics dashboard
- API versioning

Q4 2025:
- Machine learning improvements
- Global expansion
- Year-end review
EOT;

$uploadResult = $cloudManager->uploadFile(
    $sampleDocument,
    'documents/product_roadmap.txt',
    ['contentType' => 'text/plain', 'description' => 'Company roadmap for 2025']
);
print_r($uploadResult);
echo "\n";

// Example 2: Search for documents
echo "=== Step 2: Search for Documents ===\n";
$searchResult = $cloudManager->searchFiles('roadmap', 'documents/');
if ($searchResult['status'] === 'success') {
    echo "Found {$searchResult['count']} matching documents:\n";
    foreach ($searchResult['files'] as $file) {
        echo "  - {$file['basename']} ({$file['size']} bytes)\n";
    }
} else {
    echo "Search failed: " . $searchResult['message'] . "\n";
}
echo "\n";

// Example 3: Download multiple documents for RAG
echo "=== Step 3: Download Multiple Documents ===\n";
$documentsToRetrieve = ['documents/product_roadmap.txt'];
$downloadResult = $cloudManager->downloadMultiple($documentsToRetrieve);
if ($downloadResult['status'] === 'success' || $downloadResult['status'] === 'partial') {
    echo "Downloaded {$downloadResult['downloaded']} documents\n";
    if ($downloadResult['failed'] > 0) {
        echo "Failed: {$downloadResult['failed']}\n";
        print_r($downloadResult['errors']);
    }
} else {
    echo "Download failed: " . $downloadResult['message'] . "\n";
}
echo "\n";

// Example 4: Simulate RAG Query
echo "=== Step 4: RAG Query Simulation ===\n";
echo "User Question: 'What are our plans for Q2 2025?'\n\n";

// In a real scenario, this would be sent to rag_proxy.php
// Here we simulate the process:

if (isset($downloadResult['files']['documents/product_roadmap.txt'])) {
    $documentContent = $downloadResult['files']['documents/product_roadmap.txt'];
    
    echo "Context Retrieved:\n";
    echo "--- Document: product_roadmap.txt ---\n";
    echo substr($documentContent, 0, 200) . "...\n";
    echo "--- End of Document ---\n\n";
    
    echo "Enhanced Prompt sent to Gemini would be:\n";
    echo "\"Based on this document:\n";
    echo "[document content]\n\n";
    echo "User Question: What are our plans for Q2 2025?\n";
    echo "Please answer based on the provided document.\"\n\n";
}

// Example 5: Upload another document
echo "=== Step 5: Upload Technical Documentation ===\n";
$techDoc = <<<EOT
RAG System Technical Documentation

The Retrieval-Augmented Generation (RAG) system combines:

1. Cloud Storage: Documents stored in Google Cloud Storage
2. CloudManager: PHP class for storage operations
3. RAG Proxy: Endpoint that retrieves documents and queries Gemini
4. Document Manager: API for managing documents

To use RAG mode:
- Set useRAG: true in the request
- Optionally specify documentNames or searchPattern
- The system retrieves documents and provides context to Gemini
EOT;

$techUpload = $cloudManager->uploadFile(
    $techDoc,
    'documents/rag_technical_docs.txt',
    ['contentType' => 'text/plain', 'category' => 'technical']
);
print_r($techUpload);
echo "\n";

// Example 6: List all documents
echo "=== Step 6: List All Documents in Bucket ===\n";
$listResult = $cloudManager->listFiles('documents/');
if ($listResult['status'] === 'success') {
    echo "Total documents: " . count($listResult['files']) . "\n";
    foreach ($listResult['files'] as $file) {
        echo "  📄 " . $file['name'] . " (" . $file['size'] . " bytes)\n";
    }
} else {
    echo "List failed: " . $listResult['message'] . "\n";
}
echo "\n";

// Example 7: Get file metadata
echo "=== Step 7: Get File Metadata ===\n";
$metadataResult = $cloudManager->getFileMetadata('documents/product_roadmap.txt');
if ($metadataResult['status'] === 'success') {
    echo "Metadata for product_roadmap.txt:\n";
    print_r($metadataResult['metadata']);
} else {
    echo "Metadata retrieval failed: " . $metadataResult['message'] . "\n";
}
echo "\n";

echo "=== RAG System Example Complete ===\n";
echo "\nNext Steps:\n";
echo "1. Grant IAM permissions (see SETUP_PERMISSIONS.md)\n";
echo "2. Test rag_proxy.php endpoint from your frontend\n";
echo "3. Upload your own documents via document_manager.php\n";
echo "4. Query Gemini with context from your knowledge base\n";
