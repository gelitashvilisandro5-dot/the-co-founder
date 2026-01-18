<?php
/**
 * delete_books.php - Delete specific books from knowledge_chunks table
 */

$db_file = __DIR__ . '/db/database.sqlite';

if (!file_exists($db_file)) {
    die("❌ Database not found at: $db_file\n");
}

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Connected to database.\n";

    $filesToDelete = [
        '604747501-ვადიმ-ზელანდი-რეალობის-ტრანსერფინგი-1.pdf',
        '815789010-რობერტ-გრინი-ძალაუფლების-48-კანონი.pdf',
        'think and grow rich.pdf'
    ];

    foreach ($filesToDelete as $fileName) {
        $stmt = $pdo->prepare("DELETE FROM knowledge_chunks WHERE file_name = ?");
        $stmt->execute([$fileName]);
        $count = $stmt->rowCount();
        echo "🗑️ Deleted '$fileName' ($count chunks removed).\n";
    }

    echo "🏁 Deletion complete.\n";

} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage() . "\n");
}
