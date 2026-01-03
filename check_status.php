<?php
// check_status.php
$db_file = __DIR__ . '/db/database.sqlite';

// DIAGNOSTICS
echo "<pre><strong>Diagnostic Info:</strong>\n";
echo "Root Contents:\n";
print_r(scandir(__DIR__));
echo "\nAssets Contents:\n";
if (is_dir(__DIR__ . '/assets')) {
    print_r(scandir(__DIR__ . '/assets'));
} else {
    echo "⚠️ Assets directory missing!\n";
}
echo "</pre>\n";

if (!file_exists($db_file)) {
    die("❌ ბაზა ვერ მოიძებნა! Path: $db_file");
}

$pdo = new PDO("sqlite:$db_file");

// დავითვალოთ უნიკალური წიგნები
$stmt = $pdo->query("SELECT COUNT(DISTINCT file_name) FROM knowledge_chunks");
$bookCount = $stmt->fetchColumn();

// დავითვალოთ სულ რამდენი პარაგრაფია (ჩანკი)
$stmt = $pdo->query("SELECT COUNT(*) FROM knowledge_chunks");
$chunkCount = $stmt->fetchColumn();

echo "\n📊 --- სტატისტიკა --- 📊\n";
echo "📚 სულ დაინდექსდა: " . $bookCount . " წიგნი\n";
echo "🧩 სულ მონაცემები: " . $chunkCount . " ფრაგმენტი (chunks)\n";
echo "-----------------------\n";

// გამოვიტანოთ სია, რა დაინდექსდა
echo "✅ დაინდექსებული წიგნების სია:\n";
$stmt = $pdo->query("SELECT DISTINCT file_name FROM knowledge_chunks ORDER BY file_name");
$books = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($books as $index => $book) {
    echo ($index + 1) . ". " . $book . "\n";
}
?>