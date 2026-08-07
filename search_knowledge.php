<?php
require 'vendor/autoload.php';

use Gemini\Client;

ini_set('memory_limit', '1024M');

const COFOUNDER_EMBED_MODEL = 'models/gemini-embedding-001';
const COFOUNDER_CACHE_DIR = '/tmp/cofounder_cache';
const COFOUNDER_SEARCH_CACHE_VERSION = 'v5';

/**
 * Legacy-compatible cosine similarity helper.
 */
function cosineSimilarity($vec1, $vec2)
{
    if (!$vec1 || !$vec2 || count($vec1) !== count($vec2)) {
        return 0.0;
    }

    $dotProduct = 0.0;
    $norm1 = 0.0;
    $norm2 = 0.0;

    foreach ($vec1 as $i => $val) {
        $b = (float) $vec2[$i];
        $a = (float) $val;
        $dotProduct += $a * $b;
        $norm1 += $a * $a;
        $norm2 += $b * $b;
    }

    $divisor = sqrt($norm1) * sqrt($norm2);
    return $divisor == 0.0 ? 0.0 : ($dotProduct / $divisor);
}

function searchKnowledgeBase($query, $preferredFiles = [])
{
    $startTime = microtime(true);
    $query = trim((string) $query);
    if ($query === '') {
        return [];
    }

    $dbFile = __DIR__ . '/db/database.sqlite';
    $apiKey = cofounderGetApiKey();

    if ($apiKey === null) {
        error_log('❌ GEMINI_API_KEY is missing.');
        return [];
    }

    try {
        $pdo = new PDO("sqlite:$dbFile");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        error_log('❌ DB Error: ' . $e->getMessage());
        return [];
    }

    // Short-lived search cache to speed up repeated prompts during multi-turn chats.
    $cacheFingerprint = cofounderGetDbFingerprint($pdo);
    $searchCacheKey = 'search:' . sha1(COFOUNDER_SEARCH_CACHE_VERSION . '|' . $query . '|' . json_encode(array_values($preferredFiles)) . '|' . $cacheFingerprint);
    $cachedResults = cofounderCacheGet($searchCacheKey, 90);
    if (is_array($cachedResults)) {
        return $cachedResults;
    }

    $gemini = Gemini::client($apiKey);
    $queryEmbedding = cofounderGetQueryEmbedding($gemini, $query);
    if (!$queryEmbedding) {
        // Embedding can fail due API limits or transient issues; keep retrieval alive via lexical-only path.
        $dynamicContextSize = cofounderDynamicContextSize($query);
        $candidateLimit = max(300, $dynamicContextSize * 25);
        $lexicalRows = cofounderFetchLexicalCandidates($pdo, $query, $candidateLimit);

        if (count($lexicalRows) < 120) {
            $fallbackRows = cofounderFetchFallbackCandidates($pdo, $query, $candidateLimit);
            foreach ($fallbackRows as $row) {
                $lexicalRows[$row['id']] = $row;
            }
        }

        if (empty($lexicalRows)) {
            $backstop = cofounderBackstopCandidates($pdo, $dynamicContextSize, $preferredFiles);
            if (!empty($backstop)) {
                cofounderCacheSet($searchCacheKey, $backstop);
                $endTime = microtime(true);
                error_log('📊 Retrieval KPI | mode=lexical_only_no_embedding_backstop | total_ms=' . round(($endTime - $startTime) * 1000) . ' | selected=' . count($backstop));
                return $backstop;
            }
            $endTime = microtime(true);
            error_log('📊 Retrieval KPI | mode=lexical_only_no_embedding_empty | total_ms=' . round(($endTime - $startTime) * 1000) . ' | results=0');
            return [];
        }

        $results = cofounderBuildLexicalOnlyResults($lexicalRows, $dynamicContextSize, $preferredFiles);
        cofounderCacheSet($searchCacheKey, $results);

        $endTime = microtime(true);
        error_log('📊 Retrieval KPI | mode=lexical_only_no_embedding | total_ms=' . round(($endTime - $startTime) * 1000) . ' | lexical_candidates=' . count($lexicalRows) . ' | selected=' . count($results));
        return $results;
    }
    $embeddingReadyAt = microtime(true);

    $dynamicContextSize = cofounderDynamicContextSize($query);
    $candidateLimit = max(300, $dynamicContextSize * 25);
    $lexicalRows = cofounderFetchLexicalCandidates($pdo, $query, $candidateLimit);

    // If lexical retrieval is weak, fall back to a broader pool.
    if (count($lexicalRows) < 120) {
        $fallbackRows = cofounderFetchFallbackCandidates($pdo, $query, $candidateLimit);
        foreach ($fallbackRows as $row) {
            $lexicalRows[$row['id']] = $row;
        }
    }
    $candidateReadyAt = microtime(true);

    if (empty($lexicalRows)) {
        // Absolute fallback: full-scan vector ranking (slower, but keeps recall intact).
        $results = cofounderFullScanVectorSearch($pdo, $queryEmbedding, $dynamicContextSize, $preferredFiles);
        if (empty($results)) {
            $results = cofounderBackstopCandidates($pdo, $dynamicContextSize, $preferredFiles);
            if (!empty($results)) {
                cofounderCacheSet($searchCacheKey, $results);
                $endTime = microtime(true);
                error_log('📊 Retrieval KPI | mode=fallback_backstop_after_lexical_empty | embedding_ms=' . round(($embeddingReadyAt - $startTime) * 1000) . ' | total_ms=' . round(($endTime - $startTime) * 1000) . ' | selected=' . count($results));
                return $results;
            }
        }
        cofounderCacheSet($searchCacheKey, $results);
        $endTime = microtime(true);
        error_log('📊 Retrieval KPI | mode=fallback_full_scan | embedding_ms=' . round(($embeddingReadyAt - $startTime) * 1000) . ' | total_ms=' . round(($endTime - $startTime) * 1000) . ' | results=' . count($results));
        return $results;
    }

    $scored = [];
    $preferredLookup = array_fill_keys(array_map('strtolower', array_values($preferredFiles)), true);

    // Precompute lexical rank boost.
    $lexicalRankBoost = [];
    $lexicalList = array_values($lexicalRows);
    usort($lexicalList, static function ($a, $b) {
        return ($b['lexical_score'] <=> $a['lexical_score']);
    });
    foreach ($lexicalList as $idx => $row) {
        $lexicalRankBoost[(int) $row['id']] = 1.0 / (1.0 + $idx);
    }

    foreach ($lexicalList as $row) {
        $embedding = json_decode($row['embedding'], true);
        if (!is_array($embedding) || count($embedding) !== count($queryEmbedding)) {
            continue;
        }

        $vectorScore = cosineSimilarity($queryEmbedding, $embedding);

        $rowId = (int) $row['id'];
        $rankBoost = $lexicalRankBoost[$rowId] ?? 0.0;
        $lexicalScore = (float) $row['lexical_score'];

        $preferredBoost = 0.0;
        if (!empty($preferredLookup) && isset($preferredLookup[strtolower($row['file_name'])])) {
            $preferredBoost = 0.04;
        }

        // Blend lexical + vector signals (lexical keeps broad coverage, vector keeps semantic quality).
        $finalScore = (0.78 * $vectorScore) + (0.18 * $lexicalScore) + (0.04 * $rankBoost) + $preferredBoost;

        $scored[] = [
            'id' => $rowId,
            'file_name' => $row['file_name'],
            'chunk_text' => $row['chunk_text'],
            'score' => $finalScore,
            'vector_score' => $vectorScore,
            'lexical_score' => $lexicalScore,
        ];
    }

    usort($scored, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    if (empty($scored)) {
        // Likely embedding-dimension mismatch against stored chunk vectors; fall back to lexical-only ranking.
        $results = cofounderBuildLexicalOnlyResults($lexicalRows, $dynamicContextSize, $preferredFiles);
        if (!empty($results)) {
            cofounderCacheSet($searchCacheKey, $results);
            $endTime = microtime(true);
            error_log('📊 Retrieval KPI | mode=fallback_lexical_from_hybrid_empty | embedding_ms=' . round(($embeddingReadyAt - $startTime) * 1000) . ' | total_ms=' . round(($endTime - $startTime) * 1000) . ' | lexical_candidates=' . count($lexicalRows) . ' | selected=' . count($results));
            return $results;
        }

        $results = cofounderFullScanVectorSearch($pdo, $queryEmbedding, $dynamicContextSize, $preferredFiles);
        if (empty($results)) {
            $results = cofounderBackstopCandidates($pdo, $dynamicContextSize, $preferredFiles);
            if (!empty($results)) {
                cofounderCacheSet($searchCacheKey, $results);
                $endTime = microtime(true);
                error_log('📊 Retrieval KPI | mode=fallback_backstop_after_fullscan_empty | embedding_ms=' . round(($embeddingReadyAt - $startTime) * 1000) . ' | total_ms=' . round(($endTime - $startTime) * 1000) . ' | selected=' . count($results));
                return $results;
            }
        }
        cofounderCacheSet($searchCacheKey, $results);
        $endTime = microtime(true);
        error_log('📊 Retrieval KPI | mode=fallback_after_hybrid_empty | embedding_ms=' . round(($embeddingReadyAt - $startTime) * 1000) . ' | total_ms=' . round(($endTime - $startTime) * 1000) . ' | results=' . count($results));
        return $results;
    }

    $results = cofounderSelectDiverseTopChunks($scored, $dynamicContextSize);
    cofounderCacheSet($searchCacheKey, $results);

    $endTime = microtime(true);
    error_log('📊 Retrieval KPI | mode=hybrid | embedding_ms=' . round(($embeddingReadyAt - $startTime) * 1000) . ' | candidate_ms=' . round(($candidateReadyAt - $embeddingReadyAt) * 1000) . ' | total_ms=' . round(($endTime - $startTime) * 1000) . ' | lexical_candidates=' . count($lexicalRows) . ' | reranked=' . count($scored) . ' | selected=' . count($results));

    return $results;
}

function cofounderGetApiKey()
{
    $envKey = getenv('GEMINI_API_KEY');
    if (!empty($envKey)) {
        return $envKey;
    }

    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) {
        return null;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, null);
        if (trim((string) $name) === 'GEMINI_API_KEY') {
            return trim((string) $value);
        }
    }

    return null;
}

