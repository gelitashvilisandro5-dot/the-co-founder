<?php
/**
 * inspect_chunks.php - Analyze DB for potentially corrupt/garbage text
 */

$db_file = __DIR__ . '/db/database.sqlite';
$limit = 50; // Check random 50 chunks per file to save time, or set high to check all

if (!file_exists($db_file)) {
    die("❌ Database not found at: $db_file\n");
}

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "🔍 Starting analysis of knowledge_chunks...\n";

    // Get list of all files
    $stmt = $pdo->query("SELECT DISTINCT file_name FROM knowledge_chunks");
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📚 Found " . count($files) . " files in the database.\n\n";
    echo "filename | Avg Garbage Score | Status\n";
    echo str_repeat("-", 60) . "\n";

    $suspiciousFiles = [];

    foreach ($files as $file) {
        // Select a sample of chunks for this file
        $stmt = $pdo->prepare("SELECT chunk_text FROM knowledge_chunks WHERE file_name = ? LIMIT ?");
        $stmt->execute([$file, $limit]);
        $chunks = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($chunks) === 0) continue;

        $totalScore = 0;
        foreach ($chunks as $text) {
            $totalScore += calculateGarbageScore($text);
        }
        
        $avgScore = $totalScore / count($chunks);
        
        // Thresholds
        // Score > 20 is suspicious
        // Score > 50 is likely garbage
        
        $status = "✅ OK";
        if ($avgScore > 20) $status = "⚠️ Suspicious";
        if ($avgScore > 40) $status = "❌ CORRUPT";

        printf("%-40.40s | %6.2f | %s\n", $file, $avgScore, $status);

        if ($avgScore > 20) {
            $suspiciousFiles[] = [
                'name' => $file,
                'score' => $avgScore,
                'snippet' => substr(str_replace("\n", " ", $chunks[0]), 0, 50) . "..."
            ];
        }
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🚩 SUSPICIOUS FILES REPORT:\n";
    if (empty($suspiciousFiles)) {
        echo "No suspicious files found.\n";
    } else {
        foreach ($suspiciousFiles as $sf) {
            echo "\n📄 File: " . $sf['name'] . "\n";
            echo "   Garbage Score: " . number_format($sf['score'], 2) . "\n";
            echo "   Sample: [" . $sf['snippet'] . "]\n";
        }
    }

} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage() . "\n");
}

/**
 * Heuristic to calculate a "Garbage Score" (0-100)
 * Higher = worse
 */
function calculateGarbageScore($text) {
    $len = mb_strlen($text);
    if ($len === 0) return 100; // Empty is bad

    // Count weird characters (replacement char, unprintable)
    // replacement char: \xEF\xBF\xBD
    $replacementCount = substr_count($text, "\xEF\xBF\xBD");
    
    // Count Alphanumeric
    $cleanText = preg_replace('/[a-zA-Z0-9\s.,!?\'"-]/u', '', $text);
    $garbageLen = mb_strlen($cleanText);
    
    // Ratio of non-standard chars
    $garbageRatio = $garbageLen / $len;
    
    // Base score from garbage ratio
    $score = $garbageRatio * 100;

    // Penalize replacement chars heavily
    $score += ($replacementCount * 5);

    // Penalize very short lines implying OCR failure (many newlines)
    // e.g. "a\nb\nc\n"
    $lines = explode("\n", trim($text));
    $avgLineLen = $len / max(1, count($lines));
    if ($avgLineLen < 15 && $len > 100) {
        $score += 20; 
    }

    return min(100, $score);
}
