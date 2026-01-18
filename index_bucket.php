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
    die("❌ Error: GEMINI_API_KEY not found!\n");
}

// HARDCODED BUCKET AS REQUESTED (Replace with your actual bucket name)
$bucket_name = 'YOUR_BUCKET_NAME';
$db_file = __DIR__ . '/db/database.sqlite';

// --- კლიენტები ---
$keyFile = __DIR__ . '/google-key.json';
if (!file_exists($keyFile)) {
    die("❌ Error: 'google-key.json' not found!\n");
}
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $keyFile);

$storage = new StorageClient();
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

/**
 * Helper to extract text from XML nodes
 */
function extractNodeText($node) {
    $text = "";
    if ($node->nodeType == XML_TEXT_NODE) {
        $text = $node->nodeValue;
    }
    if ($node->hasChildNodes()) {
        foreach ($node->childNodes as $child) {
            $text .= extractNodeText($child);
        }
    }
    return $text;
}

/**
 * Extract text from DOCX
 */
function extractTextFromDocx($filePath) {
    if (!file_exists($filePath)) return "";
    
    $zip = new ZipArchive;
    if ($zip->open($filePath) === TRUE) {
        if (($index = $zip->locateName("word/document.xml")) !== false) {
            $xmlData = $zip->getFromIndex($index);
            $dom = new DOMDocument;
            @$dom->loadXML($xmlData, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
            $zip->close();
            
            return extractNodeText($dom->documentElement);
        }
        $zip->close();
    }
    return "";
}

/**
 * Extract text from EPUB
 */
function extractTextFromEpub($filePath) {
    if (!file_exists($filePath)) return "";
    
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        error_log("❌ Failed to open EPUB zip: $filePath");
        return "";
    }

    // 1. Find OPF path from META-INF/container.xml
    $containerXml = $zip->getFromName('META-INF/container.xml');
    if (!$containerXml) {
        $zip->close();
        return "";
    }

    $dom = new DOMDocument();
    @$dom->loadXML($containerXml);
    $rootfile = $dom->getElementsByTagName('rootfile')->item(0);
    if (!$rootfile) {
        $zip->close();
        return "";
    }
    
    $opfPath = $rootfile->getAttribute('full-path');
    $opfDir = dirname($opfPath);
    if ($opfDir === '.') $opfDir = '';
    else $opfDir .= '/';

    // 2. Parse OPF to get Spine and Manifest
    $opfXml = $zip->getFromName($opfPath);
    if (!$opfXml) {
        $zip->close();
        return "";
    }

    $domOpf = new DOMDocument();
    @$domOpf->loadXML($opfXml);
    
    // Convert Manifest to ID -> Href map
    $manifest = [];
    foreach ($domOpf->getElementsByTagName('item') as $item) {
        $id = $item->getAttribute('id');
        $href = $item->getAttribute('href');
        $manifest[$id] = $href;
    }

    // 3. Iterate Spine to get reading order
    $fullText = "";
    foreach ($domOpf->getElementsByTagName('itemref') as $itemref) {
        $idref = $itemref->getAttribute('idref');
        if (isset($manifest[$idref])) {
            $fileHref = $manifest[$idref];
            // Resolve path relative to OPF
            $contentPath = $opfDir . $fileHref;
            
            $content = $zip->getFromName($contentPath);
            if ($content) {
                // Strip tags (simple approach) or use DOM
                // Adding a space ensures words don't merge across tags
                $cleanText = strip_tags(str_replace(['<br>', '<p>', '</div>'], ["\n", "\n\n", "\n"], $content));
                $fullText .= $cleanText . "\n";
            }
        }
    }
    
    $zip->close();
    // Decode HTML entities
    return html_entity_decode($fullText, ENT_QUOTES | ENT_HTML5);
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
            if ($attempt >= $retries) throw $e;
            
            if (strpos($e->getMessage(), 'JSON') !== false || strpos($e->getMessage(), '429') !== false) {
                echo "   ⚠️ API Busy (Attempt $attempt/$retries). Sleeping 10s...\n";
                sleep(10);
            } else {
                sleep(2);
            }
        }
    }
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
                $model = $gemini->generativeModel('models/gemini-2.0-flash');
                
                $success = false;
                $ocrRetries = 0;
                while (!$success && $ocrRetries < 3) {
                    try {
                        $response = $model->generateContent([
                            "Extract all text from these PDF pages. Return ONLY the text, no conversational filler.",
                            new \Gemini\Data\Blob(mimeType: \Gemini\Enums\MimeType::APPLICATION_PDF, data: $pdfData)
                        ]);
                        
                        if (!empty($response->candidates) && isset($response->candidates[0])) {
                            $candidate = $response->candidates[0];
                            if (!empty($candidate->content->parts) && isset($candidate->content->parts[0])) {
                                $extractedText .= ($candidate->content->parts[0]->text ?? "") . "\n";
                                $success = true;
                            }
                        }

                        if (!$success && !empty($response->promptFeedback)) {
                            echo "      ⚠️ Segment blocked by safety filter.\n";
                            $success = true; // Skip
                        }
                        
                        if (!$success) throw new Exception("Empty response");

                    } catch (Exception $e) {
                        $ocrRetries++;
                        echo "   ⚠️ OCR Error: " . $e->getMessage() . ". Sleeping 15s (Attempt $ocrRetries)...\n";
                        sleep(15);
                    }
                }

            } catch (Exception $ge) {
                echo "   ❌ Segment Error: " . $ge->getMessage() . "\n";
            }
            if (file_exists($segmentFile)) unlink($segmentFile);
        }
    } catch (Exception $e) {
        echo "❌ Splitting failed: " . $e->getMessage() . "\n";
    }
    return $extractedText;
}

// ... (main loop continues below, using the same robust logic) ...


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