<?php
/**
 * Universal Indexer - index_bucket.php (Robust Version)
 * Handles Rate Limits & JSON Errors automatically
 */

require 'vendor/autoload.php';

ini_set('memory_limit', '2048M');
set_time_limit(0); 

use Google\Cloud\Storage\StorageClient;
use Smalot\PdfParser\Parser;
use Gemini\Client;
use setasign\Fpdi\Fpdi;
use PhpOffice\PhpWord\IOFactory;

// --- კონფიგურაცია ---
$api_key = getenv('GEMINI_API_KEY');
$bucket_name = getenv('GOOGLE_STORAGE_BUCKET');
$db_file = __DIR__ . '/database.sqlite';

// --- კლიენტები ---
if (!file_exists('google-key.json')) {
    die("❌ Error: 'google-key.json' not found!\n");
}
$storage = new StorageClient(['keyFilePath' => 'google-key.json']);
$bucket  = $storage->bucket($bucket_name);
$pdfParser = new Parser();
$gemini = Gemini::client($api_key);

// --- SQLite დაკავშირება ---
try {
    if (!file_exists(dirname($db_file))) {
        mkdir(dirname($db_file), 0777, true);
    }
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS knowledge_chunks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file_name TEXT NOT NULL,
        chunk_text TEXT NOT NULL,
        embedding TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_name ON knowledge_chunks (file_name)");
    
} catch (PDOException $e) {
    die("❌ DB Connection failed: " . $e->getMessage());
}

// --- დამხმარე ფუნქციები ---

function extractTextFromDocx($filePath) {
    try {
        $phpWord = IOFactory::load($filePath);
        $text = "";
        foreach ($phpWord->getSections() as $section) {
            $text .= extractNodeText($section);
        }
        return $text;
    } catch (Exception $e) {
        return "";
    }
}

function extractNodeText($node) {
    $text = "";
    if (method_exists($node, 'getElements')) {
        foreach ($node->getElements() as $element) {
            $text .= extractNodeText($element);
        }
    } elseif (method_exists($node, 'getText')) {
        $nodeText = $node->getText();
        if (is_string($nodeText)) {
            $text .= $nodeText . "\n";
        }
    }
    return $text;
}

function extractTextFromEpub($filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) === TRUE) {
        $text = "";
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/\.(xhtml|html?)$/i', $name)) {
                $content = $zip->getFromName($name);
                $text .= strip_tags($content) . "\n";
            }
        }
        $zip->close();
        return $text;
    }
    return "";
}

/**
 * Robust Embedding function with Retry
 */
function getEmbeddingWithRetry($gemini, $text, $retries = 3) {
    $attempt = 0;
    while ($attempt < $retries) {
        try {
            $response = $gemini->embeddingModel('models/text-embedding-004')->embedContent($text);
            return $response->embedding->values;
        } catch (Exception $e) {
            $attempt++;
            // თუ JSON error ან 429 არის, ველოდებით
            if (strpos($e->getMessage(), 'JSON') !== false || strpos($e->getMessage(), '429') !== false) {
                echo "   ⚠️ API Busy (Attempt $attempt/$retries). Sleeping 10s...\n";
                sleep(10);
            } else {
                sleep(2);
            }
        }
    }
    throw new Exception("Failed to get embedding after $retries attempts");
}

function splitPdfAndExtractWithGemini($tempFile, $gemini, $fileName) {
    echo "📄 [{$fileName}] Using OCR...\n";
    $extractedText = "";
    try {
        $fpdi = new Fpdi();
        $pageCount = $fpdi->setSourceFile($tempFile);
        
        $step = 10; 
        for ($start = 1; $start <= $pageCount; $start += $step) {
            $end = min($start + $step - 1, $pageCount);
            
            $segmentFpdi = new Fpdi();
            $segmentFpdi->setSourceFile($tempFile);
            for ($i = $start; $i <= $end; $i++) {
                $tpl = $segmentFpdi->importPage($i);
                $segmentFpdi->addPage();
                $segmentFpdi->useTemplate($tpl);
            }
            
            $segmentFile = tempnam(sys_get_temp_dir(), 'seg_');
            $segmentFpdi->Output('F', $segmentFile);
            
            try {
                $pdfData = base64_encode(file_get_contents($segmentFile));
                $model = $gemini->generativeModel('models/gemini-3-flash-preview');
                
                // Retry logic for OCR
                $success = false;
                $ocrRetries = 0;
                while (!$success && $ocrRetries < 3) {
                    try {
                        $response = $model->generateContent([
                            "Transcribe text only.",
                            new \Gemini\Data\Blob(mimeType: \Gemini\Enums\MimeType::APPLICATION_PDF, data: $pdfData)
                        ]);
                        $candidate = $response->candidates[0] ?? null;
                        if ($candidate) {
                            $extractedText .= ($candidate->content->parts[0]->text ?? "") . "\n";
                            $success = true;
                        }
                    } catch (Exception $e) {
                        $ocrRetries++;
                        $errMsg = $e->getMessage();
                        echo "   ⚠️ OCR Error: $errMsg. Sleeping 15s...\n";
                        sleep(15);
                    }
                }

            } catch (Exception $ge) {
                echo "   ❌ Segment Error: " . $ge->getMessage() . "\n";
            }
            if (file_exists($segmentFile)) unlink($segmentFile);
            usleep(500000); 
        }
    } catch (Exception $e) {
        echo "❌ Splitting failed: " . $e->getMessage() . "\n";
    }
    return $extractedText;
}

