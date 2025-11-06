<?php
require '../config.php';
require '../db_connection.php'; // Ajusta la ruta si es necesario
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$since_timestamp = isset($_GET['since']) ? intval($_GET['since']) : (time() - 30); // Revisa últimos 30 seg por defecto

$normalized_role = strtolower($current_user_role);
$allowed_roles = ['admin', 'digitador', 'digitadora'];

if (!in_array($normalized_role, $allowed_roles, true)) {
    echo json_encode([
        'success' => true,
        'alerts' => [],
        'timestamp' => time()
    ]);
    exit;
}

$new_alerts = [];
$user_filter = '';
if ($normalized_role !== 'admin') {
    // Filtro para alertas asignadas al usuario o a su grupo
    $escaped_role = $conn->real_escape_string($current_user_role);
    $user_filter = " AND (t.assigned_to_user_id = {$current_user_id} OR (t.assigned_to_group = '{$escaped_role}' AND (t.assigned_to_user_id IS NULL OR t.assigned_to_user_id = 0)))";
}

// Consulta para buscar alertas/tareas prioritarias NUEVAS (creadas después de 'since')
// Simplificado: Busca cualquier alerta/tarea prioritaria creada recientemente
// Podrías hacerlo más complejo guardando qué alertas ya vio el usuario
$sql = "
    SELECT
        a.id as alert_id, a.title, a.description, a.priority, a.created_at,
        t.id as task_id, t.title as task_title, t.instruction, t.priority as task_priority, t.created_at as task_created_at,
        t.assigned_to_group, t.assigned_to_user_id,
        ci.invoice_number
    FROM tasks t
    INNER JOIN alerts a ON t.alert_id = a.id
    INNER JOIN check_ins ci ON a.check_in_id = ci.id
    WHERE
      t.status = 'Pendiente' AND -- Solo pendientes
      ci.status = 'Discrepancia' AND -- Garantiza que la alerta sea por discrepancia
      (t.priority IN ('Critica', 'Alta') OR a.priority IN ('Critica', 'Alta')) AND -- Solo prioritarias
      UNIX_TIMESTAMP(COALESCE(a.created_at, t.created_at)) > ? -- Creadas después de la última revisión
      {$user_filter} -- Filtro de usuario/grupo
    ORDER BY COALESCE(a.created_at, t.created_at) DESC
    LIMIT 5 -- Limitar resultados por si acaso
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $since_timestamp);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Todas las filas corresponden a alertas de discrepancia
        $priority = $row['priority'] ?? $row['task_priority'];

        if ($priority === 'Critica' || $priority === 'Alta') {
            $new_alerts[] = [
                'id' => $row['alert_id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'priority' => $priority,
                'created_at' => $row['created_at'] ?? $row['task_created_at'],
                'type' => 'discrepancy_alert',
                'invoice_number' => $row['invoice_number'] ?? null
            ];
        }
    }
    $stmt->close();
} else {
     error_log("Error preparando consulta en realtime_alerts_api: " . $conn->error);
     // No enviar error al cliente necesariamente, solo loguear
}


$conn->close();

echo json_encode([
    'success' => true,
    'alerts' => $new_alerts,
    'timestamp' => time() // Devuelve el timestamp actual para la próxima consulta
]);
?>