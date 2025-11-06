<?php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

// Muestra todos los check-ins que ya pasaron por el operador (Procesado o con Discrepancia)
// y que aún no han sido cerrados por el digitador.
$query = "
    SELECT 
        ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, r.name as route_name, ci.created_at,
        u.name as checkinero_name, c.name as client_name, f.name as fund_name
    FROM check_ins ci
    JOIN routes r ON ci.route_id = r.id
    JOIN users u ON ci.checkinero_id = u.id
    JOIN clients c ON ci.client_id = c.id
    LEFT JOIN funds f ON ci.fund_id = f.id
    WHERE ci.status IN ('Procesado', 'Discrepancia') AND ci.digitador_status IS NULL
    ORDER BY ci.created_at DESC
";

$result = $conn->query($query);
$llegadas = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $llegadas[] = $row;
    }
}

echo json_encode($llegadas);
$conn->close();
?>