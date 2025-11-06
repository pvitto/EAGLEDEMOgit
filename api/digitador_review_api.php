<?php
// api/digitador_review_api.php
require '../config.php';
header('Content-Type: application/json');

require '../db_connection.php';

// Seguridad: Verificar que el usuario sea Digitador o Admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tienes permiso para realizar esta acción']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Validar que los datos necesarios fueron enviados
if (!isset($data['check_in_id'], $data['status'], $data['observations'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos inválidos. Faltan parámetros.']);
    exit;
}

$check_in_id = filter_var($data['check_in_id'], FILTER_VALIDATE_INT);
$status = $data['status']; // 'Conforme' o 'Rechazado'
$observations = trim($data['observations']);
$digitador_id = $_SESSION['user_id'];

if (empty($check_in_id) || !in_array($status, ['Conforme', 'Rechazado']) || empty($observations)) {
    echo json_encode(['success' => false, 'error' => 'El estado y las observaciones son requeridos.']);
    exit;
}

$conn->begin_transaction();

try {
    $sql = "";
    if ($status === 'Rechazado') {
        // CORRECCIÓN CLAVE:
        // Cuando se rechaza, SIEMPRE se establece el status principal a 'Rechazado'.
        // Esto asegura que la planilla vuelva al flujo del Checkinero para su corrección.
        $sql = "UPDATE check_ins
                SET status = 'Rechazado',
                    correction_count = correction_count + 1,
                    digitador_status = ?,
                    digitador_observations = ?,
                    closed_by_digitador_id = ?,
                    closed_by_digitador_at = NOW()
                WHERE id = ?"; // Se quita "AND digitador_status IS NULL" para permitir rechazar de nuevo si es necesario
    } else { // 'Conforme'
        $sql = "UPDATE check_ins
                SET digitador_status = ?,
                    digitador_observations = ?,
                    closed_by_digitador_id = ?,
                    closed_by_digitador_at = NOW()
                WHERE id = ? AND digitador_status IS NULL";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $status, $observations, $digitador_id, $check_in_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Revisión guardada correctamente.']);
    } else {
        throw new Exception('La planilla no pudo ser actualizada. Es posible que ya haya sido procesada.');
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(409); 
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>