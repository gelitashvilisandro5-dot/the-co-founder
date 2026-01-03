<?php
/**
 * SQLite Database Initialization Script
 * Adapted for Cloud Run (No Cloud SQL required - Free & Fast)
 */

// ბაზის ფაილის მისამართი (პროექტის შიგნით db საქაღალდეში)
$db_file = __DIR__ . '/db/database.sqlite';

try {
    // SQLite დაკავშირება
    // ეს ავტომატურად შექმნის ფაილს, თუ ის არ არსებობს
    $pdo = new PDO("sqlite:$db_file");
    
    // ერორების რეჟიმის ჩართვა
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connected to SQLite database at: $db_file\n";

    // ცხრილის შექმნა
    // შენიშვნა: SQLite-ში INT AUTO_INCREMENT-ის ნაცვლად ვიყენებთ INTEGER PRIMARY KEY AUTOINCREMENT
    // JSON ტიპი SQLite-ში ინახება როგორც TEXT
    $tableSql = "
    CREATE TABLE IF NOT EXISTS knowledge_chunks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file_name TEXT NOT NULL,
        chunk_text TEXT NOT NULL,
        embedding TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ";
    
    $pdo->exec($tableSql);
    echo "✅ Table 'knowledge_chunks' ensured.\n";

    // ინდექსის შექმნა (სისწრაფისთვის)
    $indexSql = "CREATE INDEX IF NOT EXISTS idx_file_name ON knowledge_chunks (file_name);";
    $pdo->exec($indexSql);
    echo "✅ Index created on 'file_name'.\n";

} catch (PDOException $e) {
    die("❌ Database Error: " . $e->getMessage());
}
?>