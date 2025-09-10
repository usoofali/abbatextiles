<?php
// Log Management Script
// This script provides easy control over Laravel logging to prevent log file bloat

echo "<h2>Laravel Log Management</h2>";

// Get current configuration
$envFile = __DIR__ . '/.env';
$currentConfig = [];

if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    
    // Extract current logging settings
    preg_match('/LOG_CHANNEL\s*=\s*([^\s]+)/', $envContent, $matches);
    $currentConfig['LOG_CHANNEL'] = $matches[1] ?? 'stack';
    
    preg_match('/LOG_LEVEL\s*=\s*([^\s]+)/', $envContent, $matches);
    $currentConfig['LOG_LEVEL'] = $matches[1] ?? 'debug';
    
    preg_match('/SYNC_LOGGING_ENABLED\s*=\s*([^\s]+)/', $envContent, $matches);
    $currentConfig['SYNC_LOGGING_ENABLED'] = $matches[1] ?? 'true';
    
    preg_match('/DEBUG_LOGGING_ENABLED\s*=\s*([^\s]+)/', $envContent, $matches);
    $currentConfig['DEBUG_LOGGING_ENABLED'] = $matches[1] ?? 'false';
}

echo "<h3>Current Configuration:</h3>";
echo "<ul>";
echo "<li><strong>LOG_CHANNEL:</strong> " . $currentConfig['LOG_CHANNEL'] . "</li>";
echo "<li><strong>LOG_LEVEL:</strong> " . $currentConfig['LOG_LEVEL'] . "</li>";
echo "<li><strong>SYNC_LOGGING_ENABLED:</strong> " . $currentConfig['SYNC_LOGGING_ENABLED'] . "</li>";
echo "<li><strong>DEBUG_LOGGING_ENABLED:</strong> " . $currentConfig['DEBUG_LOGGING_ENABLED'] . "</li>";
echo "</ul>";

// Check log file sizes
$logFiles = [
    'laravel.log' => storage_path('logs/laravel.log'),
    'sync.log' => storage_path('logs/sync.log'),
    'debug.log' => storage_path('logs/debug.log'),
    'error.log' => storage_path('logs/error.log')
];

echo "<h3>Log File Sizes:</h3>";
echo "<ul>";
foreach ($logFiles as $name => $path) {
    if (file_exists($path)) {
        $size = filesize($path);
        $sizeMB = round($size / 1024 / 1024, 2);
        $color = $sizeMB > 10 ? 'red' : ($sizeMB > 5 ? 'orange' : 'green');
        echo "<li style='color: {$color};'><strong>{$name}:</strong> {$sizeMB} MB</li>";
    } else {
        echo "<li style='color: gray;'><strong>{$name}:</strong> Not found</li>";
    }
}
echo "</ul>";

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    switch ($action) {
        case 'disable_sync_logging':
            updateEnvFile($envFile, 'SYNC_LOGGING_ENABLED', 'false');
            echo "<p style='color: green;'>✅ Sync logging disabled</p>";
            break;
            
        case 'enable_sync_logging':
            updateEnvFile($envFile, 'SYNC_LOGGING_ENABLED', 'true');
            echo "<p style='color: green;'>✅ Sync logging enabled</p>";
            break;
            
        case 'disable_debug_logging':
            updateEnvFile($envFile, 'DEBUG_LOGGING_ENABLED', 'false');
            updateEnvFile($envFile, 'LOG_LEVEL', 'error');
            echo "<p style='color: green;'>✅ Debug logging disabled</p>";
            break;
            
        case 'enable_debug_logging':
            updateEnvFile($envFile, 'DEBUG_LOGGING_ENABLED', 'true');
            updateEnvFile($envFile, 'LOG_LEVEL', 'debug');
            echo "<p style='color: green;'>✅ Debug logging enabled</p>";
            break;
            
        case 'set_daily_rotation':
            updateEnvFile($envFile, 'LOG_CHANNEL', 'daily');
            echo "<p style='color: green;'>✅ Log rotation set to daily</p>";
            break;
            
        case 'set_single_file':
            updateEnvFile($envFile, 'LOG_CHANNEL', 'single');
            echo "<p style='color: green;'>✅ Log rotation set to single file</p>";
            break;
            
        case 'clear_logs':
            clearLogFiles($logFiles);
            echo "<p style='color: green;'>✅ All log files cleared</p>";
            break;
            
        case 'clear_cache':
            clearLaravelCache();
            echo "<p style='color: green;'>✅ Laravel cache cleared</p>";
            break;
            
        case 'optimize_logs':
            optimizeLogging($envFile);
            echo "<p style='color: green;'>✅ Logging optimized for production</p>";
            break;
    }
    
    // Refresh page to show updated config
    echo "<script>setTimeout(() => window.location.reload(), 2000);</script>";
}

