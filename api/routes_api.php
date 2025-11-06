<?php
require '../config.php';
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $result = $conn->query("SELECT id, name, description, created_at FROM routes ORDER BY name ASC");
        $routes = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $routes[] = $row;
            }
        }
        echo json_encode($routes);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? '';
        $description = $data['description'] ?? null;

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'El nombre de la ruta es requerido.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO routes (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $description);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al crear la ruta: ' . $stmt->error]);
        }
        $stmt->close();
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
        break;
}

$conn->close();
?>