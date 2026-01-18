<?php
/**
 * reindex_files.php - Repair and Translate specific PDFs
 * 
 * Usage: php reindex_files.php
 * 
 * This script:
 * 1. Takes a list of problematic files with config
 * 2. Deletes their existing (corrupt) chunks from SQLite
 * 3. Downloads them from Cloud Storage
 * 4. Uses Gemini OCR (Vision) to extract clean text OR Translate it
 * 5. Re-indexes them with proper embeddings
 */

require 'vendor/autoload.php';

ini_set('memory_limit', '2048M');
set_time_limit(0);

use Google\Cloud\Storage\StorageClient;
use Gemini\Client;
use setasign\Fpdi\Fpdi;

// --- CONFIGURATION ---
// Load .env manually to ensure API key is present
if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

$api_key = getenv('GEMINI_API_KEY');
if (!$api_key) {
    die("❌ Error: GEMINI_API_KEY not found in environment or .env file.\n");
}

$bucket_name = 'YOUR_BUCKET_NAME';
$db_file = __DIR__ . '/db/database.sqlite';

// LIST OF FILES TO REPAIR / TRANSLATE
$targetConfigs = [
    'vadim-zeland-reality-transurfing-pdf-free.pdf' => [
        'translate' => false
    ],
    'სიმბრძნე_სიმდიდრისა_V1_1.pdf' => [
        'translate' => true,
        'target_lang' => 'English'
    ]
];

// --- INITIALIZATION ---
$keyFile = __DIR__ . '/google-key.json';
if (!file_exists($keyFile)) {
    die("❌ Error: 'google-key.json' not found at $keyFile\n");
}

// DEBUG: Check key file
$keyData = json_decode(file_get_contents($keyFile), true);
if (!$keyData) {
    die("❌ Error: 'google-key.json' is not valid JSON.\n");
}
echo "🔑 Using Service Account: " . ($keyData['client_email'] ?? 'UNKNOWN') . "\n";

// FORCE ENV VAR
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $keyFile);

try {
    // Now use default constructor which picks up the env var
    $storage = new StorageClient();
    $bucket = $storage->bucket($bucket_name);
    $gemini = Gemini::client($api_key);
    echo "✅ Clients initialized.\n";
} catch (Exception $e) {
    die("❌ Client Init Error: " . $e->getMessage() . "\n");
}

// --- DATABASE CONNECTION ---
try {
    if (!file_exists(dirname($db_file))) {
        mkdir(dirname($db_file), 0777, true);
    }
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Connected to SQLite database.\n";
} catch (PDOException $e) {
    die("❌ DB Connection failed: " . $e->getMessage() . "\n");
}

// ==================================================
// MAIN PROCESS LOOP
// ==================================================

