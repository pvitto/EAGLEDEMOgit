<?php
// api/checkin_api.php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

// --- Seguridad: Verificar rol ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'Checkinero'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    $conn->close(); // Cerrar conexión
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$checkinero_id = $_SESSION['user_id'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // --- Validar JSON ---
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido en la solicitud: ' . json_last_error_msg()]);
        $conn->close();
        exit;
    }

    // --- Extraer y validar datos ---
    $invoice_number = isset($data['invoice_number']) ? trim($data['invoice_number']) : '';
    $seal_number = isset($data['seal_number']) ? trim($data['seal_number']) : '';
    $declared_value = isset($data['declared_value']) ? filter_var($data['declared_value'], FILTER_VALIDATE_FLOAT, ['options' => ['default' => 0]]) : 0;
    $fund_id = isset($data['fund_id']) ? filter_var($data['fund_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $client_id = isset($data['client_id']) ? filter_var($data['client_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $route_id = isset($data['route_id']) ? filter_var($data['route_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;

    // --- CORRECCIÓN: Se quitó check_in_id de aquí, ya no se edita ---

    if (empty($invoice_number) || empty($seal_number) || empty($declared_value) || empty($client_id) || empty($route_id) || empty($fund_id)) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'error' => 'Todos los campos (Planilla, Sello, Valor, Cliente, Ruta, Fondo) son requeridos.']);
        $conn->close();
        exit;
    }
    if ($declared_value <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El valor declarado debe ser mayor que cero.']);
        $conn->close();
        exit;
    }


    // --- LÓGICA DE VALIDACIÓN DE DUPLICADOS (Se mantiene) ---
    // Busca si ya existe esa Planilla O ese Sello en *cualquier* registro.
    $stmt_check = $conn->prepare("SELECT id FROM check_ins WHERE invoice_number = ? OR seal_number = ?");
    if (!$stmt_check) {
        http_response_code(500);
        error_log("Error preparando chequeo de duplicados: " . $conn->error);
        echo json_encode(['success' => false, 'error' => 'Error interno al verificar duplicados.']);
        $conn->close();
        exit;
    }
    $stmt_check->bind_param("ss", $invoice_number, $seal_number);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check && $result_check->num_rows > 0) {
        http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'error' => 'El número de Planilla o Sello ya existe. Verifique los datos.']);
        $stmt_check->close();
        $conn->close();
        exit;
    }
    $stmt_check->close();
    // --- FIN DE LA VALIDACIÓN ---


    // --- LÓGICA DE CREACIÓN (Ahora es la única) ---
    $stmt = $conn->prepare("INSERT INTO check_ins (invoice_number, seal_number, declared_value, fund_id, client_id, route_id, checkinero_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente')");
    $message = 'Check-in registrado con éxito.';
    $new_checkin_id = null; // Para guardar el ID del nuevo registro

    if (!$stmt) {
        http_response_code(500);
        error_log("Error preparando INSERT de check-in: " . $conn->error);
        echo json_encode(['success' => false, 'error' => 'Error interno al preparar la inserción.']);
        $conn->close();
        exit;
    }

    $stmt->bind_param("ssdiisi", $invoice_number, $seal_number, $declared_value, $fund_id, $client_id, $route_id, $checkinero_id);

    if ($stmt->execute()) {
        $new_checkin_id = $stmt->insert_id; // Obtener el ID del registro recién insertado
        // --- Devolver el ID en la respuesta ---
        echo json_encode(['success' => true, 'message' => $message, 'check_in_id' => $new_checkin_id]);
    } else {
        http_response_code(500);
        error_log("Error ejecutando INSERT de check-in: " . $stmt->error);
        echo json_encode(['success' => false, 'error' => 'Error al procesar la solicitud en la base de datos: ' . $stmt->error]);
    }
    $stmt->close();

} else {
    // --- Manejo GET (u otros métodos) ---
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Método no soportado. Use POST para crear check-ins.']);
}

$conn->close();
?>