<?php
// Herramienta de utilidad para crear hashes de contraseñas.
// No se usa directamente en la app, pero es útil para desarrollo.
if (isset($_GET['password'])) {
    $password = $_GET['password'];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    echo "Password: " . htmlspecialchars($password) . "<br>";
    echo "Hash: " . $hash;
} else {
    echo "Por favor, proporciona una contraseña en la URL. Ejemplo: /api/password_hasher.php?password=tu_contraseña";
}
?>
