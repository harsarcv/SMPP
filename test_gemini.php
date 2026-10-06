<?php
/**
 * Test script for Gemini AI
 * Run this to verify API is working correctly
 */

require_once __DIR__ . '/helpers/GeminiAI.php';
require_once __DIR__ . '/config/database.php';

// Read API key from .env
$envPath = __DIR__ . '/.env';
$apiKey = '';

if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    if (preg_match('/GEMINI_API_KEY=(.+)/', $envContent, $matches)) {
        $apiKey = trim($matches[1]);
    }
}

echo "<h1>Gemini AI Test</h1>";
echo "<pre style='background:#f5f5f5; padding:15px; border-radius:8px;'>";

if (empty($apiKey)) {
    echo "❌ ERROR: GEMINI_API_KEY tidak ditemukan di file .env\n";
    exit;
}

echo "✅ API Key ditemukan: " . substr($apiKey, 0, 15) . "...\n\n";

// Get database connection
$pdo = Database::getInstance()->getConnection();

// Find a report photo from database
echo "🔍 Mencari foto laporan dari database...\n";
$photoStmt = $pdo->query("
    SELECT rp.id, rp.file_path, rp.ai_description, rp.ai_is_valid_work, r.description as task_desc
    FROM report_photos rp
    JOIN reports r ON rp.report_id = r.id
    ORDER BY rp.uploaded_at DESC
    LIMIT 5
");
$photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($photos)) {
    echo "⚠️ Tidak ada foto laporan ditemukan di database.\n";
    exit;
}

echo "📷 Ditemukan " . count($photos) . " foto terbaru:\n\n";

// Show current AI description in database
foreach ($photos as $idx => $photo) {
    echo "--- Foto " . ($idx+1) . " ---\n";
    echo "File: " . $photo['file_path'] . "\n";
    echo "AI Desc di DB: " . ($photo['ai_description'] ?: '(KOSONG)') . "\n";
    echo "AI Valid: " . ($photo['ai_is_valid_work'] !== null ? ($photo['ai_is_valid_work'] ? 'Yes' : 'No') : 'NULL') . "\n\n";
}

// Test with first photo
$testPhoto = $photos[0];
$testImagePath = __DIR__ . '/public/uploads/' . ltrim($testPhoto['file_path'], '/');

if (!file_exists($testImagePath)) {
    echo "❌ File tidak ditemukan: $testImagePath\n";
    exit;
}

echo "=== TESTING AI BARU ===\n";
echo "File: $testImagePath\n";
echo "Task Description: " . ($testPhoto['task_desc'] ?: 'tidak ada') . "\n\n";

// Initialize Gemini
$gemini = new GeminiAI();
$gemini->setApiKey($apiKey);

$result = $gemini->analyzeWorkPhoto($testImagePath, $testPhoto['task_desc'] ?? '');

echo "=== HASIL ANALISIS AI ===\n";
echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
echo "Valid Work: " . (isset($result['is_valid_work']) ? ($result['is_valid_work'] ? 'Yes' : 'No') : 'N/A') . "\n";
echo "Confidence: " . ($result['confidence'] ?? 0) . "%\n";
echo "Work Type: " . ($result['work_type'] ?? '-') . "\n";

echo "\n📝 DESKRIPSI AI:\n";
echo "\"" . ($result['description'] ?? 'TIDAK ADA DESKRIPSI') . "\"\n\n";

if (isset($result['reason'])) {
    echo "💡 REASON:\n" . $result['reason'] . "\n\n";
}

if (isset($result['error'])) {
    echo "❌ ERROR: " . $result['error'] . "\n\n";
}

// Show full result for debugging
echo "=== RAW RESULT ===\n";
print_r($result);

echo "</pre>";