foreach ($targetConfigs as $targetFile => $config) {
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "🛠️  PROCESSING: {$targetFile}\n";
    if ($config['translate']) {
        echo "   (Translation Mode: " . ($config['target_lang'] ?? 'English') . ")\n";
    }
    echo str_repeat("=", 40) . "\n";

    try {
        // ------------------------------------------
        // 1. DELETE EXISTING CHUNKS
        // ------------------------------------------
        echo "🗑️  Cleaning old data...\n";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE file_name = ?");
        $stmt->execute([$targetFile]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $del = $pdo->prepare("DELETE FROM knowledge_chunks WHERE file_name = ?");
            $del->execute([$targetFile]);
            echo "   ✅ Deleted {$count} corrupt chunks.\n";
        } else {
            echo "   ℹ️  No existing chunks found.\n";
        }

        // ------------------------------------------
        // 2. DOWNLOAD FILE
        // ------------------------------------------
        echo "📥 Downloading file...\n";
        $object = $bucket->object($targetFile);
        if (!$object->exists()) {
            echo "   ❌ File not found in bucket! Skipping.\n";
            continue;
        }
        
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_fix_');
        $object->downloadToFile($tempFile);
        echo "   ✅ Downloaded.\n";

        // ------------------------------------------
        // 3. OCR / TRANSLATE
        // ------------------------------------------
        echo "🔍 Running Gemini " . ($config['translate'] ? "Translation" : "OCR") . "...\n";
        $extractedText = "";
        
        $fpdi = new Fpdi();
        $pageCount = $fpdi->setSourceFile($tempFile);
        echo "   📄 Querying {$pageCount} pages...\n";
        
        $step = 10; // Batch size
        for ($start = 1; $start <= $pageCount; $start += $step) {
            $end = min($start + $step - 1, $pageCount);
            
            // Extract pages to temp PDF
            $newPdf = new Fpdi();
            $newPdf->setSourceFile($tempFile);
            for ($i = $start; $i <= $end; $i++) {
                $tpl = $newPdf->importPage($i);
                $newPdf->addPage();
                $newPdf->useTemplate($tpl);
            }
            $batchFile = tempnam(sys_get_temp_dir(), 'batch_');
            $newPdf->Output('F', $batchFile);
            
            // Helper to call Gemini
            $batchText = attemptGeminiOCR($gemini, $batchFile, $start, $end, $config);
            $extractedText .= $batchText . "\n";
            
            if (file_exists($batchFile)) unlink($batchFile);
            
            // Progress indicator
            echo "   ✨ Processed pages {$start}-{$end}\n";
            // Rate limit guard
            sleep(2);
        }

        if (file_exists($tempFile)) unlink($tempFile);

        // Sanitize
        $extractedText = mb_convert_encoding($extractedText, 'UTF-8', 'UTF-8');
        $cleanText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $extractedText);
        
        if (mb_strlen($cleanText) < 100) {
            echo "   ⚠️ Warning: Extracted text is very short/empty. Gemini might have blocked/failed.\n";
            continue;
        }

        echo "   ✅ Extraction Complete (" . mb_strlen($cleanText) . " chars)\n";

        // ------------------------------------------
        // 4. CHUNK & INDEX
        // ------------------------------------------
        echo "📦 Indexing...\n";
        
        $chunkSize = 2000;
        $overlap = 200;
        $chunks = [];
        $startPos = 0;
        $len = mb_strlen($cleanText);
        
        while ($startPos < $len) {
            $chunks[] = mb_substr($cleanText, $startPos, $chunkSize);
            $startPos += ($chunkSize - $overlap);
        }
        
        $total = count($chunks);
        $indexedOps = 0;
        
        foreach ($chunks as $idx => $chunkStr) {
            // Embed
            $emb = getEmbedding($gemini, $chunkStr);
            if (!$emb) {
                echo "      ⚠️ Embedding failed for chunk " . ($idx+1) . "\n";
                // Optionally retry or skip
                continue;
            }
            
            // Insert
            $ins = $pdo->prepare("INSERT INTO knowledge_chunks (file_name, chunk_text, embedding) VALUES (?, ?, ?)");
            $ins->execute([$targetFile, $chunkStr, json_encode($emb)]);
            $indexedOps++;
            
            if ($indexedOps % 10 == 0) echo ".";
            usleep(200000); // 200ms
        }
        
        echo "\n   ✅ Indexed {$indexedOps} / {$total} chunks.\n";

    } catch (Exception $e) {
        echo "\n   ❌ CRITICAL ERROR for {$targetFile}: " . $e->getMessage() . "\n";
    }
}

echo "\n🏁 Batch Operation Complete.\n";

// ==================================================
// HELPERS
// ==================================================

function attemptGeminiOCR($client, $pdfPath, $pStart, $pEnd, $config = []) {
    $translate = $config['translate'] ?? false;
    $lang = $config['target_lang'] ?? 'English';

    $prompt = "Extract all text from these PDF pages. Return ONLY the text, no conversational filler.";
    if ($translate) {
        // More robust prompt for translation
        $prompt = "Act as a professional translator. Extract the text from these PDF pages and TRANSLATE it into $lang. Return ONLY the translated text, no conversational filler. Maintain the original logical structure and meaning.";
    }

    $retries = 3;
    while ($retries > 0) {
        try {
            $data = base64_encode(file_get_contents($pdfPath));
            $part = new \Gemini\Data\Blob(
                mimeType: \Gemini\Enums\MimeType::APPLICATION_PDF,
                data: $data
            );
            
            // Using 2.0 Flash as it is good at this
            $model = $client->generativeModel('models/gemini-2.0-flash');
            $response = $model->generateContent([
                $prompt,
                $part
            ]);
            
            // Safe access to candidates
            if (!empty($response->candidates) && isset($response->candidates[0])) {
                $candidate = $response->candidates[0];
                if (!empty($candidate->content->parts) && isset($candidate->content->parts[0])) {
                    return $candidate->content->parts[0]->text;
                }
            }
            
            // Check for prompt feedback block
            if (!empty($response->promptFeedback)) {
                echo "      ⚠️ Blocked by safety filter (pages $pStart-$pEnd)\n";
                return ""; 
            }

            // Unknown empty response
            throw new Exception("Empty candidates returned");
            
        } catch (Exception $e) {
            $retries--;
            if ($retries <= 0) {
                echo "      ❌ Gemini Error ({$e->getMessage()})\n";
                return "";
            }
            sleep(5);
        }
    }
    return "";
}

function getEmbedding($client, $text) {
    try {
        $response = $client->embeddingModel('models/text-embedding-004')->embedContent($text);
        return $response->embedding->values;
    } catch (Exception $e) {
        return null;
    }
}
