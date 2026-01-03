<?php
require 'vendor/autoload.php';
use Google\Cloud\Storage\StorageClient;

$db_host = 'mysql-container';
$db_name = 'analog_tech';
$db_user = 'acmerti';
$db_pass = 'Sandrinio22';
$bucket_name = getenv('CLOUD_STORAGE_BUCKET');

$storage = new StorageClient();
$bucket  = $storage->bucket($bucket_name);
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
$pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$objects = $bucket->objects();
$unindexed = [];
$skipped_extensions = [];

foreach ($objects as $object) {
    $fileName = $object->name();
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Support expanded list of formats
    $supportedExts = ['pdf', 'txt', 'docx', 'epub'];
    if (!in_array($ext, $supportedExts)) {
        $skipped_extensions[$ext] = ($skipped_extensions[$ext] ?? 0) + 1;
        continue;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE file_name = ?");
    $stmt->execute([$fileName]);
    if ($stmt->fetchColumn() == 0) {
        $unindexed[] = [
            'name' => $fileName,
            'size' => round($object->info()['size'] / 1024 / 1024, 2) . 'MB'
        ];
    }
}

echo "--- UNINDEXED FILES ---\n";
foreach ($unindexed as $file) {
    echo "- {$file['name']} ({$file['size']})\n";
}
echo "Total: " . count($unindexed) . "\n";
echo "--- SKIPPED EXTENSIONS ---\n";
print_r($skipped_extensions);
