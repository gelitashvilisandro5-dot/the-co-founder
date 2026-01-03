<?php
$db_file = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO("sqlite:$db_file");
    $count = $pdo->query("SELECT count(DISTINCT file_name) FROM knowledge_chunks")->fetchColumn();
    echo "Files indexed: $count\n";
    
    $stm = $pdo->query("SELECT file_name FROM knowledge_chunks LIMIT 5");
    $files = $stm->fetchAll(PDO::FETCH_COLUMN);
    echo "Sample files:\n" . implode("\n", $files) . "\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
