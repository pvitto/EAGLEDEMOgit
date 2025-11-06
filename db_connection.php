<?php
// Configuración de la conexión a la base de datos para XAMPP
$servername = "localhost"; // El servidor de la base de datos es 'localhost' (usualmente en el puerto 3306 por defecto)
$username = "imancini9_paoloman";        // Usuario por defecto en XAMPP
$password = "Pman!cini1";            // Contraseña por defecto en XAMPP es vacía
$dbname = "imancini9_eagle_3_db";    // El nombre de la base de datos que creamos

// Crear la conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("❌ Error de conexión: " . $conn->connect_error);
}


// Verificar la conexión
/*if ($conn->connect_error) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => "Conexión a la base de datos fallida: " . $conn->connect_error]);
  exit();
}*/

// Establecer el charset a UTF-8 para soportar caracteres especiales
$conn->set_charset("utf8mb4");

$conn->query("SET SESSION sql_mode = (SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");


// --- ¡NUEVO CÓDIGO DE ZONA HORARIA! ---

// 1. Obtiene la zona horaria actual de PHP (definida en config.php, ej: 'Australia/Sydney')
$php_timezone_name = date_default_timezone_get();

// 2. Crea un objeto DateTime para esa zona horaria
$datetime = new DateTime("now", new DateTimeZone($php_timezone_name));

// 3. Obtiene el desfase UTC en formato +/-HH:MM (ej: '+11:00')
$utc_offset = $datetime->format('P');

// 4. Le dice a MySQL que use ESE desfase numérico
if ($utc_offset) {
    $conn->query("SET time_zone = '" . $conn->real_escape_string($utc_offset) . "'");
}
// --- FIN DEL NUEVO CÓDIGO ---
?>