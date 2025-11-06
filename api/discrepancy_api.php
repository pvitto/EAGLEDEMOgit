<?php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

// 1. Verificar Autenticación y Rol
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'Digitador'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado para esta acción.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $task_id = $data['task_id'] ?? null;
    $resolution_note = $data['resolution_note'] ?? '';

    if (empty($task_id) || empty($resolution_note)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Se requiere el ID de la tarea y la nota de resolución.']);
        exit;
    }

    // Actualizamos la tarea específica que el digitador está resolviendo
    $stmt = $conn->prepare("UPDATE tasks SET status = 'Resuelta', resolution_note = ? WHERE id = ? AND assigned_to_user_id = ?");
    $stmt->bind_param("sii", $resolution_note, $task_id, $_SESSION['user_id']);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Marcar todas las otras tareas para la misma alerta como 'Canceladas' para que no le aparezcan a otros digitadores
            $get_alert_id_query = $conn->prepare("SELECT alert_id FROM tasks WHERE id = ?");
            $get_alert_id_query->bind_param("i", $task_id);
            $get_alert_id_query->execute();
            $alert_id_result = $get_alert_id_query->get_result()->fetch_assoc();
            $alert_id = $alert_id_result['alert_id'];
            
            if ($alert_id) {
                $conn->query("UPDATE tasks SET status = 'Cancelada' WHERE alert_id = $alert_id AND id != $task_id");
                $conn->query("UPDATE alerts SET status = 'Resuelta' WHERE id = $alert_id");
            }

            echo json_encode(['success' => true, 'message' => 'Caso de discrepancia resuelto y documentado.']);
        } else {
             echo json_encode(['success' => false, 'error' => 'No se pudo actualizar la tarea o ya estaba resuelta.']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al actualizar la tarea: ' . $stmt->error]);
    }
    $stmt->close();

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
}

$conn->close();
?>