function cofounderGetQueryEmbedding($gemini, $query)
{
    $embeddingCacheKey = 'embedding:' . sha1($query . '|' . COFOUNDER_EMBED_MODEL);
    $cached = cofounderCacheGet($embeddingCacheKey, 3600);
    if (is_array($cached) && !empty($cached)) {
        return $cached;
    }

    try {
        $response = $gemini->embeddingModel(COFOUNDER_EMBED_MODEL)->embedContent($query);
        $values = $response->embedding->values ?? null;
        if (!is_array($values) || empty($values)) {
            return null;
        }
        cofounderCacheSet($embeddingCacheKey, $values);
        return $values;
    } catch (Exception $e) {
        error_log('❌ Embedding Error: ' . $e->getMessage());
        return null;
    }
}

function cofounderDynamicContextSize($query)
{
    $len = mb_strlen($query, 'UTF-8');

    if ($len < 40) {
        return 16;
    }
    if ($len < 140) {
        return 24;
    }
    if ($len < 320) {
        return 32;
    }

    return 40;
}

function cofounderFetchLexicalCandidates(PDO $pdo, $query, $limit)
{
    $tokens = cofounderTokenizeQuery($query);
    if (empty($tokens)) {
        return [];
    }

    if (!cofounderEnsureFtsIndex($pdo)) {
        return [];
    }

    $ftsQuery = cofounderBuildFtsQuery($tokens);
    if ($ftsQuery === '') {
        return [];
    }

    $sql = "
        SELECT
            kc.id,
            kc.file_name,
            kc.chunk_text,
            kc.embedding,
            (1.0 / (1.0 + abs(bm25(knowledge_chunks_fts)))) AS lexical_score
        FROM knowledge_chunks_fts
        JOIN knowledge_chunks kc ON kc.id = knowledge_chunks_fts.rowid
        WHERE knowledge_chunks_fts MATCH :fts
        ORDER BY bm25(knowledge_chunks_fts) ASC
        LIMIT :limit
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':fts', $ftsQuery, PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    } catch (Exception $e) {
        error_log('⚠️ FTS fetch failed, fallback to vector-only: ' . $e->getMessage());
        return [];
    }
}