echo "<h3>Quick Actions:</h3>";
echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 20px 0;'>";

echo "<a href='?action=disable_sync_logging' style='padding: 10px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>🚫 Disable Sync Logging</a>";
echo "<a href='?action=enable_sync_logging' style='padding: 10px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>✅ Enable Sync Logging</a>";
echo "<a href='?action=disable_debug_logging' style='padding: 10px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>🚫 Disable Debug Logging</a>";
echo "<a href='?action=enable_debug_logging' style='padding: 10px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>✅ Enable Debug Logging</a>";
echo "<a href='?action=set_daily_rotation' style='padding: 10px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>📅 Set Daily Rotation</a>";
echo "<a href='?action=set_single_file' style='padding: 10px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>📄 Set Single File</a>";
echo "<a href='?action=clear_logs' style='padding: 10px; background: #ffc107; color: black; text-decoration: none; border-radius: 5px; text-align: center;'>🗑️ Clear All Logs</a>";
echo "<a href='?action=clear_cache' style='padding: 10px; background: #17a2b8; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>🧹 Clear Cache</a>";
echo "<a href='?action=optimize_logs' style='padding: 10px; background: #6f42c1; color: white; text-decoration: none; border-radius: 5px; text-align: center;'>⚡ Optimize Logging</a>";

echo "</div>";

echo "<h3>Recommended Settings:</h3>";
echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4>For Production (Minimal Logging):</h4>";
echo "<ul>";
echo "<li>LOG_CHANNEL=daily</li>";
echo "<li>LOG_LEVEL=error</li>";
echo "<li>SYNC_LOGGING_ENABLED=false</li>";
echo "<li>DEBUG_LOGGING_ENABLED=false</li>";
echo "</ul>";

echo "<h4>For Development (Full Logging):</h4>";
echo "<ul>";
echo "<li>LOG_CHANNEL=daily</li>";
echo "<li>LOG_LEVEL=debug</li>";
echo "<li>SYNC_LOGGING_ENABLED=true</li>";
echo "<li>DEBUG_LOGGING_ENABLED=true</li>";
echo "</ul>";
echo "</div>";

echo "<h3>Manual .env Configuration:</h3>";
echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>";
echo "# Logging Configuration\n";
echo "LOG_CHANNEL=daily\n";
echo "LOG_LEVEL=error\n";
echo "LOG_DAYS=7\n";
echo "SYNC_LOGGING_ENABLED=false\n";
echo "DEBUG_LOGGING_ENABLED=false\n";
echo "ERROR_LOGGING_ENABLED=true\n";
echo "SYNC_LOG_LEVEL=info\n";
echo "SYNC_LOG_DAYS=7\n";
echo "DEBUG_LOG_DAYS=3\n";
echo "ERROR_LOG_DAYS=30\n";
echo "</pre>";

// Helper functions
function updateEnvFile($envFile, $key, $value) {
    if (!file_exists($envFile)) {
        echo "<p style='color: red;'>❌ .env file not found</p>";
        return;
    }
    
    $content = file_get_contents($envFile);
    
    // Check if key exists
    if (strpos($content, $key . '=') !== false) {
        // Update existing key
        $content = preg_replace("/^{$key}\s*=\s*[^\s]+/m", "{$key}={$value}", $content);
    } else {
        // Add new key
        $content .= "\n{$key}={$value}";
    }
    
    file_put_contents($envFile, $content);
}

function clearLogFiles($logFiles) {
    foreach ($logFiles as $name => $path) {
        if (file_exists($path)) {
            file_put_contents($path, '');
            echo "<p>Cleared {$name}</p>";
        }
    }
}

function clearLaravelCache() {
    $commands = [
        'php artisan config:clear',
        'php artisan cache:clear',
        'php artisan view:clear',
        'php artisan route:clear'
    ];
    
    foreach ($commands as $command) {
        $output = shell_exec($command . ' 2>&1');
        echo "<p>Executed: {$command}</p>";
    }
}

function optimizeLogging($envFile) {
    $optimizations = [
        'LOG_CHANNEL' => 'daily',
        'LOG_LEVEL' => 'error',
        'LOG_DAYS' => '7',
        'SYNC_LOGGING_ENABLED' => 'false',
        'DEBUG_LOGGING_ENABLED' => 'false',
        'ERROR_LOGGING_ENABLED' => 'true',
        'SYNC_LOG_LEVEL' => 'error',
        'SYNC_LOG_DAYS' => '7',
        'DEBUG_LOG_DAYS' => '3',
        'ERROR_LOG_DAYS' => '30'
    ];
    
    foreach ($optimizations as $key => $value) {
        updateEnvFile($envFile, $key, $value);
    }
}
?>
