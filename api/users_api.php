<?php
header('Content-Type: application/json');
require '../db_connection.php';
require '../config.php';

// Habilitar el reporte de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$response = ['success' => false, 'error' => 'Invalid request'];

if ($conn->connect_error) {
    $response['error'] = "Connection failed: " . $conn->connect_error;
    echo json_encode($response);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        // Manejar creación y actualización de usuarios
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $password = $_POST['password'] ?? '';

        // --- Validación ---
        if (empty($name) || empty($role) || empty($gender)) {
            throw new Exception("Los campos de nombre, rol y género son obligatorios.");
        }
        // Validar email solo si se proporciona
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("El formato del correo electrónico no es válido.");
        }

        if ($id > 0) {
            // --- Actualizar Usuario ---
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, gender = ?, password = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $name, $email, $role, $gender, $hashed_password, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, gender = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $email, $role, $gender, $id);
            }
            $action = "actualizado";
        } else {
            // --- Crear Usuario ---
            if (empty($password)) {
                throw new Exception("La contraseña es obligatoria para nuevos usuarios.");
            }
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, role, gender, password) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $name, $email, $role, $gender, $hashed_password);
            $action = "creado";
        }

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = "Usuario {$action} correctamente.";
            unset($response['error']);
        } else {
            if ($conn->errno == 1062) { // Código de error para entrada duplicada
                throw new Exception("Error: El correo electrónico '{$email}' ya está en uso.");
            } else {
                throw new Exception("Error al ejecutar la consulta: " . $stmt->error);
            }
        }
        $stmt->close();

    } elseif ($method === 'DELETE') {
        // Manejar eliminación de usuarios
        parse_str(file_get_contents("php://input"), $delete_vars);
        $id = $delete_vars['id'] ?? 0;

        if ($id > 0) {
            // Para mantener la integridad, podrías reasignar tareas o eliminarlas.
            // Aquí, por simplicidad, las eliminaremos.
            $conn->begin_transaction();
            try {
                // Eliminar recordatorios asociados
                $stmt_rem = $conn->prepare("DELETE FROM reminders WHERE user_id = ? OR created_by_user_id = ?");
                $stmt_rem->bind_param("ii", $id, $id);
                $stmt_rem->execute();
                $stmt_rem->close();

                // Eliminar tareas asociadas
                $stmt_tasks = $conn->prepare("DELETE FROM tasks WHERE assigned_to_user_id = ? OR created_by_user_id = ?");
                $stmt_tasks->bind_param("ii", $id, $id);
                $stmt_tasks->execute();
                $stmt_tasks->close();

                // Finalmente, eliminar el usuario
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $conn->commit();
                    $response['success'] = true;
                    $response['message'] = 'Usuario y sus registros asociados eliminados correctamente.';
                    unset($response['error']);
                } else {
                    throw new Exception("Error al eliminar el usuario: " . $stmt->error);
                }
                $stmt->close();

            } catch (Exception $e) {
                $conn->rollback();
                throw $e; // Re-lanzar la excepción para el bloque catch principal
            }
        } else {
            throw new Exception("ID de usuario no válido para eliminar.");
        }
    } elseif ($method === 'GET') {
        // Manejar la obtención de todos los usuarios
        $result = $conn->query("SELECT id, name, email, role, gender FROM users ORDER BY name ASC");
        if ($result) {
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $response['success'] = true;
            $response['users'] = $users;
            unset($response['error']);
        } else {
            throw new Exception("Error al obtener los usuarios: " . $conn->error);
        }
    } else {
        throw new Exception("Método no permitido.");
    }

} catch (Exception $e) {
    http_response_code(400); // Bad Request
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

$conn->close();
echo json_encode($response);