function cofounderFetchFallbackCandidates(PDO $pdo, $query, $limit)
{
    $tokens = cofounderSelectFallbackTokens($query, 10);
    if (empty($tokens)) {
        return [];
    }

    $whereParts = [];
    $params = [];

    foreach ($tokens as $token) {
        $whereParts[] = 'chunk_text LIKE ?';
        $params[] = '%' . $token . '%';
    }

    $sql = "
        SELECT id, file_name, chunk_text, embedding, 0.15 AS lexical_score
        FROM knowledge_chunks
        WHERE " . implode(' OR ', $whereParts) . "
        LIMIT " . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[(int) $row['id']] = $row;
    }

    return $rows;
}

function cofounderSelectFallbackTokens($query, $maxTokens = 10)
{
    $all = cofounderTokenizeQuery($query);
    if (empty($all)) {
        return [];
    }

    $ascii = [];
    $other = [];

    foreach ($all as $token) {
        if (preg_match('/[a-z0-9]/iu', $token) === 1) {
            $ascii[] = $token;
        } else {
            $other[] = $token;
        }
    }

    // Prefer technical ASCII terms (BOM, MSRP, CAC, EBITDA, etc.) for multilingual queries.
    usort($ascii, static function ($a, $b) {
        return mb_strlen((string) $b, 'UTF-8') <=> mb_strlen((string) $a, 'UTF-8');
    });
    usort($other, static function ($a, $b) {
        return mb_strlen((string) $b, 'UTF-8') <=> mb_strlen((string) $a, 'UTF-8');
    });

    $selected = array_merge(
        array_slice($ascii, 0, max(4, (int) floor($maxTokens * 0.7))),
        array_slice($other, 0, max(2, (int) ceil($maxTokens * 0.3)))
    );

    $selected = array_values(array_unique($selected));
    return array_slice($selected, 0, max(1, (int) $maxTokens));
}

