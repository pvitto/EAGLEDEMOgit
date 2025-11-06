<?php
require '../config.php';
// La ruta correcta para acceder a la conexión desde la carpeta /api/
require '../db_connection.php'; // Asegúrate que esta ruta sea correcta

// 1. Verificar Autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $task_id = $data['task_id'] ?? null;
    $resolution_note = $data['resolution_note'] ?? ''; // Captura la nota de resolución
    // Capturamos el ID del usuario que está realizando la acción desde la sesión
    $completing_user_id = $_SESSION['user_id'];

    if (!$task_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Falta el ID de la tarea.']);
        exit;
    }

     if (empty(trim($resolution_note))) {
         http_response_code(400);
         echo json_encode(['success' => false, 'error' => 'La observación de cierre es obligatoria.']);
         exit;
     }


    // Iniciar transacción para asegurar que todo se complete o nada
    $conn->begin_transaction();

    // --- LÓGICA MODIFICADA PARA MANEJAR COMPLETADO GRUPAL ---
    try {
        // 1. Marcar la tarea específica como completada, registrar quién la completó y la nota
        $stmt_task_complete = $conn->prepare("UPDATE tasks SET completed_at = NOW(), status = 'Completada', completed_by_user_id = ?, resolution_note = ? WHERE id = ? AND status = 'Pendiente'");
        $stmt_task_complete->bind_param("isi", $completing_user_id, $resolution_note, $task_id);
        $stmt_task_complete->execute();
        $affected_rows = $stmt_task_complete->affected_rows; // Guardamos si se afectó alguna fila
        $stmt_task_complete->close();

        // Si no se actualizó ninguna fila (quizás ya estaba completada), salimos temprano.
        if ($affected_rows === 0) {
             $conn->commit(); // Confirmamos aunque no se haya hecho nada nuevo
             echo json_encode(['success' => true, 'message' => 'La tarea ya estaba completada.']);
             $conn->close();
             exit;
        }

        // 2. Averiguar si esta tarea estaba ligada a una alerta
        $stmt_find_alert = $conn->prepare("SELECT alert_id FROM tasks WHERE id = ?");
        $stmt_find_alert->bind_param("i", $task_id);
        $stmt_find_alert->execute();
        $result = $stmt_find_alert->get_result();
        $task_data = $result->fetch_assoc();
        $alert_id = $task_data['alert_id'] ?? null;
        $stmt_find_alert->close();

        // 3. Si estaba ligada a una alerta, resolvemos la alerta y cancelamos las otras tareas asociadas
        if ($alert_id) {
            // Marcar la alerta principal como Resuelta
            $stmt_alert = $conn->prepare("UPDATE alerts SET status = 'Resuelta' WHERE id = ?");
            $stmt_alert->bind_param("i", $alert_id);
            $stmt_alert->execute();
            $stmt_alert->close();

            // Marcar TODAS las OTRAS tareas pendientes asociadas a esa misma alerta como Canceladas
            $stmt_cancel_others = $conn->prepare("UPDATE tasks SET status = 'Cancelada' WHERE alert_id = ? AND id != ? AND status = 'Pendiente'");
            $stmt_cancel_others->bind_param("ii", $alert_id, $task_id);
            $stmt_cancel_others->execute();
            $stmt_cancel_others->close();
        }
        // Nota: Las tareas manuales que no están ligadas a una alerta simplemente se marcan como completadas individualmente.

        // Si todo salió bien, confirmar la transacción
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Tarea completada con éxito.']);

    } catch (mysqli_sql_exception $exception) {
        // Si algo falla, revertir todo
        $conn->rollback();
        http_response_code(500);
        // Damos un mensaje más específico si es posible
        error_log("Error en task_api.php: " . $exception->getMessage()); // Loguea el error real
        echo json_encode(['success' => false, 'error' => 'Error al procesar la solicitud en la base de datos.']);
    }
    // --- FIN LÓGICA MODIFICADA ---

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
}

$conn->close();
?>