// --- მთავარი პროცესი ---

echo "🚀 Starting Robust Indexer (SQLite)...\n";

$objects = $bucket->objects();

foreach ($objects as $object) {
    $fileName = $object->name();
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['pdf', 'txt', 'docx', 'epub'])) continue;

    // Check DB
    $stmt = $pdo->prepare("SELECT count(*) FROM knowledge_chunks WHERE file_name = ?");
    $stmt->execute([$fileName]);
    if ($stmt->fetchColumn() > 0) {
        echo "⏩ Skipping [{$fileName}] (Already Indexed)\n";
        continue;
    }

    echo "📖 Processing [{$fileName}]...\n";

    try {
        $text = "";
        $tempFile = tempnam(sys_get_temp_dir(), 'proc_');
        
        try {
            if ($ext === 'txt') {
                $text = $object->downloadAsString();
            } else {
                $object->downloadToFile($tempFile);
                
                if ($ext === 'pdf') {
                    // Try standard parse first
                    try {
                        $pdf = $pdfParser->parseFile($tempFile);
                        $text = $pdf->getText();
                    } catch (Exception $e) { $text = ""; }

                    // Fallback to OCR if empty
                    if (mb_strlen(trim($text)) < 50) {
                        $text = splitPdfAndExtractWithGemini($tempFile, $gemini, $fileName);
                    }
                } elseif ($ext === 'docx') {
                    $text = extractTextFromDocx($tempFile);
                } elseif ($ext === 'epub') {
                    $text = extractTextFromEpub($tempFile);
                }
            }
        } catch (Exception $dlError) {
            echo "❌ Download/Read Error: " . $dlError->getMessage() . "\n";
            if (file_exists($tempFile)) unlink($tempFile);
            continue;
        }

        if (file_exists($tempFile)) unlink($tempFile);
        
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if (empty(trim($text))) {
            echo "⚠️ No text extracted. Skipping.\n";
            continue;
        }

        // Chunking
        $chunkSize = 2000;
        $overlap = 200;
        $chunks = [];
        $start = 0;
        $textLength = mb_strlen($text);
        
        while ($start < $textLength) {
            $chunks[] = mb_substr($text, $start, $chunkSize);
            $start += ($chunkSize - $overlap);
        }

        $totalChunks = count($chunks);
        echo "🧩 Chunks created: {$totalChunks}\n";

        foreach ($chunks as $index => $chunkText) {
            $chunkNum = $index + 1;
            $chunkText = str_replace("\x00", "", $chunkText);

            if ($chunkNum % 20 === 0) echo ".";

            try {
                $embedding = getEmbeddingWithRetry($gemini, $chunkText);
                
                $stmt = $pdo->prepare("INSERT INTO knowledge_chunks (file_name, chunk_text, embedding) VALUES (?, ?, ?)");
                $stmt->execute([$fileName, $chunkText, json_encode($embedding)]);
                
                usleep(100000); // 0.1s pause standard
            } catch (Exception $e) {
                echo "❌ Failed chunk {$chunkNum}: " . $e->getMessage() . "\n";
            }
        }
        echo "\n✅ [{$fileName}] Done.\n";

        // IMPORTANT: Pause between files to save quota
        sleep(2); 

    } catch (Throwable $e) {
        $msg = $e->getMessage();
        echo "❌ Critical Error [{$fileName}]: $msg\n";
        
        // --- RATE LIMIT PROTECTION ---
        if (strpos($msg, 'JSON') !== false || strpos($msg, '429') !== false) {
            echo "🛑 RATE LIMIT HIT! Cooling down for 60 seconds...\n";
            sleep(60); 
        }
    }
}

echo "🏁 Indexing complete.\n";
?>