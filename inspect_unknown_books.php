<?php
/**
 * inspect_unknown_books.php
 * Identifies books by reading their actual content from the database.
 */

require 'vendor/autoload.php';
use Gemini\Client;

// --- CONFIG ---
$db_file = __DIR__ . '/db/database.sqlite';
$api_key = "";

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($n, $v) = explode('=', $line, 2) + [NULL, NULL];
        if ($n === 'GEMINI_API_KEY') $api_key = trim($v);
    }
}
if (!$api_key) $api_key = getenv('GEMINI_API_KEY');
if (!$api_key) die("❌ API Key missing.\n");

$client = Gemini::client($api_key);
$model = $client->generativeModel('models/gemini-2.0-flash');

$unknownFiles = [
    '021.pdf',
    '9780753556528.pdf',
    'ABUIABA9GAAgzcquuQYowMibkgM.pdf', // 10
    'AETCS-2019-Book-for-Web.pdf', // 11
    'L-G-0000588237-0002385028.pdf', // 44
    'Marketing Book.pdf', // 47
    'SEv3.pdf', // 58
    'bk_hayh_001430.pdf', // 87
    'bk_ntgl_000033.pdf', // 88
    'output.pdf', // 115
    'სიმბრძნე_სიმდიდრისა_V1_1.pdf' // 139 - Needs content check
];

echo "🔍 Connecting to DB to inspect contents...\n";

try {
    $pdo = new PDO("sqlite:$db_file");
    
    foreach ($unknownFiles as $file) {
        echo "\n------------------------------------------------\n";
        echo "📂 Analyzing: $file\n";
        
        // Fetch the first 2 chunks to get enough context (title page/intro)
        $stmt = $pdo->prepare("SELECT chunk_text FROM knowledge_chunks WHERE file_name = ? ORDER BY id ASC LIMIT 3");
        $stmt->execute([$file]);
        $chunks = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($chunks)) {
            echo "   ⚠️ No content found in database.\n";
            continue;
        }

        $context = implode("\n... [cut] ...\n", $chunks);
        
        // Truncate to avoid huge prompts, but keep enough for ID
        $contextSnippet = substr($context, 0, 5000); 

        $prompt = <<<EOT
I have a book file named "$file", but the filename is cryptic.
Here is the text from the beginning of the book:

"""
$contextSnippet
"""

Based on this text:
1. Identify the REAL Title and Author.
2. Provide a 2-3 sentence summary of its core value for an entrepreneur.
3. If it's in Georgian, translate the title/author to English for the answer.

Format:
**Title:** [Real Title]
**Author:** [Real Author]
**Summary:** [Summary]
EOT;

        try {
            echo "   🤔 Asking Gemini...\n";
            $response = $model->generateContent($prompt);
            echo $response->text() . "\n";
        } catch (Exception $e) {
            echo "   ❌ Gemini Error: " . $e->getMessage() . "\n";
        }
        
        sleep(2); // Rate limit nice
    }

} catch (PDOException $e) {
    die("❌ DB Error: " . $e->getMessage());
}
