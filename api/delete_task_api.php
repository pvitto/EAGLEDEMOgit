<?php
// api/delete_task_api.php
require '../config.php';
header('Content-Type: application/json');
require '../db_connection.php';

// 1. Seguridad: Solo el Admin puede eliminar tareas.
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado para esta acción.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$task_id = $data['task_id'] ?? null;

if (empty($task_id) || !filter_var($task_id, FILTER_VALIDATE_INT)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de tarea inválido.']);
    exit;
}

// 2. Ejecutar la eliminación
$stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
$stmt->bind_param("i", $task_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'La tarea ha sido eliminada del historial.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'La tarea no fue encontrada o ya había sido eliminada.']);
    }
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en la base de datos.']);
}

$stmt->close();
$conn->close();
?>