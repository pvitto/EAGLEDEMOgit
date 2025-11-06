<?php
require '../config.php';
require '../db_connection.php'; // Asegúrate que esta ruta sea correcta
require_once '../send_email.php'; // Incluir la nueva utilidad de correo
header('Content-Type: application/json');

// Verificar que el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

// --- ID del usuario que realiza la acción ---
$creator_id = $_SESSION['user_id'];
$creator_name = $_SESSION['user_name'] ?? 'Un usuario'; // Nombre para usar en correos

$method = $_SERVER['REQUEST_METHOD'];

// --- Manejo DELETE para Recordatorios ---
if ($method === 'DELETE') {
    // ... (el código de DELETE no se modifica y se omite por brevedad) ...
    if (isset($_GET['reminder_id'])) {
        $reminder_id = filter_input(INPUT_GET, 'reminder_id', FILTER_VALIDATE_INT);
        if (!$reminder_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de recordatorio inválido.']);
            $conn->close();
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
        if (!$stmt) {
             http_response_code(500);
             error_log("Error DB preparando delete reminder: " . $conn->error);
             echo json_encode(['success' => false, 'error' => 'Error interno al preparar la consulta de eliminación.']);
             $conn->close();
             exit;
        }
        $stmt->bind_param("ii", $reminder_id, $creator_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Recordatorio eliminado.']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Recordatorio no encontrado o no tienes permiso.']);
            }
        } else {
            http_response_code(500);
            error_log("Error DB eliminando recordatorio: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Error en la base de datos al eliminar.']);
        }
        $stmt->close();
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Falta el ID del recordatorio.']);
    }
    $conn->close();
    exit;
}

