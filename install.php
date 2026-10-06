<?php
// Script instalasi sederhana
echo "SMPP Installation Script\n";
echo "========================\n\n";

// Cek PHP version
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    die("Error: PHP 7.4 or higher is required. You are using " . PHP_VERSION . "\n");
}

// Cek extension
$required_extensions = ['pdo', 'pdo_pgsql', 'gd', 'json', 'mbstring'];
$missing = [];

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}

if (!empty($missing)) {
    echo "Missing PHP extensions: " . implode(', ', $missing) . "\n";
    echo "Please install them before continuing.\n";
    exit(1);
}

// Database configuration
echo "Database Configuration\n";
echo "----------------------\n";

// Coba gunakan konfigurasi dari config/database.php jika tersedia
$use_config = false;
if (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    try {
        $pdo = Database::getInstance()->getConnection();
        $use_config = true;
        echo "✓ Using existing config/database.php\n";
    } catch (Throwable $e) {
        $use_config = false;
        echo "! Existing config failed: " . $e->getMessage() . "\n";
    }
}

if (!$use_config) {
    $host = readline("Database host [localhost]: ") ?: 'localhost';
    $port = readline("Database port [5432]: ") ?: '5432';
    $dbname = readline("Database name [sistem_ppsu]: ") ?: 'sistem_ppsu';
    $username = readline("Database username [postgres]: ") ?: 'postgres';
    $password = readline("Database password: ");
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
}
    
try {
    echo "✓ Database connection successful\n";
    
    // Baca file SQL
    $sql_file = __DIR__ . '/schema.sql';
    if (!file_exists($sql_file)) {
        die("Error: schema.sql not found\n");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split SQL statements
    $statements = explode(';', $sql);
    
    echo "Creating database schema...\n";
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (Exception $e) {
                echo "Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "✓ Database schema created\n";
    
    // Create config file jika belum ada
    if (!$use_config) {
        $template_path = __DIR__ . '/config/database.php.template';
        if (file_exists($template_path)) {
            $config_template = file_get_contents($template_path);
            $config_content = str_replace(
                ['{{host}}', '{{dbname}}', '{{username}}', '{{password}}', '{{port}}'],
                [$host, $dbname, $username, $password, $port],
                $config_template
            );
            file_put_contents(__DIR__ . '/config/database.php', $config_content);
            echo "✓ Configuration file created\n";
        } else {
            echo "! Skipping config creation (template not found)\n";
        }
    }
    
    // Create upload directories
    $directories = [
        'public/uploads/users',
        'public/uploads/reports',
        'public/uploads/zones',
        'public/uploads/temp'
    ];
    
    foreach ($directories as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
            echo "✓ Created directory: $dir\n";
        }
    }
    
    // Set permissions
    echo "Setting permissions...\n";
    chmod(__DIR__ . '/public/uploads', 0775);
    
    echo "\nInstallation completed successfully!\n";
    echo "Next steps:\n";
    echo "1. Login with admin/admin123\n";
    echo "2. Change the default admin password\n";
    echo "3. Configure zones and schedules\n";
    echo "4. Add petugas users\n";
    
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
