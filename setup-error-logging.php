<?php
/**
 * Setup Error Logging System
 * Run this file once to create error_logs table and fix upload directories
 */

require_once 'config/database.php';

echo "<h2>Setting up Error Logging System...</h2>";

// Create error_logs table
try {
    $sql = "
    CREATE TABLE IF NOT EXISTS error_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        file VARCHAR(255),
        line INT,
        user_id INT,
        context JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type),
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $db->exec($sql);
    echo "<p style='color: green;'>✓ error_logs table created successfully</p>";
} catch (Exception $e) {
    echo "<p style='color: orange;'>⚠ error_logs table: " . $e->getMessage() . "</p>";
}

// Create logs directory
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    if (mkdir($logsDir, 0755, true)) {
        echo "<p style='color: green;'>✓ logs directory created</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to create logs directory</p>";
    }
} else {
    echo "<p style='color: green;'>✓ logs directory exists</p>";
}

// Create .htaccess for logs directory
$logsHtaccess = $logsDir . '/.htaccess';
if (!file_exists($logsHtaccess)) {
    $htaccessContent = "# Deny all access to log files\nOrder deny,allow\nDeny from all\n";
    if (file_put_contents($logsHtaccess, $htaccessContent)) {
        echo "<p style='color: green;'>✓ logs .htaccess created</p>";
    }
}

// Fix uploads directory permissions
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    if (mkdir($uploadsDir, 0755, true)) {
        echo "<p style='color: green;'>✓ uploads directory created</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to create uploads directory</p>";
    }
} else {
    echo "<p style='color: green;'>✓ uploads directory exists</p>";
}

// Check and fix uploads directory permissions
if (is_writable($uploadsDir)) {
    echo "<p style='color: green;'>✓ uploads directory is writable</p>";
} else {
    if (chmod($uploadsDir, 0755)) {
        echo "<p style='color: green;'>✓ uploads directory permissions fixed</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to fix uploads directory permissions. Please run: chmod 755 uploads/</p>";
    }
}

// Create subdirectories for uploads
$uploadSubdirs = ['documents', 'profile_pictures', 'temp'];
foreach ($uploadSubdirs as $subdir) {
    $path = $uploadsDir . '/' . $subdir;
    if (!is_dir($path)) {
        if (mkdir($path, 0755, true)) {
            echo "<p style='color: green;'>✓ Created uploads/{$subdir}</p>";
        }
    }
}

// Test file upload capability
$testFile = $uploadsDir . '/test_write.txt';
if (file_put_contents($testFile, 'test')) {
    unlink($testFile);
    echo "<p style='color: green;'>✓ File upload test successful</p>";
} else {
    echo "<p style='color: red;'>✗ Cannot write to uploads directory</p>";
}

// Check PHP upload settings
echo "<h3>PHP Upload Configuration:</h3>";
echo "<ul>";
echo "<li>upload_max_filesize: " . ini_get('upload_max_filesize') . "</li>";
echo "<li>post_max_size: " . ini_get('post_max_size') . "</li>";
echo "<li>max_file_uploads: " . ini_get('max_file_uploads') . "</li>";
echo "<li>memory_limit: " . ini_get('memory_limit') . "</li>";
echo "</ul>";

if (ini_get('file_uploads') != 1) {
    echo "<p style='color: red;'>✗ File uploads are DISABLED in PHP configuration</p>";
} else {
    echo "<p style='color: green;'>✓ File uploads are enabled</p>";
}

echo "<h2>Setup Complete!</h2>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Delete this file (setup-error-logging.php) for security</li>";
echo "<li>Check the admin panel for the new Error Logs viewer</li>";
echo "<li>Test document upload functionality</li>";
echo "</ol>";
?>
