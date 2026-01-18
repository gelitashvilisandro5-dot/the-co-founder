<?php
require 'vendor/autoload.php';
use Gemini\Client;

ini_set('memory_limit', '1024M');

function cosineSimilarity($vec1, $vec2) {
    // იგივე რჩება ...
    if (!$vec1 || !$vec2 || count($vec1) !== count($vec2)) return 0;
    $dotProduct = 0; $norm1 = 0; $norm2 = 0;
    foreach ($vec1 as $i => $val) {
        $dotProduct += $val * $vec2[$i];
        $norm1 += $val * $val;
        $norm2 += $vec2[$i] * $vec2[$i];
    }
    $divisor = sqrt($norm1) * sqrt($norm2);
    return ($divisor == 0) ? 0 : $dotProduct / $divisor;
}

function searchKnowledgeBase($query, $allowedFiles = []) {
   // HARDCODED KEY AS REQUESTED (Replace with your actual key)
   $api_key = 'YOUR_GEMINI_API_KEY';
    $db_file = __DIR__ . '/db/example_database.sqlite';

    $gemini = Gemini::client($api_key);
    
    try {
        $pdo = new PDO("sqlite:$db_file");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        echo "❌ DB Error: " . $e->getMessage() . "\n";
        return [];
    }

    // 1. ემბედინგი
    try {
        $response = $gemini->embeddingModel('models/text-embedding-004')->embedContent($query);
        $queryEmbedding = $response->embedding->values;
    } catch (Exception $e) {
        echo "❌ Embedding Error: " . $e->getMessage() . "\n";
        return [];
    }

    // 2. ძებნა
    $sql = "SELECT id, file_name, chunk_text, embedding FROM knowledge_chunks";
    $params = [];

    if (!empty($allowedFiles)) {
        // Prepare placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($allowedFiles), '?'));
        $sql .= " WHERE file_name IN ($placeholders)";
        $params = array_values($allowedFiles);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $embedding = json_decode($row['embedding']);
        $similarity = cosineSimilarity($queryEmbedding, $embedding);
        
        if ($similarity > 0.40) {
            $row['score'] = $similarity;
            unset($row['embedding']);
            $results[] = $row;
        }
    }

    usort($results, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return array_slice($results, 0, 100);
}
?>