function cofounderBuildLexicalOnlyResults(array $lexicalRows, int $dynamicContextSize, array $preferredFiles = [])
{
    if (empty($lexicalRows)) {
        return [];
    }

    $preferredLookup = array_fill_keys(array_map('strtolower', array_values($preferredFiles)), true);
    $lexicalList = array_values($lexicalRows);
    usort($lexicalList, static function ($a, $b) {
        return ($b['lexical_score'] <=> $a['lexical_score']);
    });

    $scored = [];
    foreach ($lexicalList as $idx => $row) {
        $lexicalScore = (float) ($row['lexical_score'] ?? 0.0);
        $rankBoost = 1.0 / (1.0 + $idx);
        $preferredBoost = 0.0;

        if (!empty($preferredLookup) && isset($preferredLookup[strtolower((string) $row['file_name'])])) {
            $preferredBoost = 0.04;
        }

        $finalScore = (0.88 * $lexicalScore) + (0.08 * $rankBoost) + $preferredBoost;
        $scored[] = [
            'id' => (int) $row['id'],
            'file_name' => $row['file_name'],
            'chunk_text' => $row['chunk_text'],
            'score' => $finalScore,
            'vector_score' => 0.0,
            'lexical_score' => $lexicalScore,
        ];
    }

    usort($scored, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return cofounderSelectDiverseTopChunks($scored, $dynamicContextSize);
}

function cofounderBackstopCandidates(PDO $pdo, int $limit, array $preferredFiles = [])
{
    $limit = max(8, (int) $limit);
    $maxFiles = max(6, (int) ceil($limit / 2));

    $preferred = array_values(array_unique(array_filter(array_map(static function ($v) {
        return trim((string) $v);
    }, $preferredFiles))));

    $filePool = [];
    foreach ($preferred as $name) {
        $filePool[] = $name;
    }

    try {
        $topStmt = $pdo->query('SELECT file_name FROM knowledge_chunks GROUP BY file_name ORDER BY COUNT(*) DESC LIMIT 60');
        while (($name = $topStmt->fetchColumn()) !== false) {
            $clean = trim((string) $name);
            if ($clean !== '') {
                $filePool[] = $clean;
            }
        }
    } catch (Exception $e) {
        error_log('⚠️ Backstop file pool query failed: ' . $e->getMessage());
    }

    $filePool = array_values(array_unique($filePool));
    if (empty($filePool)) {
        return [];
    }

    $selectedFiles = array_slice($filePool, 0, $maxFiles);
    $byFileStmt = $pdo->prepare('SELECT id, file_name, chunk_text FROM knowledge_chunks WHERE file_name = ? ORDER BY id DESC LIMIT 2');

    $rows = [];
    $baseScore = 0.05;
    foreach ($selectedFiles as $idx => $fileName) {
        try {
            $byFileStmt->execute([$fileName]);
            while ($row = $byFileStmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [
                    'id' => (int) $row['id'],
                    'file_name' => (string) $row['file_name'],
                    'chunk_text' => (string) $row['chunk_text'],
                    'score' => $baseScore - ($idx * 0.0001),
                    'vector_score' => 0.0,
                    'lexical_score' => 0.0,
                ];
                if (count($rows) >= $limit * 2) {
                    break 2;
                }
            }
        } catch (Exception $e) {
            error_log('⚠️ Backstop chunk query failed for file ' . $fileName . ': ' . $e->getMessage());
        }
    }

    if (empty($rows)) {
        return [];
    }

    return cofounderSelectDiverseTopChunks($rows, $limit);
}

function cofounderFullScanVectorSearch(PDO $pdo, $queryEmbedding, $dynamicContextSize, $preferredFiles = [])
{
    $stmt = $pdo->query('SELECT id, file_name, chunk_text, embedding FROM knowledge_chunks');

    $preferredLookup = array_fill_keys(array_map('strtolower', array_values($preferredFiles)), true);
    $results = [];
    $topReservoir = [];
    $reservoirLimit = max(200, $dynamicContextSize * 8);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $embedding = json_decode($row['embedding'], true);
        if (!is_array($embedding) || count($embedding) !== count($queryEmbedding)) {
            continue;
        }

        $score = cosineSimilarity($queryEmbedding, $embedding);
        if (!empty($preferredLookup) && isset($preferredLookup[strtolower($row['file_name'])])) {
            $score += 0.03;
        }

        cofounderReservoirPush($topReservoir, [
            'id' => (int) $row['id'],
            'file_name' => $row['file_name'],
            'chunk_text' => $row['chunk_text'],
            'score' => $score,
        ], $reservoirLimit);

        if ($score < 0.12) {
            continue;
        }

        $results[] = [
            'id' => (int) $row['id'],
            'file_name' => $row['file_name'],
            'chunk_text' => $row['chunk_text'],
            'score' => $score,
        ];
    }

    if (empty($results)) {
        $results = $topReservoir;
    }

    usort($results, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return cofounderSelectDiverseTopChunks($results, $dynamicContextSize);
}

function cofounderReservoirPush(&$reservoir, $candidate, $maxSize)
{
    if (count($reservoir) < $maxSize) {
        $reservoir[] = $candidate;
        return;
    }

    $minIdx = 0;
    $minScore = $reservoir[0]['score'];
    foreach ($reservoir as $idx => $row) {
        if ($row['score'] < $minScore) {
            $minScore = $row['score'];
            $minIdx = $idx;
        }
    }

    if ($candidate['score'] > $minScore) {
        $reservoir[$minIdx] = $candidate;
    }
}

function cofounderSelectDiverseTopChunks(array $rows, int $limit)
{
    $maxPerFile = max(2, (int) ceil($limit * 0.25));
    $selected = [];
    $fileCounts = [];
    $seenChunkHashes = [];

    foreach ($rows as $row) {
        $file = (string) $row['file_name'];
        $count = $fileCounts[$file] ?? 0;
        if ($count >= $maxPerFile) {
            continue;
        }

        $chunk = (string) $row['chunk_text'];
        $dedupeKey = sha1(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $chunk)), 'UTF-8'));
        if (isset($seenChunkHashes[$dedupeKey])) {
            continue;
        }

        unset($row['embedding']);
        $selected[] = $row;
        $seenChunkHashes[$dedupeKey] = true;
        $fileCounts[$file] = $count + 1;

        if (count($selected) >= $limit) {
            break;
        }
    }

    return $selected;
}

