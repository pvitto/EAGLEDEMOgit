<?php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $client_id = $_GET['client_id'] ?? null;
        if ($client_id) {
            // Obtener fondos para un cliente específico
            $stmt = $conn->prepare("SELECT id, name FROM funds WHERE client_id = ? ORDER BY name ASC");
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            // Obtener todos los fondos con info del cliente
            $query = "
                SELECT f.id, f.name, c.name as client_name, c.nit as client_nit 
                FROM funds f
                JOIN clients c ON f.client_id = c.id
                ORDER BY c.name, f.name ASC
            ";
            $result = $conn->query($query);
        }
        
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode($data);
        break;

    case 'POST':
        if ($_SESSION['user_role'] !== 'Admin') {
            echo json_encode(['success' => false, 'error' => 'Solo los administradores pueden crear fondos.']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? '';
        $client_id = $data['client_id'] ?? null;

        if (empty($name) || empty($client_id)) {
            echo json_encode(['success' => false, 'error' => 'Nombre del fondo y cliente son requeridos.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO funds (name, client_id) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $client_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al crear el fondo: ' . $stmt->error]);
        }
        $stmt->close();
        break;
}

$conn->close();
?>