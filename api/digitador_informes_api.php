<?php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$action = $_GET['action'] ?? 'list_closed_funds';

if ($action === 'list_closed_funds') {
    // MODIFICADO: Agrupar por Fondo Y por DÍA de cierre
    $query = "
        SELECT 
            f.id, 
            f.name as fund_name, 
            c.name as client_name,
            DATE(ci.closed_by_digitador_at) as close_date
        FROM check_ins ci
        JOIN funds f ON ci.fund_id = f.id
        JOIN clients c ON f.client_id = c.id
        WHERE ci.digitador_status = 'Cerrado'
        GROUP BY f.id, f.name, c.name, DATE(ci.closed_by_digitador_at)
        ORDER BY close_date DESC, f.name ASC
    ";
    $result = $conn->query($query);
    $funds = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $funds[] = $row;
        }
    }
    echo json_encode($funds);
    
} elseif ($action === 'get_report_details' && isset($_GET['fund_id']) && isset($_GET['close_date'])) {
    // MODIFICADO: Aceptar y validar fund_id Y close_date
    $fund_id = intval($_GET['fund_id']);
    $close_date = $_GET['close_date'];

    // Validar formato de fecha YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $close_date)) {
         http_response_code(400);
         echo json_encode(['success' => false, 'error' => 'Formato de fecha inválido. Use YYYY-MM-DD.']);
         exit;
    }

    $query = "
        SELECT 
            ci.invoice_number as planilla, 
            ci.seal_number as sello, 
            ci.declared_value,
            oc.total_counted as total, 
            oc.discrepancy,
            oc.bills_100k, oc.bills_50k, oc.bills_20k, oc.bills_10k, oc.bills_5k, oc.bills_2k, oc.coins,
            c.name as cliente,
            u_op.name as operador,
            u_dig.name as digitador
        FROM check_ins ci
        LEFT JOIN (
            SELECT a.*
            FROM operator_counts a
            INNER JOIN (
                SELECT check_in_id, MAX(id) as max_id
                FROM operator_counts
                GROUP BY check_in_id
            ) b ON a.id = b.max_id
        ) oc ON ci.id = oc.check_in_id
        LEFT JOIN clients c ON ci.client_id = c.id
        LEFT JOIN users u_op ON oc.operator_id = u_op.id
        LEFT JOIN users u_dig ON ci.closed_by_digitador_id = u_dig.id
        WHERE ci.fund_id = ? 
          AND ci.digitador_status = 'Cerrado'
          AND DATE(ci.closed_by_digitador_at) = ? -- <-- LA LÍNEA CLAVE
        ORDER BY ci.invoice_number ASC
    ";
    
    $stmt = $conn->prepare($query);
    // MODIFICADO: bind_param con "is" (integer, string)
    $stmt->bind_param("is", $fund_id, $close_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $planillas = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $planillas[] = $row;
        }
    }
    echo json_encode($planillas);
    $stmt->close();
} else {
    // Error si faltan parámetros
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Acción no válida o faltan parámetros (fund_id, close_date).']);
}

$conn->close();
?>