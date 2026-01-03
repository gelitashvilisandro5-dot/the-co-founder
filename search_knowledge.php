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

function searchKnowledgeBase($query) {
   $api_key = getenv('GEMINI_API_KEY');
    $db_file = __DIR__ . '/db/database.sqlite';

    $gemini = Gemini::client($api_key);
    
    try {
        $pdo = new PDO("sqlite:$db_file");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return [];
    }

    // 1. ემბედინგი
    try {
        $response = $gemini->embeddingModel('models/text-embedding-004')->embedContent($query);
        $queryEmbedding = $response->embedding->values;
    } catch (Exception $e) {
        return [];
    }

    // 2. ძებნა
    $stmt = $pdo->query("SELECT id, file_name, chunk_text, embedding FROM knowledge_chunks");
    $results = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $embedding = json_decode($row['embedding']);
        $similarity = cosineSimilarity($queryEmbedding, $embedding);
        
        if ($similarity > 0.45) {
            $row['score'] = $similarity;
            unset($row['embedding']);
            $results[] = $row;
        }
    }

    usort($results, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return array_slice($results, 0, 5);
}
?>