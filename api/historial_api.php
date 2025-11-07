<?php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

// --- Seguridad: Solo Admin puede acceder a este historial ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

// --- Recibir y Validar Parámetros de Filtro ---
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;
$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

// --- Construir la Consulta SQL Base ---
$sql = "
    SELECT
        oc.created_at,
        ci.invoice_number,
        c.name AS client_name,
        u_op.name AS operator_name,
        u_dig.name AS digitador_name,
        oc.total_counted,
        CASE
            WHEN ci.digitador_status = 'Cerrado' THEN 'Cerrado'
            WHEN ci.status = 'Procesado' THEN 'Procesado'
            WHEN ci.status = 'Faltante' THEN 'Faltante'
            WHEN ci.status = 'Pendiente' THEN 'Pendiente'
            ELSE ci.status
        END AS final_status
    FROM operator_counts oc
    JOIN check_ins ci ON oc.check_in_id = ci.id
    JOIN clients c ON ci.client_id = c.id
    JOIN users u_op ON oc.operator_id = u_op.id
    LEFT JOIN users u_dig ON ci.closed_by_digitador_id = u_dig.id
";

// --- Añadir Filtros a la Consulta ---
$where_clauses = [];
$params = [];
$types = '';

if ($start_date) {
    $where_clauses[] = "DATE(oc.created_at) >= ?";
    $params[] = $start_date;
    $types .= 's';
}
if ($end_date) {
    $where_clauses[] = "DATE(oc.created_at) <= ?";
    $params[] = $end_date;
    $types .= 's';
}
if ($user_id) {
    // Filtrar si el usuario es el operador O el digitador que cerró
    $where_clauses[] = "(oc.operator_id = ? OR ci.closed_by_digitador_id = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
    $types .= 'ii';
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY oc.created_at DESC";

// --- Preparar y Ejecutar la Consulta ---
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al preparar la consulta: ' . $conn->error]);
    exit;
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}

$stmt->close();

// --- Calcular Estadísticas desde los Resultados ---
$stats = [
    'total_recaudado' => 0,
    'total_planillas' => count($history),
    'recaudo_por_operador' => [],
    'cierres_por_digitador' => [],
];

$operador_totals = [];
$digitador_totals = [];

foreach ($history as $row) {
    $stats['total_recaudado'] += (float)$row['total_counted'];

    // Estadísticas por Operador
    if (!empty($row['operator_name'])) {
        if (!isset($operador_totals[$row['operator_name']])) {
            $operador_totals[$row['operator_name']] = ['total' => 0, 'count' => 0];
        }
        $operador_totals[$row['operator_name']]['total'] += (float)$row['total_counted'];
        $operador_totals[$row['operator_name']]['count']++;
    }

    // Estadísticas por Digitador
    if (!empty($row['digitador_name'])) {
        if (!isset($digitador_totals[$row['digitador_name']])) {
            $digitador_totals[$row['digitador_name']] = ['total' => 0, 'count' => 0];
        }
        $digitador_totals[$row['digitador_name']]['total'] += (float)$row['total_counted'];
        $digitador_totals[$row['digitador_name']]['count']++;
    }
}

// Formatear para la salida
uasort($operador_totals, function($a, $b) {
    return $b['total'] <=> $a['total'];
});
uasort($digitador_totals, function($a, $b) {
    return $b['total'] <=> $a['total'];
});
$stats['recaudo_por_operador'] = $operador_totals;
$stats['cierres_por_digitador'] = $digitador_totals;


$conn->close();

// --- Devolver Resultados ---
echo json_encode(['success' => true, 'data' => $history, 'stats' => $stats]);
?>
