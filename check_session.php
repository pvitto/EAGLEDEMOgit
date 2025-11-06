<?php
// session_start() se llama ahora en login_handler.php y en index.php
// por lo que no es necesario volver a llamarlo aquí.

if (!isset($_SESSION['user_id'])) {
    // Si no hay una sesión de usuario activa, redirigir a la página de login
    header('Location: login.php');
    exit;
}
?>