function cofounderTokenizeQuery($query)
{
    $query = mb_strtolower((string) $query, 'UTF-8');
    $query = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query);
    $tokens = preg_split('/\s+/u', trim($query));

    $filtered = [];
    foreach ($tokens as $token) {
        if ($token === null || $token === '') {
            continue;
        }

        if (mb_strlen($token, 'UTF-8') < 2) {
            continue;
        }

        $filtered[] = $token;
    }

    return array_values(array_unique($filtered));
}

function cofounderBuildFtsQuery(array $tokens)
{
    if (empty($tokens)) {
        return '';
    }

    $parts = [];
    foreach ($tokens as $token) {
        $safe = str_replace('"', ' ', $token);
        $parts[] = '"' . $safe . '"*';
    }

    return implode(' OR ', $parts);
}

function cofounderEnsureFtsIndex(PDO $pdo)
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS knowledge_chunks_fts USING fts5(chunk_text, file_name UNINDEXED, content='knowledge_chunks', content_rowid='id', tokenize='unicode61 remove_diacritics 2')");

        $pdo->exec("CREATE TRIGGER IF NOT EXISTS knowledge_chunks_ai AFTER INSERT ON knowledge_chunks BEGIN
            INSERT INTO knowledge_chunks_fts(rowid, chunk_text, file_name)
            VALUES (new.id, new.chunk_text, new.file_name);
        END;");

        $pdo->exec("CREATE TRIGGER IF NOT EXISTS knowledge_chunks_ad AFTER DELETE ON knowledge_chunks BEGIN
            INSERT INTO knowledge_chunks_fts(knowledge_chunks_fts, rowid, chunk_text, file_name)
            VALUES('delete', old.id, old.chunk_text, old.file_name);
        END;");

        $pdo->exec("CREATE TRIGGER IF NOT EXISTS knowledge_chunks_au AFTER UPDATE ON knowledge_chunks BEGIN
            INSERT INTO knowledge_chunks_fts(knowledge_chunks_fts, rowid, chunk_text, file_name)
            VALUES('delete', old.id, old.chunk_text, old.file_name);
            INSERT INTO knowledge_chunks_fts(rowid, chunk_text, file_name)
            VALUES (new.id, new.chunk_text, new.file_name);
        END;");

        // One-time bootstrap for existing rows.
        $baseCount = (int) $pdo->query('SELECT COUNT(*) FROM knowledge_chunks')->fetchColumn();
        $ftsCount = (int) $pdo->query('SELECT COUNT(*) FROM knowledge_chunks_fts')->fetchColumn();

        if ($baseCount > 0 && $ftsCount === 0) {
            $pdo->exec('INSERT INTO knowledge_chunks_fts(rowid, chunk_text, file_name) SELECT id, chunk_text, file_name FROM knowledge_chunks');
        }

        $ready = true;
        return true;
    } catch (Exception $e) {
        error_log('⚠️ FTS5 unavailable: ' . $e->getMessage());
        $ready = false;
        return false;
    }
}

function cofounderCacheGet($key, $ttlSeconds)
{
    $path = cofounderCachePath($key);
    if (!is_file($path)) {
        return null;
    }

    if ((time() - filemtime($path)) > $ttlSeconds) {
        @unlink($path);
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function cofounderCacheSet($key, $value)
{
    $dir = COFOUNDER_CACHE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $path = cofounderCachePath($key);
    @file_put_contents($path, json_encode($value, JSON_UNESCAPED_UNICODE));
}

function cofounderCachePath($key)
{
    return rtrim(COFOUNDER_CACHE_DIR, '/') . '/' . sha1($key) . '.json';
}

function cofounderGetDbFingerprint(PDO $pdo)
{
    try {
        $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM knowledge_chunks')->fetchColumn();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM knowledge_chunks')->fetchColumn();
        return $count . ':' . $maxId;
    } catch (Exception $e) {
        return '0:0';
    }
}

?>
