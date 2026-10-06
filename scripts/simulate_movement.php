<?php
// scripts/simulate_movement.php
// Usage: php scripts/simulate_movement.php [user_id]
// Default user_id = 2

require_once __DIR__ . '/../config/database.php';

// Auto-detect CLI vs Browser
$isCli = (php_sapi_name() === 'cli');
$userId = $isCli ? ($argv[1] ?? 2) : ($_GET['id'] ?? 2);

if (!$isCli) {
    echo "<pre>";
    echo "Simulating movement for User ID: $userId (Browser Mode - 60s limit)\n";
    // Increase time limit for this script
    set_time_limit(120);
} else {
    echo "Simulating movement for User ID: $userId (CLI Mode)\n";
}

$pdo = Database::getInstance()->getConnection();

// Get initial location
$stmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Fallback: Try to find ANY petugas
    if (!$isCli) echo "User ID $userId not found. Searching for any available petugas...<br>";
    
    $stmtFallback = $pdo->query("SELECT id, latitude, longitude, name FROM users WHERE role='petugas' LIMIT 1");
    $fallbackUser = $stmtFallback->fetch(PDO::FETCH_ASSOC);
    
    if ($fallbackUser) {
        $userId = $fallbackUser['id'];
        $user = $fallbackUser; // Use this user data
        if (!$isCli) echo "<strong>Found Petugas: " . htmlspecialchars($user['name']) . " (ID: $userId)</strong><br><br>";
        else echo "Switched to Petugas: {$user['name']} (ID: $userId)\n";
    } else {
        die("No 'petugas' users found in database at all!\n");
    }
}

$lat = (float)($user['latitude'] ?? -6.175110);
$lng = (float)($user['longitude'] ?? 106.865036);

echo "Starting at: $lat, $lng\n";
if ($isCli) echo "Press Ctrl+C to stop.\n\n";

$startTime = time();

while (true) {
    // Browser Limit: Stop after 60 seconds
    if (!$isCli && (time() - $startTime > 60)) {
        echo "Simulation detailed (60s reached). Reload to run again.\n";
        break;
    }

    // Random move approx 5-10 meters
    $latChange = (rand(-5, 5)) / 100000; 
    $lngChange = (rand(-5, 5)) / 100000;
    
    $lat += $latChange;
    $lng += $lngChange;
    
    // Update DB
    $upd = $pdo->prepare("UPDATE users SET latitude = ?, longitude = ?, last_location_update = NOW() WHERE id = ?");
    $upd->execute([$lat, $lng, $userId]);
    
    echo "Moved to: $lat, $lng (Time: " . date('H:i:s') . ")\n";
    
    if (!$isCli) {
        @ob_flush();
        @flush();
    }
    
    sleep(5); // Update every 5 seconds
}
