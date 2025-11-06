<?php
// api/realtime/realtime_alerts_api.php

// (ini_set y error_reporting si los necesitas para depurar)
require dirname(__DIR__, 2) . '/config.php';

require dirname(__DIR__, 2) . '/db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { /* ... manejo no autenticado ... */ exit; }

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$since_timestamp = isset($_GET['since']) ? max(0, intval($_GET['since'])) : (time() - 60);
$since_datetime_string = date('Y-m-d H:i:s', $since_timestamp);

// --- AÑADIDO: Log para ver qué 'since' se está usando ---
error_log("Polling alerts since: " . $since_datetime_string);
// --- FIN AÑADIDO ---

$new_alerts = [];
$user_filter_sql = '';
if ($current_user_role !== 'Admin') { /* ... filtro usuario/grupo ... */ }

$sql = "
    SELECT
        a.id as alert_id, a.title as alert_title, a.description as alert_description, a.priority as alert_priority,
        t.id as task_id, t.title as task_title, t.instruction as task_instruction, t.priority as task_priority, t.created_at as task_created_at,
        t.assigned_to_group, t.assigned_to_user_id, t.type as task_type
    FROM tasks t
    LEFT JOIN alerts a ON t.alert_id = a.id
    WHERE
        t.status = 'Pendiente'
        AND ( (t.priority IN ('Critica', 'Alta')) OR (a.id IS NOT NULL AND a.priority IN ('Critica', 'Alta')) )
        AND t.created_at >= ? -- Solo tareas creadas DESPUÉS del último chequeo
        {$user_filter_sql}    -- Filtro de usuario/grupo (vacío para Admin)
    ORDER BY t.created_at DESC
    LIMIT 10
";

// --- AÑADIDO: Log para ver la consulta SQL completa (aproximada) ---
$debug_sql = str_replace('?', "'".$since_datetime_string."'", $sql); // Reemplaza '?' para debug
error_log("Executing SQL: " . $debug_sql);
// --- FIN AÑADIDO ---

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("s", $since_datetime_string);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        // ... (resto del while para procesar $new_alerts sin cambios) ...
        $priority_map = ['Critica' => 4, 'Alta' => 3, 'Media' => 2, 'Baja' => 1];
        $priority_names = ['Baja', 'Media', 'Alta', 'Critica'];

        while ($row = $result->fetch_assoc()) {
            $is_manual = empty($row['alert_id']);
            $task_prio_val = $priority_map[$row['task_priority'] ?? 'Media'] ?? 2;
            $alert_prio_val = $priority_map[$row['alert_priority'] ?? 'Baja'] ?? 1;
            $final_prio_val = max($task_prio_val, $alert_prio_val);
            $priority = $priority_names[$final_prio_val - 1] ?? 'Media';

            if ($priority === 'Critica' || $priority === 'Alta') {
                 // --- AÑADIDO: Log si se encuentra una alerta ---
                 error_log("Found high priority task/alert: ID " . $row['task_id']);
                 // --- FIN AÑADIDO ---
                $new_alerts[] = [ /* ... datos de la alerta ... */ ];
            }
        }
    } else { /* ... manejo error execute ... */ }
    $stmt->close();
} else { /* ... manejo error prepare ... */ }
$conn->close();

// --- AÑADIDO: Log final antes de enviar JSON ---
error_log("Returning JSON: " . json_encode(['success' => true, 'alerts' => $new_alerts, 'timestamp' => time()]));
// --- FIN AÑADIDO ---

echo json_encode([ /* ... JSON de respuesta ... */ ]);
?>