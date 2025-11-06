<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>DEBUG\n";

// 1) DB
require __DIR__ . "/db_connection.php";
echo "DB OK\n";

// 2) Composer
$autoload = __DIR__ . "/vendor/autoload.php";
if (file_exists($autoload)) {
  require $autoload;
  echo "Autoload OK\n";
} else {
  echo "Falta vendor/autoload.php\n";
}

// 3) Version y extensiones
echo "PHP: " . PHP_VERSION . "\n";
echo "Ext mysqli: " . (extension_loaded('mysqli') ? "ON" : "OFF") . "\n";
echo "</pre>";
