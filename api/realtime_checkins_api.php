<?php
// api/realtime_checkins_api.php
require '../config.php';
require '../db_connection.php'; // Ajusta la ruta si es necesario
header('Content-Type: application/json');

// Verificar sesión (importante para seguridad)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // No autorizado
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

// La misma consulta que usas en index.php para obtener los checkins iniciales
$checkins = [];
$checkins_result = $conn->query("
    SELECT ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, f.id as fund_id, f.name as fund_name,
           ci.created_at, c.name as client_name, c.id as client_id, r.name as route_name, r.id as route_id, u.name as checkinero_name,
           ci.status, ci.correction_count, ci.digitador_status
    FROM check_ins ci
    JOIN clients c ON ci.client_id = c.id
    JOIN routes r ON ci.route_id = r.id
    JOIN users u ON ci.checkinero_id = u.id
    LEFT JOIN funds f ON ci.fund_id = f.id
    WHERE ci.status IN ('Pendiente', 'Rechazado') OR ci.digitador_status IS NULL
    ORDER BY ci.correction_count DESC, ci.created_at DESC
");

if ($checkins_result) {
    while ($row = $checkins_result->fetch_assoc()) {
        $checkins[] = $row;
    }
} else {
    // Es mejor no enviar un error fatal aquí, solo un array vacío o un mensaje
    error_log("Error fetching checkins for realtime: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Error al obtener checkins', 'checkins' => []]);
    $conn->close();
    exit;
}

$conn->close();

echo json_encode(['success' => true, 'checkins' => $checkins]);
?>