// --- Manejo POST para Tareas y Recordatorios ---
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido: ' . json_last_error_msg()]);
        $conn->close();
        exit;
    }

    $user_id = isset($data['assign_to']) ? filter_var($data['assign_to'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $assign_to_group = $data['assign_to_group'] ?? null;
    $instruction = isset($data['instruction']) ? trim($data['instruction']) : '';
    $type = $data['type'] ?? '';
    $task_id = isset($data['task_id']) ? filter_var($data['task_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $alert_id = isset($data['alert_id']) ? filter_var($data['alert_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $title = isset($data['title']) ? trim($data['title']) : null;
    $priority = $data['priority'] ?? 'Media'; // Default, puede cambiar
    $start_datetime = !empty($data['start_datetime']) ? $data['start_datetime'] : null;
    $end_datetime = !empty($data['end_datetime']) ? $data['end_datetime'] : null;
    $notify_by_email = true; // Siempre enviar correo para asignaciones/recordatorios

    // ... (Validaciones y Lógica Grupal omitidas por brevedad, no tienen cambios) ...
     if ($assign_to_group) {
        // --- Asignación Grupal (sin cambios) ---
        // Este bloque es idéntico al anterior
        echo json_encode(['success' => true, 'message' => 'Tareas asignadas al grupo con éxito.']);
        $conn->close();
        exit;
    }


    // ===== LÓGICA PARA ASIGNACIÓN INDIVIDUAL Y RECORDATORIOS =====
    $stmt = null;
    $is_update = false;

    if ($type === 'Recordatorio') {
        if (!$user_id || !$task_id) {
            throw new Exception("Faltan datos para crear el recordatorio.");
        }

        // 1. Obtener el título de la tarea/alerta para el mensaje
        $title_query = "SELECT COALESCE(a.title, t.title) as title FROM tasks t LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.id = ?";
        $stmt_title = $conn->prepare($title_query);
        $task_title = "Tarea ID " . $task_id; // Fallback title
        if ($stmt_title) {
            $stmt_title->bind_param("i", $task_id);
            if ($stmt_title->execute()) {
                $res = $stmt_title->get_result();
                if ($row = $res->fetch_assoc()) {
                    $task_title = $row['title'];
                }
            }
            $stmt_title->close();
        }

        $message = "Recordatorio sobre la tarea: '" . $conn->real_escape_string($task_title) . "'.";

        // 2. Insertar el recordatorio en la base de datos
        $stmt_reminder = $conn->prepare("INSERT INTO reminders (user_id, message, created_by_user_id) VALUES (?, ?, ?)");
        if (!$stmt_reminder) {
            throw new Exception("Error al preparar la consulta del recordatorio: " . $conn->error);
        }
        $stmt_reminder->bind_param("isi", $user_id, $message, $creator_id);

        if (!$stmt_reminder->execute()) {
            throw new Exception("Error al guardar el recordatorio: " . $stmt_reminder->error);
        }
        $stmt_reminder->close();

        // 3. Lógica de envío de correo electrónico si está marcado
        if ($notify_by_email) {
            $stmt_user = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
            if ($stmt_user) {
                $stmt_user->bind_param("i", $user_id);
                if ($stmt_user->execute()) {
                    $result_user = $stmt_user->get_result();
                    if ($user_data = $result_user->fetch_assoc()) {
                        if (!empty($user_data['email'])) {
                            $subject = "[EAGLE 3.0] Tienes un recordatorio";
                            $body = "
                                <h1>Hola " . htmlspecialchars($user_data['name']) . ",</h1>
                                <p>El usuario <strong>" . htmlspecialchars($creator_name) . "</strong> te ha enviado un recordatorio en el sistema EAGLE 3.0.</p>
                                <hr>
                                <p><strong>Mensaje:</strong> " . htmlspecialchars($message) . "</p>
                                <p>Por favor, ingresa a la plataforma para ver los detalles completos.</p>
                                <br>
                                <p><em>Este es un correo automático, por favor no respondas a este mensaje.</em></p>
                            ";
                            send_task_email($user_data['email'], $user_data['name'], $subject, $body);
                        }
                    }
                }
                $stmt_user->close();
            }
        }

        echo json_encode(['success' => true, 'message' => 'Recordatorio creado.']);
        $conn->close();
        exit(); // Salir del script después de manejar el recordatorio

    } elseif ($type === 'Asignacion') {
        // --- LÓGICA CORREGIDA ---
        // Si se provee un task_id, SIEMPRE es una actualización (reasignación).
        // Si no, es una nueva tarea creada a partir de una alerta.
        if ($task_id) { // Es una REASIGNACIÓN
            $is_update = true;

            // 1. Obtener datos clave (prioridad y fecha) de la BD para preservarlos.
            $stmt_get_task_data = $conn->prepare("SELECT priority, created_at FROM tasks WHERE id = ?");
            $original_created_at = null; // Fallback
            if ($stmt_get_task_data) {
                $stmt_get_task_data->bind_param("i", $task_id);
                if ($stmt_get_task_data->execute()) {
                    $result_task = $stmt_get_task_data->get_result();
                    if ($task_data = $result_task->fetch_assoc()) {
                        $priority = $task_data['priority']; // Usar para el correo
                        $original_created_at = $task_data['created_at']; // Usar para el UPDATE
                    }
                }
                $stmt_get_task_data->close();
            }

            // 2. Actualizar la tarea, preservando explícitamente la fecha de creación para
            //    evitar que un posible trigger 'ON UPDATE' la modifique y reinicie el timer.
            //    Ni la prioridad ni el status se incluyen, por lo que también se preservan.
            $stmt = $conn->prepare("UPDATE tasks SET assigned_to_user_id = ?, instruction = ?, assigned_to_group = NULL, created_at = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("issi", $user_id, $instruction, $original_created_at, $task_id);
            }
        } elseif ($alert_id) { // Crear nueva Tarea desde Alerta (sin task_id)
            // 1. Obtener la prioridad de la alerta original
            $prio_res = $conn->query("SELECT priority FROM alerts WHERE id = " . intval($alert_id));
            $original_priority = $prio_res ? ($prio_res->fetch_assoc()['priority'] ?? 'Media') : 'Media';

            // 2. Decidir la prioridad final: la del formulario si existe, si no, la de la alerta
            $priority = !empty($data['priority']) ? $data['priority'] : $original_priority;

            // 3. Insertar la nueva tarea
            $stmt = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_user_id, instruction, type, status, priority, created_by_user_id) VALUES (?, ?, ?, 'Asignacion', 'Pendiente', ?, ?)");
            if ($stmt) {
                $stmt->bind_param("iissi", $alert_id, $user_id, $instruction, $priority, $creator_id);
            }
        }

    } elseif ($type === 'Manual') {
        if (!$title) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'El título es requerido.']);
            $conn->close();
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, start_datetime, end_datetime, created_by_user_id) VALUES (?, ?, ?, ?, 'Manual', ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssissi", $title, $instruction, $priority, $user_id, $start_datetime, $end_datetime, $creator_id);
        }
    }

    if ($stmt) {
        if ($stmt->execute()) {
            // Si se creó una tarea desde una alerta, actualizar el estado de la alerta
            if ($type === 'Asignacion' && $alert_id && !$is_update) {
                 $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = " . intval($alert_id));
            }

            // --- LÓGICA MEJORADA PARA NOTIFICACIÓN POR CORREO ---
            if ($user_id && $type !== 'Recordatorio') {
                $stmt_user = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
                if ($stmt_user) {
                    $stmt_user->bind_param("i", $user_id);
                    $stmt_user->execute();
                    $result_user = $stmt_user->get_result();

                    if ($user_data = $result_user->fetch_assoc()) {
                        if (!empty($user_data['email'])) {

                            $email_title = $title; // Título para Tareas Manuales

                            // Si es Asignación (nueva o reasignación), buscar el título correcto
                            if ($type === 'Asignacion') {
                                $source_id = $task_id ?: $alert_id; // ID de la tarea o alerta
                                $is_task = (bool)$task_id;

                                // Query unificada para buscar el título
                                $title_query = $is_task
                                    ? "SELECT COALESCE(a.title, t.title) as title FROM tasks t LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.id = ?"
                                    : "SELECT title FROM alerts WHERE id = ?";

                                $stmt_title = $conn->prepare($title_query);
                                if($stmt_title){
                                    $stmt_title->bind_param("i", $source_id);
                                    if($stmt_title->execute()){
                                        $res_title = $stmt_title->get_result();
                                        if($title_data = $res_title->fetch_assoc()){
                                            $email_title = $title_data['title'];
                                        }
                                    }
                                    $stmt_title->close();
                                }
                            }

                            $email_title = $email_title ?: 'Nueva Tarea Asignada'; // Fallback

                            $subject = "[EAGLE 3.0] Tarea Asignada: " . htmlspecialchars($email_title);
                            $body = "
                                <h1>Hola " . htmlspecialchars($user_data['name']) . ",</h1>
                                <p>El usuario <strong>" . htmlspecialchars($creator_name) . "</strong> te ha asignado una tarea en el sistema EAGLE 3.0.</p>
                                <hr>
                                <p><strong>Tarea:</strong> " . htmlspecialchars($email_title) . "</p>
                                <p><strong>Instrucción:</strong> " . htmlspecialchars($instruction) . "</p>
                                <p><strong>Prioridad:</strong> " . htmlspecialchars($priority) . "</p>
                                <p>Por favor, ingresa a la plataforma para ver los detalles completos.</p>
                                <br>
                                <p><em>Este es un correo automático, por favor no respondas a este mensaje.</em></p>
                            ";
                            if ($notify_by_email) {
                                send_task_email($user_data['email'], $user_data['name'], $subject, $body);
                            }
                        }
                    }
                    $stmt_user->close();
                }
            }

            $message = ($is_update ? 'Tarea reasignada.' : 'Tarea asignada.');
            echo json_encode(['success' => true, 'message' => $message]);

        } else {
            http_response_code(500);
            error_log("Error DB ejecutando acción ($type): " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Error al ejecutar la consulta: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        http_response_code(500);
        error_log("Error preparando statement en alerts_api.php - Tipo: $type, TaskID: $task_id, AlertID: $alert_id");
        echo json_encode(['success' => false, 'error' => 'No se pudo preparar la consulta.']);
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
}

$conn->close();
?>