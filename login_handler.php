<?php
require 'config.php';
require 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        header('Location: login.php?error=Email y contraseña son requeridos');
        exit;
    }

    // *** MODIFICADO: Incluir 'gender' en la consulta ***
    $stmt = $conn->prepare("SELECT id, name, email, role, password, gender FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Comparación simple de contraseñas (INSEGURO para producción)
        if ($password === $user['password']) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            // *** NUEVO: Guardar género en la sesión ***
            $_SESSION['user_gender'] = $user['gender'];
            header('Location: index.php');
            exit;
        } else {
            header('Location: login.php?error=Contraseña incorrecta');
            exit;
        }
    } else {
        header('Location: login.php?error=Usuario no encontrado');
        exit;
    }

    $stmt->close();
    $conn->close();
}
?>