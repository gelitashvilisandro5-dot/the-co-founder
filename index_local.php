<?php
// index_local.php - Final Robust Version

require 'vendor/autoload.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);


// HARDCODED BUCKET AS REQUESTED
$bucket_name = 'the-cofounder-knowledge-base';
$api_key = getenv('GEMINI_API_KEY');

ini_set('memory_limit', '2048M');
set_time_limit(0); 

use Google\Cloud\Storage\StorageClient;
use Smalot\PdfParser\Parser;
use Gemini\Client;

// --- Helper: Text Sanitizer (უფრო ძლიერი და ჩუმი) ---
function sanitizeUtf8($text) {
    // 1. mb_convert_encoding უფრო "მშვიდია" ვიდრე iconv. 
    // ის ასწორებს არასწორ სიმბოლოებს ერორის გარეშე.
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    
    // 2. წაშალე უხილავი სისტემური სიმბოლოები (Null bytes და ა.შ.)
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    
    return $text;
}

// --- კლიენტები ---
if (!file_exists('google-key.json')) {
    die("❌ Error: 'key.json' ვერ ვიპოვე! ჩააგდე საქაღალდეში.\n");
}

$storage = new StorageClient(['keyFilePath' => 'google-key.json']);
$bucket  = $storage->bucket($bucket_name);
$pdfParser = new Parser();
$gemini = Gemini::client($api_key);

$db_file = __DIR__ . '/database.sqlite';

// --- ბაზის მომზადება ---
try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS knowledge_chunks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file_name TEXT NOT NULL,
        chunk_text TEXT NOT NULL,
        embedding TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) { die("❌ DB Error: " . $e->getMessage()); }

echo "🚀 Starting Local Indexer (Silent Mode)...\n";

if (!$bucket->exists()) {
    die("❌ ბაქეთი '$bucket_name' არ არსებობს ან key.json არასწორია.\n");
}

$objects = $bucket->objects();

foreach ($objects as $object) {
    $fileName = $object->name();
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($ext, ['pdf', 'txt'])) continue;

    // შემოწმება: თუ უკვე ბაზაშია, ვტოვებთ
    $stmt = $pdo->prepare("SELECT count(*) FROM knowledge_chunks WHERE file_name = ?");
    $stmt->execute([$fileName]);
    if ($stmt->fetchColumn() > 0) {
        // echo "⏩ Skipping: $fileName\n"; 
        continue;
    }

    echo "📖 Processing: $fileName ...\n";
    
    try {
        $text = "";
        
        try {
            $content = $object->downloadAsString();
        } catch (Exception $e) {
            echo "   ⚠️ Download failed. Skipping.\n";
            continue;
        }
        
        if ($ext === 'pdf') {
            try {
                $pdf = $pdfParser->parseContent($content);
                $text = $pdf->getText();
            } catch (Exception $e) { 
                echo "   ⚠️ PDF Parse error (might be scanned or encrypted)\n"; 
            }
        } else {
            $text = $content;
        }
        
        // --- 🧹 CLEANING ---
        $text = sanitizeUtf8($text);
        
        if (mb_strlen($text) < 50) {
            echo "   ⚠️ Text empty/short. Skipping.\n";
            continue;
        }

        // Chunking
        $chunks = str_split($text, 2000);
        echo "   🧩 Chunks: " . count($chunks) . "\n";
        
        foreach ($chunks as $chunk) {
            $chunk = sanitizeUtf8($chunk);
            if (empty(trim($chunk))) continue;

            $retry = 0;
            $success = false;
            
            while (!$success && $retry < 3) {
                try {
                    $response = $gemini->embeddingModel('models/text-embedding-004')->embedContent($chunk);
                    $embedding = json_encode($response->embedding->values);
                    
                    $stmt = $pdo->prepare("INSERT INTO knowledge_chunks (file_name, chunk_text, embedding) VALUES (?, ?, ?)");
                    $stmt->execute([$fileName, $chunk, $embedding]);
                    $success = true;
                    
                    usleep(200000); // 0.2s pause
                    
                } catch (Exception $e) {
                    $retry++;
                    $msg = $e->getMessage();
                    
                    if (strpos($msg, '429') !== false || strpos($msg, 'Resource has been exhausted') !== false) {
                        echo "   ⏳ API Limit. Sleeping 20s...\n";
                        sleep(20);
                    } else {
                        // აქაც ჩუმად ვართ, თუ 3-ჯერ ვერ ქნა, უბრალოდ გაატარებს
                        sleep(2);
                    }
                }
            }
        }
        echo "   ✅ Done.\n";

    } catch (Exception $e) {
        echo "❌ Error on file: " . $e->getMessage() . "\n";
    }
}

echo "🏁 All Done! 'database.sqlite' is ready.\n";
?>