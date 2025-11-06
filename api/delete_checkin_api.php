<?php
// api/delete_checkin_api.php
require '../config.php';
header('Content-Type: application/json');
require '../db_connection.php';

// 1. Verificar Autenticación y Rol de Administrador
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
$check_in_id = $data['check_in_id'] ?? null;

if (empty($check_in_id) || !filter_var($check_in_id, FILTER_VALIDATE_INT)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de planilla inválido.']);
    exit;
}

// Iniciar una transacción para garantizar la integridad de los datos
$conn->begin_transaction();

try {
    // Para mantener la base de datos limpia, eliminamos todos los registros relacionados en orden.
    // Las restricciones FOREIGN KEY con ON DELETE CASCADE ayudarán, pero lo hacemos explícito para mayor seguridad.

    // 1. Eliminar conteos del operador asociados
    $stmt1 = $conn->prepare("DELETE FROM operator_counts WHERE check_in_id = ?");
    $stmt1->bind_param("i", $check_in_id);
    $stmt1->execute();
    $stmt1->close();

    // 2. Eliminar alertas y tareas asociadas (si las hay)
    $stmt2 = $conn->prepare("DELETE FROM alerts WHERE check_in_id = ?");
    $stmt2->bind_param("i", $check_in_id);
    $stmt2->execute();
    $stmt2->close();
    // Las tareas se borran en cascada si la configuración de la BD es correcta (ON DELETE CASCADE)

    // 3. Finalmente, eliminar el check-in principal
    $stmt3 = $conn->prepare("DELETE FROM check_ins WHERE id = ?");
    $stmt3->bind_param("i", $check_in_id);
    $stmt3->execute();

    if ($stmt3->affected_rows > 0) {
        // Si se eliminó al menos una fila, confirmamos la transacción
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Planilla y todos sus registros asociados han sido eliminados.']);
    } else {
        throw new Exception('La planilla no fue encontrada o ya había sido eliminada.');
    }
    $stmt3->close();

} catch (Exception $e) {
    // Si algo falla, revertimos todos los cambios
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $e->getMessage()]);
}

$conn->close();
?>