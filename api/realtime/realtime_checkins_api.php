<?php
// api/realtime/realtime_checkins_api.php
require dirname(__DIR__, 2) . '/config.php';
// --- CORREGIDO: Ruta para subir dos niveles ---
require dirname(__DIR__, 2) . '/db_connection.php';
header('Content-Type: application/json');

// Verificar sesión (importante para seguridad)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // No autorizado
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

// La misma consulta que usas en index.php para obtener los checkins iniciales
$checkins = [];
// --- CORRECCIÓN: Filtrar por los estados relevantes para tiempo real ---
// Usualmente, solo quieres ver los que están pendientes o recién cambiaron.
// Ajusta 'Pendiente', 'Rechazado' si necesitas ver otros estados en tiempo real aquí.
$checkins_result = $conn->query("
    SELECT ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, f.id as fund_id, f.name as fund_name,
           ci.created_at, c.name as client_name, c.id as client_id, r.name as route_name, r.id as route_id, u.name as checkinero_name,
           ci.status, ci.correction_count, ci.digitador_status
    FROM check_ins ci
    JOIN clients c ON ci.client_id = c.id
    JOIN routes r ON ci.route_id = r.id
    JOIN users u ON ci.checkinero_id = u.id
    LEFT JOIN funds f ON ci.fund_id = f.id
    WHERE ci.status IN ('Pendiente', 'Rechazado', 'Procesado', 'Discrepancia') -- O los estados que necesites actualizar dinámicamente
    -- Podrías añadir un filtro de tiempo si solo quieres los más recientes
    -- AND ci.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ORDER BY ci.created_at DESC -- O el orden que prefieras
");

if ($checkins_result) {
    while ($row = $checkins_result->fetch_assoc()) {
        $checkins[] = $row;
    }
} else {
    // Es mejor no enviar un error fatal aquí, solo un array vacío o un mensaje
    error_log("Error fetching checkins for realtime: " . $conn->error);
    // Cambiado 'success' a false para indicar un problema, pero aún devolver 'checkins' vacío
    echo json_encode(['success' => false, 'error' => 'Error al obtener checkins', 'checkins' => []]);
    $conn->close();
    exit;
}

$conn->close();

echo json_encode(['success' => true, 'checkins' => $checkins]);
?>