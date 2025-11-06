

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/check_session.php';




// --- Cargar datos iniciales ---
$all_users = [];
$users_result = $conn->query("SELECT id, name, role, email, gender FROM users ORDER BY name ASC");
if ($users_result) { while ($row = $users_result->fetch_assoc()) { $all_users[] = $row; } }
$admin_users_list = ($_SESSION['user_role'] === 'Admin') ? $all_users : [];

// --- Lógica de Usuario y Rol ---
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$current_user_gender = $_SESSION['user_gender'] ?? null;

function getRoleDisplayName($role, $gender) {
    if ($gender === 'F') {
        switch ($role) {
            case 'Digitador': return 'Digitadora';
            case 'Operador': return 'Operadora';
            case 'Checkinero': return 'Checkinera';
            default: return $role;
        }
    }
    return $role;
}
$displayRole = getRoleDisplayName($current_user_role, $current_user_gender);

// Clases CSS para roles
$role_color_class = 'bg-gray-200 text-gray-800'; // Color del badge interno
$role_nav_class = 'nav-admin'; // Clase para la navegación
$role_container_border_class = 'border-gray-400'; // Borde del contenedor de saludo (default)
$role_container_bg_class = 'bg-gray-50'; // Fondo del contenedor de saludo (default)

switch ($current_user_role) {
    case 'Admin':
        $role_color_class = 'bg-red-200 text-red-800'; $role_nav_class = 'nav-admin';
        $role_container_border_class = 'border-red-400'; $role_container_bg_class = 'bg-red-50'; break;
    case 'Digitador':
        $role_color_class = 'bg-blue-200 text-blue-800'; $role_nav_class = 'nav-digitador';
        $role_container_border_class = 'border-blue-400'; $role_container_bg_class = 'bg-blue-50'; break;
    case 'Operador':
        $role_color_class = 'bg-yellow-200 text-yellow-800'; $role_nav_class = 'nav-operador';
        $role_container_border_class = 'border-yellow-400'; $role_container_bg_class = 'bg-yellow-50'; break;
    case 'Checkinero':
        $role_color_class = 'bg-green-200 text-green-800'; $role_nav_class = 'nav-checkinero';
        $role_container_border_class = 'border-green-400'; $role_container_bg_class = 'bg-green-50'; break;
}

// --- LÓGICA DE ALERTAS Y TAREAS PENDIENTES ---
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
// --- LÓGICA DE ALERTAS Y TAREAS PENDIENTES ---
$all_pending_items = [];

// --- FILTRO PARA TAREAS MANUALES ---
// --- FILTRO PARA TAREAS MANUALES ---
$task_filter = '';
if ($current_user_role !== 'Admin') {
    $escaped_role = $current_user_role; // <-- Simplemente usa la variable de sesión
    // Filtro solo para tareas: asignadas a mí O a mi grupo
    $task_filter = " AND (
        t.assigned_to_user_id = {$current_user_id} 
        OR (t.assigned_to_group = '{$escaped_role}' AND t.assigned_to_user_id IS NULL)
    )";
}

// --- FILTRO PARA ALERTAS (COMBINA LÓGICA NUEVA Y ESTABLE) ---
$alert_filter = $task_filter; // Empezamos con el filtro de tareas
if ($current_user_role === 'Digitador') {
$escaped_role = $current_user_role; // <-- Simplemente usa la variable de sesión    // Lógica especial de la versión estable:
    // Muestra si la TAREA es para mí/mi grupo O si la ALERTA es para mi rol
    $alert_filter = " AND (
        (t.assigned_to_user_id = {$current_user_id} OR (t.assigned_to_group = '{$escaped_role}' AND t.assigned_to_user_id IS NULL))
        OR a.suggested_role = '{$escaped_role}'
    )";
}
// Para Admin, $alert_filter sigue siendo = '' (correcto)


// Cargar Alertas Pendientes (agrupadas por alerta)
$alerts_sql = "
SELECT a.*,
       MIN(t.id) AS task_id,
       MIN(t.status) AS task_status,
       COALESCE(MAX(t.assigned_to_group), a.suggested_role) AS assigned_to_group,       MAX(CASE WHEN t.assigned_to_user_id = {$current_user_id} THEN t.id ELSE NULL END) as user_task_id,
       GROUP_CONCAT(DISTINCT CASE WHEN t.assigned_to_group IS NULL THEN u_assigned.name ELSE NULL END SEPARATOR ', ') as assigned_names,
       MIN(t.type) AS task_type,
       MIN(t.instruction) as instruction,
       MIN(t.start_datetime) as start_datetime,
       MIN(t.end_datetime) as end_datetime,
       ci.invoice_number
FROM alerts a
LEFT JOIN tasks t ON t.alert_id = a.id AND t.status = 'Pendiente'
LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
LEFT JOIN check_ins ci ON a.check_in_id = ci.id
WHERE a.status IN ('Pendiente', 'Asignada') -- CORRECCIÓN: Buscamos ambas (como en v. estable)
  {$alert_filter} -- CORRECCIÓN: Usamos el nuevo filtro de alertas
GROUP BY a.id
ORDER BY FIELD(a.priority, 'Critica', 'Alta', 'Media', 'Baja'), a.created_at DESC
";
// ... (el resto de la lógica de $alerts_result no cambia) ...

$alerts_result = $conn->query($alerts_sql);
if ($alerts_result) {
    while ($row = $alerts_result->fetch_assoc()) {
        $row['item_type'] = 'alert';
        $all_pending_items[] = $row;
    }
} else { error_log("Error loading alerts: " . $conn->error); }

// Cargar Tareas Manuales Pendientes
$manual_tasks_sql = "
    SELECT
        t.id, MIN(t.id) as task_id, t.title, t.instruction, t.priority, MIN(t.status) as task_status,
        t.assigned_to_user_id, t.assigned_to_group,
        GROUP_CONCAT(DISTINCT CASE WHEN t.assigned_to_group IS NULL THEN u.name ELSE NULL END SEPARATOR ', ') as assigned_names,
        t.start_datetime, t.end_datetime,
        MAX(CASE WHEN t.assigned_to_user_id = {$current_user_id} THEN t.id ELSE NULL END) as user_task_id
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to_user_id = u.id
    WHERE t.alert_id IS NULL AND t.type = 'Manual' AND t.status = 'Pendiente'
      {$task_filter} -- Se aplica el filtro corregido
    GROUP BY IF(t.assigned_to_group IS NOT NULL, CONCAT(t.title, t.assigned_to_group, t.created_at), t.id)
    ORDER BY FIELD(t.priority, 'Critica', 'Alta', 'Media', 'Baja'), t.created_at DESC
";
$manual_tasks_result = $conn->query($manual_tasks_sql);
if ($manual_tasks_result) {
    while($row = $manual_tasks_result->fetch_assoc()) {
        $row['item_type'] = 'manual_task';
        $all_pending_items[] = $row;
    }
} else { error_log("Error loading manual tasks: " . $conn->error); }

// Procesar y ordenar items
$main_priority_items = []; $main_non_priority_items = [];
$panel_high_priority_items = []; $panel_medium_priority_items = [];
$now = new DateTime();
foreach ($all_pending_items as $item) {
    $original_priority = $item['priority'] ?? 'Media';
    $current_priority = $original_priority;
    if (!empty($item['end_datetime'])) {
        try {
           $end_time = new DateTime($item['end_datetime']);
           $diff_minutes = ($now->getTimestamp() - $end_time->getTimestamp()) / 60;
            if ($diff_minutes >= 0) { $current_priority = 'Alta'; }
            elseif ($diff_minutes > -15 && ($original_priority === 'Baja' || $original_priority === 'Media')) { $current_priority = 'Media'; }
        } catch (Exception $e) { /* Log error */ }
    }
    $item['current_priority'] = $current_priority;
    // Si no tiene user_task_id pero sí grupo y el grupo coincide, usa el task_id general (para el botón completar)
    if (empty($item['user_task_id']) && !empty($item['assigned_to_group']) && $item['assigned_to_group'] == $current_user_role) {
         $item['user_task_id'] = $item['task_id'] ?? $item['id'];
    }

    if ($current_priority === 'Critica' || $current_priority === 'Alta') {
        $main_priority_items[] = $item; $panel_high_priority_items[] = $item;
    } else {
        $main_non_priority_items[] = $item;
        if ($current_priority === 'Media') $panel_medium_priority_items[] = $item;
    }
}
$priority_order = ['Critica' => 4, 'Alta' => 3, 'Media' => 2, 'Baja' => 1];
$sortFunction = function($a, $b) use ($priority_order) {
    $priorityA = $priority_order[$a['current_priority']] ?? 0;
    $priorityB = $priority_order[$b['current_priority']] ?? 0;
    if ($priorityB !== $priorityA) return $priorityB <=> $priorityA;
    $dateA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
    $dateB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
    return $dateB <=> $dateA;
};
usort($main_priority_items, $sortFunction);
usort($main_non_priority_items, $sortFunction);
usort($panel_high_priority_items, $sortFunction);
usort($panel_medium_priority_items, $sortFunction);
$total_alerts_count_for_user = count($all_pending_items);
$priority_summary_count = count($main_priority_items);
$high_priority_badge_count = count($panel_high_priority_items);
$medium_priority_badge_count = count($panel_medium_priority_items);

// --- OTRAS CONSULTAS DE DATOS ---
$completed_tasks = [];
if ($_SESSION['user_role'] === 'Admin') {
    // Consulta para Trazabilidad (Admin)
    $completed_result = $conn->query(
        "SELECT t.id, COALESCE(a.title, t.title) as title, t.instruction, t.priority, t.start_datetime, t.end_datetime, u_assigned.name as assigned_to, u_completed.name as completed_by, t.created_at, t.completed_at, TIMEDIFF(t.completed_at, t.created_at) as response_time, t.assigned_to_group, u_creator.name as created_by_name, t.resolution_note FROM tasks t LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id LEFT JOIN users u_completed ON t.completed_by_user_id = u_completed.id LEFT JOIN users u_creator ON t.created_by_user_id = u_creator.id LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.status = 'Completada' ORDER BY t.completed_at DESC" );
    if ($completed_result) {
        while($row = $completed_result->fetch_assoc()){
            $original_priority = $row['priority']; $final_priority = $original_priority;
             if (!empty($row['end_datetime']) && !empty($row['completed_at'])) {
                  try { $end_time = new DateTime($row['end_datetime']); $completed_time = new DateTime($row['completed_at']); if ($completed_time > $end_time) { $final_priority = 'Alta'; } } catch (Exception $e) {}
             }
            $row['final_priority'] = $final_priority;
            $completed_tasks[] = $row;
        }
    } else { error_log("Error Admin completed tasks: " . $conn->error); }
}

// Consulta para Mi Historial (Usuario actual)
$user_completed_tasks = [];
$stmt_user_tasks = $conn->prepare( "SELECT t.id, COALESCE(a.title, t.title) as title, t.instruction, t.priority, t.start_datetime, t.end_datetime, u_assigned.name as assigned_to, u_completed.name as completed_by, t.created_at, t.completed_at, TIMEDIFF(t.completed_at, t.created_at) as response_time, t.assigned_to_group, u_creator.name as created_by_name, t.resolution_note FROM tasks t LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id LEFT JOIN users u_completed ON t.completed_by_user_id = u_completed.id LEFT JOIN users u_creator ON t.created_by_user_id = u_creator.id LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.status = 'Completada' AND (t.completed_by_user_id = ?) ORDER BY t.completed_at DESC" );
$stmt_user_tasks->bind_param("i", $current_user_id);
$stmt_user_tasks->execute();
$user_tasks_result = $stmt_user_tasks->get_result();
if ($user_tasks_result) {
    while($row = $user_tasks_result->fetch_assoc()){
        $original_priority = $row['priority']; $final_priority = $original_priority;
         if (!empty($row['end_datetime']) && !empty($row['completed_at'])) {
             try { $end_time = new DateTime($row['end_datetime']); $completed_time = new DateTime($row['completed_at']); if ($completed_time > $end_time) { $final_priority = 'Alta'; } } catch (Exception $e) {}
        }
        $row['final_priority'] = $final_priority;
        $user_completed_tasks[] = $row;
    }
} else { error_log("Error User completed tasks: " . $conn->error); }
$stmt_user_tasks->close();

// Recordatorios del usuario
$user_reminders = [];
// --- MODIFICADO: Añadir JOIN con users para obtener el nombre del creador ---
$reminders_sql = "
    SELECT r.id, r.message, r.created_at, u.name as creator_name
    FROM reminders r
    LEFT JOIN users u ON r.created_by_user_id = u.id
    WHERE r.user_id = {$current_user_id} AND r.is_read = 0
    ORDER BY r.created_at DESC
";
$reminders_result = $conn->query($reminders_sql);
if($reminders_result) {
    while($row = $reminders_result->fetch_assoc()){
        $user_reminders[] = $row;
    }
} else {
    error_log("Error loading user reminders: " . $conn->error);
}

// Datos para el Dashboard (Panel General)
$today_collections = []; $total_recaudado_hoy = 0;
$today_collections_result = $conn->query("
SELECT
    oc.id,
    oc.total_counted,
    c.name AS client_name,
    u_op.name AS operator_name,
    oc.bills_100k, oc.bills_50k, oc.bills_20k, oc.bills_10k, oc.bills_5k, oc.bills_2k, oc.coins,
    oc.created_at,
    ci.invoice_number,
    f.name AS fund_name,
    u_dig.name AS digitador_name,
    CASE
        WHEN ci.digitador_status = 'Cerrado' THEN 'Cerrado'
        -- WHEN ci.digitador_status = 'Conforme' THEN 'Conforme' -- Comentado si ya no usas 'Conforme'
        WHEN ci.status = 'Rechazado' THEN 'Rechazado'
        WHEN ci.status = 'Discrepancia' THEN 'Discrepancia' -- <-- MODIFICADO
        WHEN ci.status = 'Procesado' THEN 'Procesado'    -- <-- MODIFICADO
        WHEN ci.status = 'Pendiente' THEN 'Pendiente (Operador)'
        ELSE ci.status
    END AS final_status
FROM operator_counts oc
INNER JOIN (
    SELECT check_in_id, MAX(id) AS max_oc_id
    FROM operator_counts
    WHERE DATE(created_at) = CURDATE()
    GROUP BY check_in_id
) latest_oc ON oc.id = latest_oc.max_oc_id
JOIN check_ins ci ON oc.check_in_id = ci.id
JOIN clients c ON ci.client_id = c.id
JOIN users u_op ON oc.operator_id = u_op.id
LEFT JOIN funds f ON ci.fund_id = f.id
LEFT JOIN users u_dig ON ci.closed_by_digitador_id = u_dig.id
ORDER BY oc.created_at DESC
");
if ($today_collections_result) { while ($row = $today_collections_result->fetch_assoc()) { $today_collections[] = $row; $total_recaudado_hoy += $row['total_counted']; } }
else { error_log("Error Today collections: " . $conn->error); }

$cierres_pendientes_result = $conn->query("SELECT COUNT(DISTINCT ci.id) as total FROM check_ins ci WHERE ci.status IN ('Procesado', 'Discrepancia') AND ci.digitador_status IS NULL");
$cierres_pendientes_count = $cierres_pendientes_result->fetch_assoc()['total'] ?? 0;

// Datos para Selects (Checkinero)
$all_clients = [];
$clients_result = $conn->query("SELECT id, name, nit FROM clients ORDER BY name ASC");
if ($clients_result) { while ($row = $clients_result->fetch_assoc()) { $all_clients[] = $row; } }
$all_routes = [];
$routes_result = $conn->query("SELECT id, name FROM routes ORDER BY name ASC");
if ($routes_result) { while ($row = $routes_result->fetch_assoc()) { $all_routes[] = $row; } }

// Checkins Iniciales (Checkinero y también base para realtime)

$total_alerts_count_for_user = count($all_pending_items);
$priority_summary_count = count($main_priority_items);
$high_priority_badge_count = count($panel_high_priority_items);
$medium_priority_badge_count = count($panel_medium_priority_items);
$all_clients = [];
$clients_result = $conn->query("SELECT id, name, nit FROM clients ORDER BY name ASC");
if ($clients_result) { while ($row = $clients_result->fetch_assoc()) { $all_clients[] = $row; } }
$all_routes = [];
$routes_result = $conn->query("SELECT id, name FROM routes ORDER BY name ASC");
if ($routes_result) { while ($row = $routes_result->fetch_assoc()) { $all_routes[] = $row; } }

$initial_checkins = [];
$checkins_result = $conn->query("
    SELECT ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, f.id as fund_id, f.name as fund_name,
           ci.created_at, c.name as client_name, c.id as client_id, r.name as route_name, r.id as route_id, u.name as checkinero_name,
           ci.status, ci.correction_count, ci.digitador_status
    FROM check_ins ci
    JOIN clients c ON ci.client_id = c.id
    JOIN routes r ON ci.route_id = r.id
    JOIN users u ON ci.checkinero_id = u.id
    LEFT JOIN funds f ON ci.fund_id = f.id
    WHERE ci.status IN ('Pendiente', 'Rechazado', 'Procesado', 'Discrepancia')
    ORDER BY ci.correction_count DESC, ci.created_at DESC
");
if ($checkins_result) { while ($row = $checkins_result->fetch_assoc()) { $initial_checkins[] = $row; } }

// Historial Operador (Operador y Admin)
$operator_history = [];
if (in_array($_SESSION['user_role'], ['Operador', 'Admin', 'Digitador'])) { // Digitador también puede verlo
    $operator_id_filter = ($_SESSION['user_role'] === 'Operador') ? "WHERE op.operator_id = " . intval($_SESSION['user_id']) : "";
    $history_query = " SELECT op.id, op.check_in_id, op.total_counted, op.discrepancy, op.observations, op.created_at as count_date, ci.invoice_number, ci.declared_value, c.name as client_name, u.name as operator_name FROM operator_counts op INNER JOIN ( SELECT check_in_id, MAX(id) as max_id FROM operator_counts GROUP BY check_in_id ) as latest_oc ON op.id = latest_oc.max_id JOIN check_ins ci ON op.check_in_id = ci.id JOIN clients c ON ci.client_id = c.id JOIN users u ON op.operator_id = u.id {$operator_id_filter} ORDER BY op.created_at DESC ";
    $history_result = $conn->query($history_query);
    if ($history_result) { while($row = $history_result->fetch_assoc()) { $operator_history[] = $row; } }
    else { error_log("Error operator history: " . $conn->error); }
}

// Historial Cierres Digitador (Digitador y Admin)
$digitador_closed_history = [];
if (in_array($current_user_role, ['Digitador', 'Admin'])) {
   $digitador_filter = "WHERE ci.digitador_status IN ('Procesado', 'Discrepancia', 'Cerrado')";

    $closed_history_result = $conn->query(" SELECT ci.id, ci.invoice_number, c.name as client_name, u_check.name as checkinero_name, oc.total_counted, oc.discrepancy, oc.bills_100k, oc.bills_50k, oc.bills_20k, oc.bills_10k, oc.bills_5k, oc.bills_2k, oc.coins, u_op.name as operator_name, ci.closed_by_digitador_at, u_digitador.name as digitador_name, ci.digitador_status, ci.digitador_observations, f.name as fund_name FROM check_ins ci LEFT JOIN clients c ON ci.client_id = c.id LEFT JOIN users u_check ON ci.checkinero_id = u_check.id LEFT JOIN ( SELECT a.* FROM operator_counts a INNER JOIN ( SELECT check_in_id, MAX(id) as max_id FROM operator_counts GROUP BY check_in_id ) b ON a.id = b.max_id ) oc ON ci.id = oc.check_in_id LEFT JOIN users u_op ON oc.operator_id = u_op.id LEFT JOIN users u_digitador ON ci.closed_by_digitador_id = u_digitador.id LEFT JOIN funds f ON ci.fund_id = f.id {$digitador_filter} ORDER BY ci.closed_by_digitador_at DESC, ci.id DESC" );
    if($closed_history_result){ while($row = $closed_history_result->fetch_assoc()){ $digitador_closed_history[] = $row; } }
    else { error_log("Error digitador history: " . $conn->error); }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EAGLE 3.0 - Sistema de Alertas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/xlsx/dist/xlsx.full.min.js"></script>
    <script src="js/jspdf.umd.min.js"></script>
    <script src="js/jspdf-autotable.min.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        
        html { font-size: 12px; }
        body { font-family: 'Inter', sans-serif; }
        .nav-tab { cursor: pointer; padding: 0.25rem 1rem; font-weight: 600; border-bottom: 3px solid transparent; transition: all 0.2s; white-space: nowrap; }
        .nav-admin:hover { color: #dc2626; }
        .nav-admin.active { color: #dc2626; border-bottom-color: #dc2626; background-color: #fee2e2; }
        .nav-digitador { color: #1e40af; }
        .nav-digitador:hover { color: #2563eb; }
        .nav-digitador.active { color: #2563eb; border-bottom-color: #2563eb; background-color: #dbeafe; }
        .nav-operador { color: #a16207; }
        .nav-operador:hover { color: #ca8a04; }
        .nav-operador.active { color: #ca8a04; border-bottom-color: #ca8a04; background-color: #fefce8; }
        .nav-checkinero { color: #15803d; }
        .nav-checkinero:hover { color: #16a34a; }
        .nav-checkinero.active { color: #16a34a; border-bottom-color: #16a34a; background-color: #f0fdf4; }
        #user-modal-overlay, #reminders-panel, #task-notifications-panel, #medium-priority-panel { transition: opacity 0.3s ease; }
        .task-form, .cash-breakdown { transition: all 0.4s ease-in-out; max-height: 0; overflow: hidden; padding-top: 0; padding-bottom: 0; opacity: 0;}
        .task-form.active, .cash-breakdown.active { max-height: 800px; padding-top: 1rem; padding-bottom: 1rem; opacity: 1;}
        .details-row { border-top: 1px solid #e5e7eb; }
        .sortable { transition: background-color 0.2s; cursor: pointer; }
        .sortable:hover { background-color: #f3f4f6; }
        .sortable span { color: #9ca3af; }
        .nav-dropdown { position: relative; display: inline-block; }
        .nav-dropdown-content { display: none; position: absolute; background-color: white; min-width: 200px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 50; border-radius: 0.375rem; overflow: hidden; top: 100%; left: 0; margin-top: 0.25rem; }
        .nav-dropdown-content a { color: #374151; padding: 0.75rem 1rem; text-decoration: none; display: block; font-weight: 500; font-size: 0.875rem; white-space: nowrap; transition: background-color 0.15s ease-in-out; cursor: pointer; border-bottom: 3px solid transparent; }
        .nav-dropdown-content a:hover { background-color: #f3f4f6; }
        .nav-dropdown:hover .nav-dropdown-content { display: block; }
        .nav-dropdown-content a.nav-admin:hover { background-color: #fee2e2; }
        .nav-dropdown-content a.nav-digitador:hover { background-color: #dbeafe; }
        .nav-dropdown-content a.nav-operador:hover { background-color: #fefce8; }
        .nav-dropdown-content a.nav-checkinero:hover { background-color: #f0fdf4; }
        .header-panel-button { display: inline-block; padding: 0.3rem 0.8rem; margin-left: 0.5rem; font-size: 0.8rem; font-weight: 500; border-radius: 0.375rem; border: 1px solid; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); line-height: 1.5; }
        .header-panel-button:hover { opacity: 0.9; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); }
        .header-panel-button.active { box-shadow: inset 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
        .header-panel-button.nav-checkinero { background-color: #dcfce7; border-color: #4ade80; color: #166534; }
        .header-panel-button.nav-checkinero:hover { background-color: #bbf7d0; }
        .header-panel-button.nav-checkinero.active { background-color: #a7f3d0; border-color: #22c55e; }
        .header-panel-button.nav-operador { background-color: #fef9c3; border-color: #facc15; color: #854d0e; }
        .header-panel-button.nav-operador:hover { background-color: #fde68a; }
        .header-panel-button.nav-operador.active { background-color: #fde047; border-color: #eab308; }
        .header-panel-button.nav-digitador { background-color: #dbeafe; border-color: #60a5fa; color: #1e40af; }
        .header-panel-button.nav-digitador:hover { background-color: #bfdbfe; }
        .header-panel-button.nav-digitador.active { background-color: #93c5fd; border-color: #3b82f6; }
        #alert-popup-overlay { transition: opacity 0.3s ease; }
        #alert-popup { transition: transform 0.3s ease, opacity 0.3s ease; }
        .loader { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .notification-panel { position: absolute; right: 0; margin-top: 0.5rem; width: 20rem; background-color: white; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); padding: 1rem; z-index: 50; }
        .notification-list { max-height: 16rem; overflow-y: auto; }
        .badge-count { display: flex; align-items: center; justify-content: center; height: 1.25rem; width: 1.25rem; border-radius: 9999px; color: white; font-size: 0.75rem; font-weight: 700; position: absolute; top: -0.5rem; right: -0.5rem; }
        /* Toast Notification Styles */
.toast {
    background-color: white;
    color: #333;
    padding: 1rem 1.5rem;
    border-radius: 0.375rem; /* rounded-md */
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); /* shadow-md */
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-width: 250px;
    max-width: 400px;
    opacity: 0;
    transform: translateX(100%);
    transition: all 0.5s ease-in-out;
    border-left: 4px solid; /* Placeholder for priority color */
}

.toast.show {
    opacity: 1;
    transform: translateX(0);
}

.toast .toast-content {
    margin-right: 1rem;
}

.toast .toast-title {
    font-weight: 600; /* font-semibold */
    margin-bottom: 0.25rem;
}

.toast .toast-body {
    font-size: 0.875rem; /* text-sm */
    color: #6b7280; /* text-gray-500 */
}

.toast .toast-close {
    background: none;
    border: none;
    color: #9ca3af; /* text-gray-400 */
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 0.5rem;
}
.toast .toast-close:hover {
    color: #374151; /* text-gray-700 */
}


/* Priority Colors */
.toast.toast-critica { border-left-color: #ef4444; /* red-500 */ }
.toast.toast-alta { border-left-color: #f97316; /* orange-500 */ }
.toast.toast-media { border-left-color: #eab308; /* yellow-500 */ }
.toast.toast-baja { border-left-color: #6b7280; /* gray-500 */ }
.toast.toast-success { border-left-color: #22c55e; } /* green-500 */
.toast.toast-error { border-left-color: #ef4444; } /* red-500 */
.loading-overlay {
    position: absolute;
    inset: 0;
    background-color: rgba(255, 255, 255, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 20; /* Ensure it's on top of the form */
    border-radius: 0.375rem; /* rounded-md */
}
.progress-bar-container {
    width: 80%;
    background-color: #e9ecef;
    border-radius: 0.375rem;
    overflow: hidden;
    height: 2.5rem;
    display: flex;
    align-items: center;
    position: relative;
    border: 1px solid #ced4da;
}
.progress-bar {
    background-color: #0d6efd;
    height: 100%;
    width: 0%;
    transition: width 0.1s linear;
    display: flex;
    align-items: center;
    justify-content: center;
}
.progress-bar-text {
    position: absolute;
    width: 100%;
    text-align: center;
    font-weight: bold;
    color: #495057;
    text-shadow: 0 0 2px white;
}
    
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div id="user-modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
        <div id="user-modal" class="bg-white rounded-xl shadow-2xl w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center pb-3 border-b"><h3 id="modal-title" class="text-xl font-bold text-gray-900"></h3><button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-3xl leading-none">&times;</button></div>
                <form id="user-form" class="mt-6 space-y-4">
                    <input type="hidden" id="user-id" name="id">
                    <div><label for="user-name" class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label><input type="text" id="user-name" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-md" required></div>
                    <div><label for="user-email" class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" id="user-email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md"></div>
                    <div><label for="user-role" class="block text-sm font-medium text-gray-700 mb-1">Rol</label><select id="user-role" name="role" class="w-full px-3 py-2 border border-gray-300 rounded-md" required><option value="Operador">Operador</option><option value="Checkinero">Checkinero</option><option value="Digitador">Digitador</option><option value="Admin">Admin</option></select></div>
                    <div>
                        <label for="user-gender" class="block text-sm font-medium text-gray-700 mb-1">Sexo</label>
                        <select id="user-gender" name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
                            <option value="">Seleccionar...</option><option value="M">Masculino</option><option value="F">Femenino</option>
                        </select>
                    </div>
                    <div><label for="user-password" class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label><input type="password" id="user-password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md"><p id="password-hint" class="text-xs text-gray-500 mt-1"></p></div>
                    <div class="pt-4 flex justify-end space-x-3"><button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancelar</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700">Guardar</button></div>
                </form>
            </div>
        </div>
    </div>

    <div id="alert-popup-overlay" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 hidden z-[100]">
        <div id="alert-popup" class="bg-white rounded-lg shadow-xl w-full max-w-lg transform scale-95 opacity-0">
             <div id="alert-popup-header" class="p-4 border-b rounded-t-lg bg-red-100 border-red-200"><div class="flex justify-between items-center"><h3 id="alert-popup-title" class="text-xl font-bold text-red-800"></h3><button onclick="closeAlertPopup()" class="text-red-400 hover:text-red-600 text-3xl leading-none">&times;</button></div></div>
             <div class="p-6 space-y-3"><p id="alert-popup-description" class="text-gray-700"></p><p class="text-sm text-gray-500">Por favor, revisa el panel de alertas para más detalles y acciones.</p></div>
             <div class="p-4 bg-gray-50 border-t rounded-b-lg flex justify-end"><button onclick="closeAlertPopup()" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700">Entendido</button></div>
        </div>
    </div>

    <div id="app" class="p-4 sm:p-6 lg:p-8 max-w-full mx-auto">
        <header class="flex flex-col sm:flex-row justify-between sm:items-start mb-2 border-b pb-1">
             <div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-900">EAGLE 3.0</h1>
                <p class="text-sm text-gray-500 mb-2">Sistema Integrado de Operaciones y Alertas</p>
                <div class="inline-flex items-center gap-2 mt-2 px-4 py-2 rounded-xl border <?php echo $role_container_border_class; ?> <?php echo $role_container_bg_class; ?>">
                    <span class="font-semibold text-gray-700">Hola</span>
                    <span class="font-bold px-3 py-1 rounded-full <?php echo $role_color_class; ?>"><?php echo htmlspecialchars($displayRole); ?></span>
                    <span class="font-bold text-gray-900">: <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                </div>
            </div>
            <div class="mt-4 sm:mt-0 flex flex-col sm:items-end space-y-2">
                <div class="flex items-center space-x-4">
                    <a href="logout.php" class="text-blue-600 hover:underline">Cerrar Sesión</a>
                    <div class="relative">
                        <button id="task-notification-button" onclick="togglePanel('task-notifications-panel')" class="relative text-gray-500 hover:text-gray-700">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6H5a2 2 0 00-2 2zm0 0h7"></path></svg>
                            <span id="task-notification-badge" class="absolute -top-2 -right-2 badge-count bg-red-500 <?php echo $high_priority_badge_count == 0 ? 'hidden' : ''; ?>"><?php echo $high_priority_badge_count; ?></span>
                        </button>
                        <div id="task-notifications-panel" class="notification-panel hidden">
                            <h4 class="font-bold text-gray-800 mb-2">Alertas de Tareas Prioritarias</h4>
                            <div id="task-notifications-list" class="space-y-2 max-h-64 overflow-y-auto notification-list">
                                <?php if(empty($panel_high_priority_items)): ?> <p class="text-sm text-gray-500">No hay alertas prioritarias.</p>
                                <?php else: foreach($panel_high_priority_items as $item): $color_class = $item['current_priority'] === 'Critica' ? 'red' : 'orange'; ?>
                                <div class="p-2 bg-<?php echo $color_class; ?>-50 rounded-md border border-<?php echo $color_class; ?>-200 text-sm">
                                    <p class="font-semibold text-<?php echo $color_class; ?>-800"><?php echo htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?><?php if (!empty($item['invoice_number'])): ?> <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span><?php endif; ?></p>
                                    <p class="text-gray-700 text-xs mt-1"><?php echo htmlspecialchars($item['item_type'] === 'manual_task' ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                    <?php if ($_SESSION['user_role'] === 'Admin'): if (!empty($item['assigned_to_group'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-<?php echo $color_class; ?>-200">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></p><?php elseif (!empty($item['assigned_names'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-<?php echo $color_class; ?>-200">Asignada a: <?php echo htmlspecialchars($item['assigned_names']); ?></p><?php endif; endif; ?>
                                    <?php if (!empty($item['end_datetime'])): ?><div class="countdown-timer text-xs font-bold mt-1" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div><?php endif; ?>
                                </div> <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="relative">
                        <button id="medium-priority-button" onclick="togglePanel('medium-priority-panel')" class="relative text-gray-500 hover:text-gray-700">
                             <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <span id="medium-priority-badge" class="absolute -top-2 -right-2 badge-count bg-yellow-500 <?php echo $medium_priority_badge_count == 0 ? 'hidden' : ''; ?>"><?php echo $medium_priority_badge_count; ?></span>
                        </button>
                        <div id="medium-priority-panel" class="notification-panel hidden">
                           <h4 class="font-bold text-gray-800 mb-2">Alertas de Prioridad Media</h4>
                            <div id="medium-priority-list" class="space-y-2 max-h-64 overflow-y-auto notification-list">
                               <?php if(empty($panel_medium_priority_items)): ?> <p class="text-sm text-gray-500">No hay alertas de prioridad media.</p>
                               <?php else: foreach($panel_medium_priority_items as $item): ?>
                                    <div class="p-2 bg-yellow-50 rounded-md border border-yellow-200 text-sm">
                                        <p class="font-semibold text-yellow-800"><?php echo htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?><?php if (!empty($item['invoice_number'])): ?> <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span><?php endif; ?></p>
                                        <p class="text-gray-700 text-xs mt-1"><?php echo htmlspecialchars($item['item_type'] === 'manual_task' ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                        <?php if ($_SESSION['user_role'] === 'Admin'): if (!empty($item['assigned_to_group'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-yellow-200">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></p><?php elseif (!empty($item['assigned_names'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-yellow-200">Asignada a: <?php echo htmlspecialchars($item['assigned_names']); ?></p><?php endif; endif; ?>
                                        <?php if (!empty($item['end_datetime'])): ?><div class="countdown-timer text-xs font-bold mt-1" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div><?php endif; ?>
                                    </div> <?php endforeach; endif; ?>
                            </div>
                        </div>
                   </div>
                   <div class="relative">
                            <button id="reminders-button" onclick="togglePanel('reminders-panel')" class="relative text-gray-500 hover:text-gray-700">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
    <span id="reminders-badge" class="absolute -top-2 -right-2 flex items-center justify-center h-5 w-5 rounded-full bg-blue-500 text-white text-xs font-bold hidden"></span>
</button>
<div id="reminders-panel" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl p-4 hidden z-20">
    <h4 class="font-bold text-gray-800 mb-2">Tus Recordatorios</h4>
    <div id="reminders-list" class="space-y-2 max-h-64 overflow-y-auto notification-list">
       <?php if(empty($user_reminders)): ?>
           <p class="text-sm text-gray-500">No tienes recordatorios pendientes.</p>
       <?php else: ?>
           <?php foreach($user_reminders as $reminder): ?>
                <div class="reminder-item p-2 bg-blue-50 rounded-md border border-blue-200 text-sm">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-700"><?php echo htmlspecialchars($reminder['message']); ?></p>
                            <p class="text-xs text-gray-400 mt-1"><?php echo date('d M, h:i a', strtotime($reminder['created_at'])); ?></p>
                            <p class="text-xs text-blue-600 mt-1">Enviado por: <?php echo htmlspecialchars($reminder['creator_name'] ?? 'Desconocido'); ?></p>
                        </div>
                        <button onclick="deleteReminder(<?php echo $reminder['id']; ?>, this)" class="text-red-400 hover:text-red-600 font-bold text-lg">&times;</button>
                    </div>
                </div>
           <?php endforeach; ?>
       <?php endif; // <--- ¡Añade esta línea! ?>
    </div>
</div>
                   </div>
               </div>
               <?php if ($_SESSION['user_role'] === 'Admin'): ?>
               <div class="mt-2 flex flex-wrap justify-start sm:justify-end space-x-2 sm:space-x-4">
                   <?php if (in_array($_SESSION['user_role'], ['Checkinero', 'Admin'])): ?><button id="tab-checkinero" class="header-panel-button nav-checkinero shadow-sm" onclick="switchTab('checkinero')">Panel Check-in</button><?php endif; ?>
                   <?php if (in_array($_SESSION['user_role'], ['Operador', 'Admin'])): ?><button id="tab-operador" class="header-panel-button nav-operador shadow-sm" onclick="switchTab('operador')">Panel Operador</button><?php endif; ?>
                   <?php if (in_array($_SESSION['user_role'], ['Digitador', 'Admin'])): ?><button id="tab-digitador" class="header-panel-button nav-digitador shadow-sm" onclick="switchTab('digitador')">Panel Digitador</button><?php endif; ?>
               </div>
               <?php endif; ?>
               <div class="mt-2 text-sm font-mono text-blue-700 font-semibold p-2 bg-blue-100 border border-blue-300 rounded-md">
    Hora del Servidor (Prueba): <?php echo date('Y-m-d H:i:s T'); ?>
</div>
               </div>
       </header>

        <nav class="mb-4">
           <div class="border-b border-gray-200"><div class="-mb-px flex space-x-4 overflow-x-auto">
               <button id="tab-operaciones" class="nav-tab active <?php echo $role_nav_class; ?>" onclick="switchTab('operaciones')">Panel General</button>
               <?php if ($_SESSION['user_role'] === 'Checkinero'): ?><button id="tab-checkinero" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('checkinero')">Panel Check-in</button><?php endif; ?>
               <?php if ($_SESSION['user_role'] === 'Operador'): ?><button id="tab-operador" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('operador')">Panel Operador</button><?php endif; ?>
               <?php if ($_SESSION['user_role'] === 'Digitador'): ?><button id="tab-digitador" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('digitador')">Panel Digitador</button><?php endif; ?>
               <button id="tab-mi-historial" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('mi-historial')">Mi Historial de Tareas</button>
               <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                   <button id="tab-roles" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('roles')">Gestión de Roles</button>
                   <button id="tab-manage-clients" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('manage-clients')">Gestionar Clientes</button>
                   <button id="tab-manage-routes" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('manage-routes')">Gestionar Rutas</button>
                   <button id="tab-manage-funds" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('manage-funds')">Gestionar Fondos</button>
                   <button id="tab-trazabilidad" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('trazabilidad')">Trazabilidad</button>
               <?php endif; ?>
           </div></div>
       </nav>

        <main>
            <div id="content-operaciones">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Recaudos de Hoy</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">$</div></div><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo '$' . number_format($total_recaudado_hoy, 0, ',', '.'); ?></p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Cierres Pendientes</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">🕔</div></div><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo $cierres_pendientes_count; ?></p><p class="text-sm text-gray-500 mt-2">Para revisión de Digitador</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Alertas Activas</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">❗</div></div><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo $total_alerts_count_for_user; ?></p><p class="text-sm text-gray-500 mt-2"><?php echo $priority_summary_count; ?> Prioritarias</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Tasa de Cumplimiento</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">📈</div></div><p class="text-3xl font-bold text-gray-900 mt-2">94%</p><p class="text-sm text-green-600 mt-2">▲ 3% vs semana pasada</p></div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-4">
                        <h2 class="text-xl font-bold text-gray-900">Alertas y Tareas Prioritarias</h2>
                        <?php if (empty($main_priority_items)): ?>
                            <p class="text-sm text-gray-500 bg-white p-4 rounded-lg shadow-sm">No hay items prioritarios pendientes.</p>
                        <?php else: foreach ($main_priority_items as $item): ?>
                            <?php
                                $is_manual = $item['item_type'] === 'manual_task';
                                $is_group_task = !empty($item['assigned_to_group']);
                                $assigned_names = $item['assigned_names'] ?? null;
                                $task_id_to_use = $item['user_task_id'] ?? $item['task_id'] ?? $item['id']; // Usa el ID específico del usuario si existe
                                $alert_id_or_null = $is_manual ? 'null' : ($item['id'] ?? 'null');
                                $form_id_prefix = $task_id_to_use;
                                // --- CAMBIO: Condición para mostrar botón "Completar" (sin !$is_group_task) ---
                                // --- INICIO: Lógica Corregida para $can_complete ---
// --- INICIO: Lógica Corregida para $can_complete (v2) ---
$is_admin = $current_user_role === 'Admin';
$is_assigned_individually = !empty($item['user_task_id']);
$is_assigned_to_my_group = $is_group_task && isset($item['assigned_to_group']) && $item['assigned_to_group'] == $current_user_role;

// El usuario puede completar si es Admin O la tarea es suya (individual o grupo)
$user_can_act = $is_admin || $is_assigned_individually || $is_assigned_to_my_group;

// La tarea se considera "activa" si:
// 1. Es una tarea nueva con estado Pendiente
// 2. Es una alerta vieja sin tarea (task_status es NULL), la cual tratamos como pendiente
$task_is_active = (isset($item['task_status']) && in_array($item['task_status'], ['Pendiente','Media','Alta','Crítica']))
                   || !isset($item['task_status']);

$can_complete = $user_can_act && $task_is_active;
// --- FIN: Lógica Corregida para $can_complete (v2) ---
// --- FIN: Lógica Corregida para $can_complete ---
                                // --- FIN CAMBIO ---
                                $priority_to_use = $item['current_priority'];
                                $color_map = ['Critica' => ['bg' => 'bg-red-100', 'border' => 'border-red-500', 'text' => 'text-red-800', 'badge' => 'bg-red-200'],'Alta' => ['bg' => 'bg-orange-100', 'border' => 'border-orange-500', 'text' => 'text-orange-800', 'badge' => 'bg-orange-200']];
                                $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                            ?>
                            <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $task_id_to_use; ?>">
                                <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                    <div class="flex justify-between items-start">
                                        <p class="font-semibold <?php echo $color['text']; ?> text-lg">
                                            <?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?>
                                            <?php if (!empty($item['invoice_number'])): ?> <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span> <?php endif; ?>
                                            <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full"><?php echo strtoupper($priority_to_use); ?></span>
                                        </p>
                                        <?php if ($can_complete): ?>
                                            <button onclick="toggleForm('complete-form-<?php echo $form_id_prefix; ?>', this)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300 ml-auto" title="Marcar como completada"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></button>
                                         <?php endif; ?>
                                    </div>
                                    <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                    <?php if (!empty($item['end_datetime'])): ?> <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div> <?php endif; ?>
                                    <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                         <button onclick="toggleForm('assign-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?></button>
                                        <button onclick="toggleForm('reminder-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                        <div class="flex-grow text-right text-sm">
                                            <?php if($is_group_task): ?> <span class="font-semibold text-purple-700">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></span>
                                            <?php elseif (!empty($assigned_names)): ?> <span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($assigned_names); ?></span>
                                            <?php else: ?> <span class="font-semibold text-gray-500">Pendiente de Asignación</span> <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div id="complete-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                    <h4 class="text-sm font-semibold mb-2">Completar Tarea</h4>
                                    <textarea id="resolution-note-<?php echo $form_id_prefix; ?>" rows="3" class="w-full p-2 text-sm border rounded-md" placeholder="Añadir observación de cierre (obligatorio)..."></textarea>
                                    <button type="button" onclick="completeTask(<?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar Cierre</button>
                                </div>
                                <div id="assign-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                     <h4 class="text-sm font-semibold mb-2"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                     <select id="assign-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md"><optgroup label="Grupos"><option value="group-todos">Todos</option><option value="group-Operador">Operadores</option><option value="group-Checkinero">Checkineros</option><option value="group-Digitador">Digitadores</option></optgroup><optgroup label="Individuales"><?php foreach ($all_users as $user): ?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']) . " ({$user['role']})"; ?></option><?php endforeach; ?></optgroup></select>
                                     <textarea id="task-instruction-<?php echo $form_id_prefix; ?>" rows="2" class="w-full p-2 text-sm border rounded-md mt-2" placeholder="Instrucción"><?php echo htmlspecialchars($item['instruction'] ?? ''); ?></textarea>
                                     <button type="button" onclick="submitAssignment(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-blue-600 text-white font-semibold py-2 mt-4 rounded-md">Confirmar</button>
                                </div>
                                <div id="reminder-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                     <h4 class="text-sm font-semibold mb-2">Crear Recordatorio</h4>
                                     <select id="reminder-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md"><option value="">Seleccione usuario...</option><?php foreach ($all_users as $user): ?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option><?php endforeach; ?></select>
                                     <button type="button" onclick="setReminder(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-3 rounded-md">Crear</button>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>

                       <div class="bg-white p-6 rounded-xl shadow-sm mt-8">
                         <h2 class="text-lg font-semibold mb-4 text-gray-900">Recaudos del Día</h2>
                         <div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="text-xs text-gray-500 uppercase bg-gray-50"><tr><th class="px-6 py-3">Planilla</th><th class="px-6 py-3">Fondo</th><th class="px-6 py-3">Cliente</th><th class="px-6 py-3">Operador</th><th class="px-6 py-3">Digitador</th><th class="px-6 py-3">Hora</th><th class="px-6 py-3">Monto</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3"></th></tr></thead><tbody id="recaudos-tbody"><?php if (empty($today_collections)): ?><tr><td colspan="9" class="px-6 py-4 text-center text-gray-500">No hay recaudos hoy.</td></tr><?php else: ?><?php foreach ($today_collections as $recaudo): ?><?php $status_badge = ''; switch ($recaudo['final_status']) { case 'Conforme': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-green-100 text-green-800">Conforme</span>'; break; case 'Cerrado': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-blue-100 text-blue-800">Cerrado</span>'; break; case 'Rechazado': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-red-100 text-red-800">Rechazado</span>'; break; case 'En Revisión (Digitador)': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-yellow-100 text-yellow-800">En Revisión</span>'; break; case 'Pendiente (Operador)': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 text-gray-800">Pendiente</span>'; break; default: $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 text-gray-800">' . htmlspecialchars($recaudo['final_status']) . '</span>'; break; } ?><tr class="border-b"><td class="px-6 py-4 font-mono"><?php echo htmlspecialchars($recaudo['invoice_number']); ?></td><td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['fund_name'] ?? 'N/A'); ?></td><td class="px-6 py-4 font-medium"><?php echo htmlspecialchars($recaudo['client_name']); ?></td><td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['operator_name']); ?></td><td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['digitador_name'] ?? 'N/A'); ?></td><td class="px-6 py-4 text-xs"><?php echo date('h:i a', strtotime($recaudo['created_at'])); ?></td><td class="px-6 py-4 font-mono"><?php echo '$' . number_format($recaudo['total_counted'], 0, ',', '.'); ?></td><td class="px-6 py-4"><?php echo $status_badge; ?></td><td class="px-6 py-4 text-right"><button onclick="toggleBreakdown(<?php echo $recaudo['id']; ?>)" class="text-blue-600 text-xs font-semibold">Desglose</button></td></tr><tr class="details-row hidden" id="breakdown-row-<?php echo $recaudo['id']; ?>"><td colspan="9" class="p-0"><div id="breakdown-content-<?php echo $recaudo['id']; ?>" class="cash-breakdown bg-gray-50"><div class="p-4 grid grid-cols-3 sm:grid-cols-4 md:grid-cols-7 gap-x-8 gap-y-2 text-xs"><span><strong>$100.000:</strong> <?php echo number_format($recaudo['bills_100k'] ?? 0); ?></span><span><strong>$50.000:</strong> <?php echo number_format($recaudo['bills_50k'] ?? 0); ?></span><span><strong>$20.000:</strong> <?php echo number_format($recaudo['bills_20k'] ?? 0); ?></span><span><strong>$10.000:</strong> <?php echo number_format($recaudo['bills_10k'] ?? 0); ?></span><span><strong>$5.000:</strong> <?php echo number_format($recaudo['bills_5k'] ?? 0); ?></span><span><strong>$2.000:</strong> <?php echo number_format($recaudo['bills_2k'] ?? 0); ?></span><span class="font-bold">Monedas: <?php echo '$' . number_format($recaudo['coins'] ?? 0, 0, ',', '.'); ?></span></div></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div>
                    </div>
                    </div>

                    <div class="space-y-8">
                        <div class="bg-white p-6 rounded-xl shadow-sm">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Crear Tarea Manual</h2>
                            <form id="manual-task-form" class="space-y-3">
                                <div><label for="manual-task-title" class="text-sm font-medium">Título</label><input type="text" id="manual-task-title" required class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                <div><label for="manual-task-desc" class="text-sm font-medium">Descripción</label><textarea id="manual-task-desc" rows="3" class="w-full p-2 text-sm border rounded-md mt-1"></textarea></div>
                                <div><label for="manual-task-priority" class="text-sm font-medium">Prioridad</label><select id="manual-task-priority" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="Alta">Alta</option><option value="Media" selected>Media</option><option value="Baja">Baja</option></select></div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div><label for="manual-task-start" class="text-sm font-medium">Inicio</label><input type="datetime-local" id="manual-task-start" class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                    <div><label for="manual-task-end" class="text-sm font-medium">Fin</label><input type="datetime-local" id="manual-task-end" class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                </div>
                                <div><label for="manual-task-user" class="text-sm font-medium">Asignar a</label><select id="manual-task-user" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="">Seleccionar...</option><optgroup label="Grupos"><option value="group-todos">Todos</option><option value="group-Operador">Operadores</option><option value="group-Checkinero">Checkineros</option><option value="group-Digitador">Digitadores</option></optgroup><optgroup label="Individuales"><?php foreach ($all_users as $user):?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?> (<?php echo $user['role']; ?>)</option><?php endforeach; ?></optgroup></select></div>
                                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 mt-4 rounded-md">Crear Tarea</button>
                            </form>
                        </div>

                        <div class="bg-white p-6 rounded-xl shadow-sm">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Tareas y Alertas no Prioritarias</h2>
                            <div class="space-y-4">
                               <?php if (empty($main_non_priority_items)): ?>
                                    <p class="text-sm text-gray-500">No hay items no prioritarios pendientes.</p>
                                <?php else: foreach ($main_non_priority_items as $item): ?>
                                    <?php
                                        $is_manual = $item['item_type'] === 'manual_task';
                                        $is_group_task = !empty($item['assigned_to_group']);
                                        $assigned_names = $item['assigned_names'] ?? null;
                                        $task_id_to_use = $item['user_task_id'] ?? $item['task_id'] ?? $item['id']; // Usa el ID específico del usuario si existe
                                        $alert_id_or_null = $is_manual ? 'null' : ($item['id'] ?? 'null');
                                        $form_id_prefix = "np-" . $task_id_to_use;
                                        // --- CAMBIO: Condición para mostrar botón "Completar" (sin !$is_group_task) ---
// --- INICIO: Lógica Corregida para $can_complete (v2) ---
$is_admin = $current_user_role === 'Admin';
$is_assigned_individually = !empty($item['user_task_id']);
$is_assigned_to_my_group = $is_group_task && isset($item['assigned_to_group']) && $item['assigned_to_group'] == $current_user_role;

// El usuario puede completar si es Admin O la tarea es suya (individual o grupo)
$user_can_act = $is_admin || $is_assigned_individually || $is_assigned_to_my_group;

// La tarea se considera "activa" si:
// 1. Es una tarea nueva con estado Pendiente
// 2. Es una alerta vieja sin tarea (task_status es NULL), la cual tratamos como pendiente
$task_is_active = (isset($item['task_status']) && in_array($item['task_status'], ['Pendiente','Media','Alta','Crítica']))
                   || !isset($item['task_status']);

$can_complete = $user_can_act && $task_is_active;
// --- FIN: Lógica Corregida para $can_complete (v2) ---                                        // --- FIN CAMBIO ---
                                        $priority_to_use = $item['current_priority'];
                                        $color_map = ['Media' => ['bg' => 'bg-yellow-100', 'border' => 'border-yellow-400', 'text' => 'text-yellow-800', 'badge' => 'bg-yellow-200'],'Baja'  => ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200']];
                                        $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                                    ?>
                                    <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $task_id_to_use; ?>">
                                        <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                            <div class="flex justify-between items-start">
                                                <p class="font-semibold <?php echo $color['text']; ?> text-md">
                                                    <?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?>
                                                    <?php if (!empty($item['invoice_number'])): ?> <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span> <?php endif; ?>
                                                    <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full"><?php echo strtoupper($priority_to_use); ?></span>
                                                </p>
                                                 <?php if ($can_complete): ?>
                                                    <button onclick="toggleForm('complete-form-<?php echo $form_id_prefix; ?>', this)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300 ml-auto" title="Marcar como completada"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></button>
                                                 <?php endif; ?>
                                            </div>
                                            <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                            <?php if (!empty($item['end_datetime'])): ?> <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div> <?php endif; ?>
                                            <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                                 <button onclick="toggleForm('assign-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?></button>
                                                <button onclick="toggleForm('reminder-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                                <div class="flex-grow text-right text-sm">
                                                     <?php if($is_group_task): ?> <span class="font-semibold text-purple-700">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></span>
                                                    <?php elseif (!empty($assigned_names)): ?> <span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($assigned_names); ?></span>
                                                    <?php else: ?> <span class="font-semibold text-gray-500">Pendiente de Asignación</span> <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                         <div id="complete-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2">Completar Tarea</h4>
                                            <textarea id="resolution-note-<?php echo $form_id_prefix; ?>" rows="3" class="w-full p-2 text-sm border rounded-md" placeholder="Añadir observación de cierre (obligatorio)..."></textarea>
                                            <button type="button" onclick="completeTask(<?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar Cierre</button>
                                        </div>
                                        <div id="assign-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                            <select id="assign-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md"><optgroup label="Grupos"><option value="group-todos">Todos</option><option value="group-Operador">Operadores</option><option value="group-Checkinero">Checkineros</option><option value="group-Digitador">Digitadores</option></optgroup><optgroup label="Individuales"><?php foreach ($all_users as $user): ?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']) . " ({$user['role']})"; ?></option><?php endforeach; ?></optgroup></select>
                                            <textarea id="task-instruction-<?php echo $form_id_prefix; ?>" rows="2" class="w-full p-2 text-sm border rounded-md mt-2" placeholder="Instrucción"><?php echo htmlspecialchars($item['instruction'] ?? ''); ?></textarea>
                                            <button type="button" onclick="submitAssignment(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-blue-600 text-white font-semibold py-2 mt-4 rounded-md">Confirmar</button>
                                        </div>
                                        <div id="reminder-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2">Crear Recordatorio</h4>
                                            <select id="reminder-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md"><option value="">Seleccione usuario...</option><?php foreach ($all_users as $user): ?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option><?php endforeach; ?></select>
                                            <button type="button" onclick="setReminder(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-3 rounded-md">Crear</button>
                                        </div>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
           </div>

            <div id="content-checkinero" class="hidden">
                <h2 class="text-2xl font-bold text-gray-900 mb-6">Módulo de Check-in</h2>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 id="checkin-form-title" class="text-xl font-semibold mb-4">Registrar Nuevo Check-in</h3>
                        <form id="checkin-form" class="space-y-4">
                             <input type="hidden" id="check_in_id_field">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4"><div><label for="invoice_number" class="block text-sm font-medium">Nro Planilla</label><input type="text" id="invoice_number" required class="mt-1 w-full p-2 border rounded-md"></div><div><label for="seal_number" class="block text-sm font-medium">Nro Sello</label><input type="text" id="seal_number" required class="mt-1 w-full p-2 border rounded-md"></div></div>
                            <div><label for="client_id" class="block text-sm font-medium">Cliente</label><select id="client_id" required class="mt-1 w-full p-2 border rounded-md"><option value="">Seleccione...</option><?php foreach($all_clients as $client): ?><option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']) . ' (NIT: ' . htmlspecialchars($client['nit'] ?? 'N/A') . ')'; ?></option><?php endforeach; ?></select></div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label for="route_id" class="block text-sm font-medium">Ruta</label>
        <select id="route_id" required class="mt-1 w-full p-2 border rounded-md">
            <option value="">Seleccione...</option>
            <?php foreach($all_routes as $route): ?>
                <option value="<?php echo $route['id']; ?>"><?php echo htmlspecialchars($route['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Fondo</label>
        <span id="fund_display" class="mt-1 block w-full p-2 border border-gray-300 rounded-md bg-gray-100 text-gray-600 italic">
            Seleccione un cliente...
        </span>
        <input type="hidden" id="fund_id_hidden" name="fund_id">
    </div>
</div>

                                <div><label for="declared_value" class="block text-sm font-medium">Valor Declarado</label><input type="number" step="0.01" id="declared_value" required class="mt-1 w-full p-2 border rounded-md"></div>
                             <div id="checkin-form-buttons" class="flex space-x-4 pt-4">
                                <button type="submit" id="checkin-submit-button" class="w-full bg-green-600 text-white font-bold py-3 rounded-md hover:bg-green-700">Agregar Check-in</button>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 class="text-xl font-semibold mb-4">Últimos Check-ins Registrados</h3>
                        <div class="overflow-auto max-h-[600px]">
                            <table class="w-full text-sm text-left">
                             <thead class="bg-gray-50 sticky top-0">
                                <tbody id="checkins-table-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-operador" class="hidden">
                 <h2 class="text-2xl font-bold text-gray-900 mb-6">Módulo de Operador</h2>
                 <div id="consultation-section" class="bg-white p-6 rounded-xl shadow-lg mb-8">
                     <h3 class="text-xl font-semibold mb-4">Buscar Planilla para Detallar</h3>
                     <form id="consultation-form" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center"><label class="block text-sm font-medium">Nro Planilla<input type="text" id="consult-invoice" required class="mt-1 w-full p-2 border rounded-md"></label><div class="pt-6"><button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Consultar</button></div></form>
                </div>
                <div id="operator-panel" class="hidden"><div class="bg-white p-6 rounded-xl shadow-lg mb-8 relative"><div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 pb-4 border-b"><div><span class="block text-sm text-gray-500">Nro Planilla</span><strong id="display-invoice" class="text-lg"></strong></div><div><span class="block text-sm text-gray-500">Nro Sello</span><strong id="display-seal" class="text-lg"></strong></div><div><span class="block text-sm text-gray-500">Cliente</span><strong id="display-client" class="text-lg"></strong></div><div><span class="block text-sm text-gray-500">Valor Declarado</span><strong id="display-declared" class="text-lg text-blue-600"></strong></div></div><h3 class="text-xl font-semibold mb-4">Detalle de Denominación</h3><form id="denomination-form"><input type="hidden" id="op-checkin-id"><div class="space-y-2"><?php $denominations = [100000, 50000, 20000, 10000, 5000, 2000]; foreach($denominations as $value): ?><div class="grid grid-cols-5 gap-4 items-center denomination-row" data-value="<?php echo $value; ?>"><div class="col-span-2 font-medium text-gray-700"><?php echo '$' . number_format($value, 0, ',', '.'); ?></div><div class="col-span-2 flex items-center"><button type="button" class="px-3 py-1 bg-gray-200 rounded-l-md font-bold text-lg" onclick="updateQty(this, -1)">-</button><input type="number" value="0" min="0" class="w-full text-center border-t border-b p-1 denomination-qty" oninput="calculateTotals()"><button type="button" class="px-3 py-1 bg-gray-200 rounded-r-md font-bold text-lg" onclick="updateQty(this, 1)">+</button></div><div class="text-right font-mono subtotal">$ 0</div></div><?php endforeach; ?><div class="grid grid-cols-5 gap-4 items-center pt-2 border-t"><div class="col-span-2 font-medium text-gray-700">Monedas</div><div class="col-span-2"><input type="number" id="coins-value" value="0" min="0" step="50" class="w-full border p-1" oninput="calculateTotals()" placeholder="Valor total en monedas"></div><div class="text-right font-mono" id="coins-subtotal">$ 0</div></div><div class="grid grid-cols-5 gap-4 items-center pt-4 mt-4 border-t-2"><div class="col-span-2 font-bold text-xl">Total</div><div class="col-span-3 text-right font-mono text-xl" id="total-counted">$ 0</div></div><div class="grid grid-cols-5 gap-4 items-center"><div class="col-span-2 font-bold text-xl">Diferencia</div><div class="col-span-3 text-right font-mono text-xl" id="discrepancy">$ 0</div></div></div><div class="mt-6"><label for="observations" class="block text-sm font-medium">Observación</label><textarea id="observations" rows="3" class="mt-1 w-full border rounded-md p-2"></textarea></div><div class="mt-6 flex justify-end"><button type="submit" class="bg-green-600 text-white font-bold py-3 px-6 rounded-md hover:bg-green-700">Guardar y Cerrar</button></div><div id="loading-overlay" class="loading-overlay hidden">
    <div class="progress-bar-container">
        <div id="progress-bar" class="progress-bar"></div>
        <span id="progress-bar-text" class="progress-bar-text">0%</span>
    </div>
</div>
</form></div></div>
                <?php if ($_SESSION['user_role'] === 'Admin'): ?><div class="bg-white p-6 rounded-xl shadow-lg mt-8"><h3 class="text-xl font-semibold mb-4">Planillas Pendientes de Detallar (Admin)</h3><div class="overflow-auto max-h-[600px]"><table class="w-full text-sm text-left"><thead class="bg-gray-50 sticky top-0"></thead><tbody id="operator-checkins-table-body"></tbody></table></div></div><?php endif; ?>
                <div class="bg-white p-6 rounded-xl shadow-lg mt-8"><h3 class="text-xl font-semibold mb-4">Historial de Conteos Realizados</h3><div class="overflow-auto max-h-[600px]"><table class="w-full text-sm text-left"><thead class="bg-gray-50 sticky top-0"><tr><th class="p-3">Planilla</th><th class="p-3">Cliente</th><th class="p-3">Valor Declarado</th><th class="p-3">Valor Contado</th><th class="p-3">Discrep.</th><?php if (in_array($_SESSION['user_role'], ['Admin', 'Digitador'])): ?><th class="p-3">Operador</th><?php endif; ?><th class="p-3">Fecha</th><th class="p-3">Obs.</th><?php if ($_SESSION['user_role'] === 'Admin'): ?><th class="p-3">Acciones</th><?php endif; ?></tr></thead><tbody id="operator-history-table-body"></tbody></table></div></div>
            </div>

            <?php if (in_array($_SESSION['user_role'], ['Digitador', 'Admin'])): ?>
            <div id="content-digitador" class="hidden">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-900">Módulo de Digitador: Gestión de Cierre e Informes</h2>
                </div>

                <style>
                    .carousel-container {
                        scroll-behavior: smooth;
                        scrollbar-width: none; /* Firefox */
                    }
                    .carousel-container::-webkit-scrollbar {
                        display: none; /* Safari and Chrome */
                    }
                </style>
                <div class="mb-8">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-900">Alertas y Tareas Prioritarias</h2>
                        <div class="flex space-x-2">
                            <button id="carousel-prev" class="p-2 rounded-full bg-gray-200 hover:bg-gray-300 text-gray-700">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            <button id="carousel-next" class="p-2 rounded-full bg-gray-200 hover:bg-gray-300 text-gray-700">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                    </div>

                    <div id="digitador-carousel" class="carousel-container flex overflow-x-auto space-x-4 pb-4">
                        <?php if (empty($main_priority_items)): ?>
                            <p class="text-sm text-gray-500 bg-white p-4 rounded-lg shadow-sm w-full text-center">No hay items prioritarios pendientes.</p>
                        <?php else: foreach ($main_priority_items as $item): ?>
                            <?php
                                // Same PHP logic as before to prepare variables
                                $is_manual = $item['item_type'] === 'manual_task';
                                $is_group_task = !empty($item['assigned_to_group']);
                                $assigned_names = $item['assigned_names'] ?? null;
                                $task_id_to_use = $item['user_task_id'] ?? $item['task_id'] ?? $item['id'];
                                $alert_id_or_null = $is_manual ? 'null' : ($item['id'] ?? 'null');
                                $form_id_prefix = 'd-'.$task_id_to_use; // Prefix to avoid ID clashes with general panel
                                $is_admin = $current_user_role === 'Admin';
                                $is_assigned_individually = !empty($item['user_task_id']);
                                $is_assigned_to_my_group = $is_group_task && isset($item['assigned_to_group']) && $item['assigned_to_group'] == $current_user_role;
                                $user_can_act = $is_admin || $is_assigned_individually || $is_assigned_to_my_group;
                                $task_is_active = (isset($item['task_status']) && in_array($item['task_status'], ['Pendiente','Media','Alta','Crítica'])) || !isset($item['task_status']);
                                $can_complete = $user_can_act && $task_is_active;
                                $priority_to_use = $item['current_priority'];
                                $color_map = ['Critica' => ['bg' => 'bg-red-100', 'border' => 'border-red-500', 'text' => 'text-red-800', 'badge' => 'bg-red-200'],'Alta' => ['bg' => 'bg-orange-100', 'border' => 'border-orange-500', 'text' => 'text-orange-800', 'badge' => 'bg-orange-200']];
                                $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                            ?>
                            <div class="flex-none w-96">
                                <div class="bg-white rounded-lg shadow-md overflow-hidden task-card h-full flex flex-col" data-task-id="<?php echo $task_id_to_use; ?>">
                                    <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?> flex-grow">
                                        <div class="flex justify-between items-start">
                                            <p class="font-semibold <?php echo $color['text']; ?> text-lg">
                                                <?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?>
                                                <?php if (!empty($item['invoice_number'])): ?> <span class="font-normal text-blue-600 text-sm">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span> <?php endif; ?>
                                                <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full"><?php echo strtoupper($priority_to_use); ?></span>
                                            </p>
                                            <?php if ($can_complete): ?>
                                                <button onclick="toggleForm('complete-form-<?php echo $form_id_prefix; ?>', this)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300 ml-auto" title="Marcar como completada"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                        <?php if (!empty($item['end_datetime'])): ?> <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div> <?php endif; ?>
                                        <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                            <button onclick="toggleForm('assign-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?></button>
                                            <button onclick="toggleForm('reminder-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                            <div class="flex-grow text-right text-sm">
                                                <?php if($is_group_task): ?> <span class="font-semibold text-purple-700">Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></span>
                                                <?php elseif (!empty($assigned_names)): ?> <span class="font-semibold text-green-700"><?php echo htmlspecialchars($assigned_names); ?></span>
                                                <?php else: ?> <span class="font-semibold text-gray-500">Sin Asignar</span> <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="complete-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                        <h4 class="text-sm font-semibold mb-2">Completar Tarea</h4>
                                        <textarea id="resolution-note-<?php echo $form_id_prefix; ?>" rows="2" class="w-full p-2 text-sm border rounded-md" placeholder="Observación de cierre..."></textarea>
                                        <button type="button" onclick="completeTask(<?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-1 mt-2 rounded-md">Confirmar</button>
                                    </div>
                                    <div id="assign-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                        <h4 class="text-sm font-semibold mb-2">Asignar Tarea</h4>
                                        <select id="assign-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md"><optgroup label="Grupos"><option value="group-Operador">Operadores</option><option value="group-Checkinero">Checkineros</option></optgroup><optgroup label="Individuales"><?php foreach ($all_users as $user): ?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option><?php endforeach; ?></optgroup></select>
                                        <textarea id="task-instruction-<?php echo $form_id_prefix; ?>" rows="2" class="w-full p-2 text-sm border rounded-md mt-2" placeholder="Instrucción"><?php echo htmlspecialchars($item['instruction'] ?? ''); ?></textarea>
                                        <button type="button" onclick="submitAssignment(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-blue-600 text-white font-semibold py-1 mt-2 rounded-md">Confirmar</button>
                                    </div>
                                    <div id="reminder-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                        <h4 class="text-sm font-semibold mb-2">Crear Recordatorio</h4>
                                        <select id="reminder-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md"><option value="">Seleccione usuario...</option><?php foreach ($all_users as $user): ?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option><?php endforeach; ?></select>
                                        <button type="button" onclick="setReminder(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-1 mt-2 rounded-md">Crear</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <script>
                    (() => {
                        const carousel = document.getElementById('digitador-carousel');
                        const prevBtn = document.getElementById('carousel-prev');
                        const nextBtn = document.getElementById('carousel-next');

                        if (carousel && prevBtn && nextBtn) {
                            const scrollAmount = 400; // w-96 (384px) + space-x-4 (1rem = 16px) = 400
                            prevBtn.addEventListener('click', () => {
                                carousel.scrollLeft -= scrollAmount;
                            });
                            nextBtn.addEventListener('click', () => {
                                carousel.scrollLeft += scrollAmount;
                            });
                        }
                    })();
                </script>
                            <div class="mb-8 flex space-x-2">
    <button id="btn-supervision-operador" class="px-4 py-2 text-sm font-semibold rounded-md bg-gray-200 text-gray-700">Abrir llegadas de Operador</button>
    
    <button id="btn-cierre" class="px-4 py-2 text-sm font-semibold rounded-md bg-blue-600 text-white">Gestión de Cierre</button>
    <button id="btn-historial-cierre" class="px-4 py-2 text-sm font-semibold rounded-md bg-gray-200 text-gray-700">Historial de Cierres</button>
    <button id="btn-informes" class="px-4 py-2 text-sm font-semibold rounded-md bg-gray-200 text-gray-700">Generar Informes</button>
</div>
<div id="panel-supervision-operador" class="hidden">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Llegadas recientes de Operador</h2>
    <div class="bg-white p-6 rounded-xl shadow-lg mb-8">
        <div class="overflow-auto max-h-[400px]">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="p-3">Planilla</th>
                        <th class="p-3">Cliente</th>
                        <th class="p-3">Valor Declarado</th>
                        <th class="p-3">Valor Contado</th>
                        <th class="p-3">Discrep.</th>
                        <th class="p-3">Operador</th>
                        <th class="p-3">Fecha Conteo</th>
                        <th class="p-3">Obs. Operador</th>
                    </tr>
                </thead>
                <tbody id="digitador-operator-history-tbody">
                    </tbody>
            </table>
        </div>
    </div>
</div>
   

                <div id="panel-cierre">
                     <h2 class="text-2xl font-bold text-gray-900 mb-6">Gestión de Cierre por Fondo</h2>
                     <div class="bg-white p-6 rounded-xl shadow-lg"><h3 class="text-xl font-semibold mb-4 text-gray-900">Proceso de Cierre</h3><p class="text-sm text-gray-500 mb-4">Seleccione un fondo para ver las planillas aprobadas ('Conforme') y proceder con el cierre.</p><div class="grid grid-cols-1 md:grid-cols-2 gap-6"><div><h4 class="font-semibold mb-2">1. Fondos listos para cerrar</h4><div id="funds-list-container" class="space-y-2 max-h-96 overflow-y-auto"></div></div><div><h4 class="font-semibold mb-2">2. Planillas a incluir</h4><div id="services-list-container" class="space-y-3"><p class="text-gray-500 text-sm">Seleccione un fondo.</p></div><button id="close-fund-button" onclick="closeFund()" class="w-full bg-teal-500 text-white font-semibold py-2 px-4 rounded-md hover:bg-teal-600 mt-4 hidden">Cerrar Fondo</button></div></div></div>
                </div>

                 <div id="panel-historial-cierre" class="hidden">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">Historial de Planillas Revisadas/Cerradas</h2>
                    <div class="bg-white p-6 rounded-xl shadow-lg mt-8"><h3 class="text-xl font-semibold mb-4">Historial</h3><div class="overflow-auto max-h-[700px]"><table class="w-full text-sm text-left"><thead class="bg-gray-50 sticky top-0"><tr><th class="p-3">Planilla</th><th class="p-3">Fondo</th><th class="p-3">Cierre</th><th class="p-3">Total Rec.</th><th class="p-3">Discrep.</th><th class="p-3">Estado</th><th class="p-3">Obs.</th><?php if (in_array($_SESSION['user_role'], ['Admin', 'Digitador'])): ?><th class="p-3">Cerrada por</th><?php endif; ?><th class="p-3">Acciones</th></tr></thead><tbody id="digitador-closed-history-body"></tbody></table></div></div>
                </div>

                <div id="panel-informes" class="hidden">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">Generar Informes</h2>
                    <div class="bg-white p-6 rounded-xl shadow-lg"><h3 class="text-xl font-semibold mb-4 text-gray-900">Informes por Fondo (PDF)</h3><p class="text-sm text-gray-500 mb-4">Seleccione un fondo cerrado para generar el informe PDF.</p><div class="overflow-auto max-h-[500px]"><table class="w-full text-sm"><thead class="bg-gray-50 sticky top-0"><tr><th class="p-3 text-left">Fondo</th><th class="p-3 text-left">Cliente</th><th class="p-3 text-left">Fecha Cierre</th><th class="p-3 text-center">Acción</th></tr></thead><tbody id="informes-table-body"></tbody></table></div></div>
                </div>
            </div>
            <?php endif; ?>

            <div id="content-mi-historial" class="hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Mi Historial de Tareas Completadas</h2>
                <p class="text-sm text-gray-500 mb-6">Registro de tareas (manuales y alertas) completadas por ti.</p>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden"><div class="overflow-x-auto"><table id="historial-individual-table" class="w-full text-sm"><thead class="bg-gray-50"><tr class="text-left"><th class="px-6 py-3">Tarea</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">P. Ini.</th><th class="px-6 py-3">P. Fin.</th><th class="px-6 py-3">Asig. por</th><th class="px-6 py-3">Check por</th><th class="px-6 py-3">F. Inicio</th><th class="px-6 py-3">F. Fin</th><th class="px-6 py-3">T. Resp.</th><th class="px-6 py-3">Obs. Cierre</th></tr></thead><tbody id="historial-individual-tbody"></tbody></table></div></div>
            </div>

            <?php if ($_SESSION['user_role'] === 'Admin'): ?>
            <div id="content-roles" class="hidden"><div class="flex justify-between items-center mb-4"><h2 class="text-xl font-bold">Gestionar Usuarios</h2><button onclick="openModal()" class="bg-green-600 text-white font-semibold px-4 py-2 rounded-lg">Agregar Usuario</button></div><div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full text-sm"><thead class="bg-gray-50"><tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">Email</th><th class="px-6 py-3">Rol</th><th class="px-6 py-3 text-center">Sexo</th><th class="px-6 py-3 text-center">Acciones</th></tr></thead><tbody id="user-table-body"></tbody></table></div></div>
            <div id="content-trazabilidad" class="hidden"><h2 class="text-xl font-bold text-gray-900 mb-4">Trazabilidad de Tareas Completadas</h2><div class="bg-white p-4 rounded-xl shadow-sm mb-6"><div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4"><div><label for="filter-start-date" class="text-sm">F. Inicio</label><input type="date" id="filter-start-date" class="mt-1 w-full p-2 border rounded-md text-sm"></div><div><label for="filter-end-date" class="text-sm">F. Fin</label><input type="date" id="filter-end-date" class="mt-1 w-full p-2 border rounded-md text-sm"></div><div><label for="filter-user" class="text-sm">Asignado a</label><select id="filter-user" class="mt-1 w-full p-2 border rounded-md text-sm"><option value="">Todos</option><?php foreach($all_users as $user): ?><option value="<?php echo htmlspecialchars($user['name']); ?>"><?php echo htmlspecialchars($user['name']); ?></option><?php endforeach; ?></select></div><div><label for="filter-checker" class="text-sm">Check por</label><select id="filter-checker" class="mt-1 w-full p-2 border rounded-md text-sm"><option value="">Todos</option><?php foreach($all_users as $user): ?><option value="<?php echo htmlspecialchars($user['name']); ?>"><?php echo htmlspecialchars($user['name']); ?></option><?php endforeach; ?></select></div><div><label for="filter-priority" class="text-sm">P. Final</label><select id="filter-priority" class="mt-1 w-full p-2 border rounded-md text-sm"><option value="">Todas</option><option value="Alta">Alta</option><option value="Media">Media</option><option value="Baja">Baja</option></select></div><div class="flex items-end space-x-2"><button onclick="applyTrazabilidadFilters()" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md">Filtrar</button><button onclick="exportToExcel()" class="w-full bg-green-600 text-white font-semibold py-2 px-4 rounded-md">Excel</button></div></div></div><div class="bg-white rounded-xl shadow-sm overflow-hidden"><div class="overflow-x-auto"><table id="trazabilidad-table" class="w-full text-sm"><thead class="bg-gray-50"><tr class="text-left"><th class="px-6 py-3">Tarea</th><th class="px-6 py-3">Desc.</th><th class="px-6 py-3">P. Ini.</th><th class="px-6 py-3">P. Fin.</th><th class="px-6 py-3 sortable" data-column-name="created_at" onclick="sortTableByDate('created_at')">H. Inicio <span></span></th><th class="px-6 py-3 sortable" data-column-name="completed_at" onclick="sortTableByDate('completed_at')">H. Fin <span></span></th><th class="px-6 py-3">T. Resp.</th><th class="px-6 py-3">Asig. a</th><th class="px-6 py-3">Asig. por</th><th class="px-6 py-3">Check por</th><th class="px-6 py-3">Obs. Cierre</th><th class="px-6 py-3">Acciones</th></tr></thead><tbody id="trazabilidad-tbody"></tbody></table></div></div></div>
            <div id="content-manage-clients" class="hidden"><div class="loader"></div><p class="text-center text-gray-500">Cargando...</p></div>
            <div id="content-manage-routes" class="hidden"><div class="loader"></div><p class="text-center text-gray-500">Cargando...</p></div>
            <div id="content-manage-funds" class="hidden"><div class="loader"></div><p class="text-center text-gray-500">Cargando...</p></div>
            <?php endif; ?>
        </main>
    </div>

    <script>

    // --- Global Variables ---
    const allUsers = <?php echo json_encode($all_users); ?>;
    const adminUsersData = <?php echo json_encode($admin_users_list); ?>;
    const currentUserId = <?php echo $current_user_id; ?>;
    const currentUserRole = '<?php echo $current_user_role; ?>';
    const currentUserName = '<?php echo htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8'); ?>';
    const apiUrlBase = 'api'; // Base para APIs normales
    const apiRealtimeBase = 'api/realtime'; // --- NUEVO: Base para APIs de tiempo real ---
    const initialCheckins = <?php echo json_encode($initial_checkins); ?>;
    const operatorHistoryData = <?php echo json_encode($operator_history); ?>;
    const digitadorClosedHistory = <?php echo json_encode($digitador_closed_history); ?>;
    const completedTasksData = <?php echo json_encode($completed_tasks); ?>;
    const userCompletedTasksData = <?php echo json_encode($user_completed_tasks); ?>;
//let repeatingToasts = new Map(); // Guarda { intervalId, count, alertData } para toasts repetitivos
let lastDiscrepancyIds = new Set();
let lastDiscrepancyCount = 0;
let discrepancySnapshotReady = false;

function canSeeDiscrepancyToasts() {
  const role = (window.currentUserRole || '').toLowerCase();
  return role === 'admin' || role === 'digitador' || role === 'digitadora';
}
// ===== Discrepancias: control anti-spam / multi-pestaña (PÉGALO AQUÍ) =====
// ===== Discrepancias: control anti-spam / multi-pestaña =====
const DISCREP_LS_KEY = 'seen_discrepancy_ids_v1';
const DISCREP_TTL_MS = 5 * 60 * 1000; // “olvida” IDs después de 5 minutos

function loadSeenDiscrepFromLS() {
  try {
    const raw = localStorage.getItem(DISCREP_LS_KEY);
    if (!raw) return { ids: [], ts: Date.now() };
    const data = JSON.parse(raw);
    // TTL: si expiró, resetea
    if (!data.ts || (Date.now() - data.ts) > DISCREP_TTL_MS) return { ids: [], ts: Date.now() };
    return data;
  } catch { return { ids: [], ts: Date.now() }; }
}
function saveSeenDiscrepToLS(ids) {
  try { localStorage.setItem(DISCREP_LS_KEY, JSON.stringify({ ids: Array.from(ids), ts: Date.now() })); } catch {}
}

// Sonido corto de alerta (WebAudio, no bloquea)
function beepOnce() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    o.type = 'sine';
    o.frequency.value = 880;      // tono
    g.gain.setValueAtTime(0.0001, ctx.currentTime);
    g.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
    o.connect(g); g.connect(ctx.destination);
    o.start(); o.stop(ctx.currentTime + 0.26);
  } catch {}
}



// (opcional, recomendado) hidratar estado al cargar
try {
  const boot = loadSeenDiscrepFromLS();
  if (boot?.ids?.length) {
    lastDiscrepancyIds = new Set(boot.ids);
    lastDiscrepancyCount = boot.ids.length;
  }
} catch {}
// Inicializa memoria desde localStorage al cargar
try {
  const boot = loadSeenDiscrepFromLS();
  if (boot?.ids?.length) {
    lastDiscrepancyIds = new Set(boot.ids);
    lastDiscrepancyCount = boot.ids.length;
  }
} catch {}


    let selectedFundForClosure = null;
    let alertPollingInterval = null;
    let checkinPollingInterval = null;
    let trazabilidadPollingInterval = null; // Para trazabilidad
    let lastCheckedAlertTime = Math.floor(Date.now() / 1000);
    let currentCheckinData = JSON.parse(JSON.stringify(initialCheckins));
    let currentFilteredTrazabilidadData = [];
    let loadedContent = {};

    // --- Sets para Tiempo Real ---
    let activeCheckinsIds = new Set(); // Para tabla Checkinero
    let operatorPendingIds = new Set(); // Para tabla Operador (Pendientes)
    let digitadorPendingIds = new Set(); // Para tabla Digitador (Pendientes)
    let lastTrazabilidadTaskId = 0; // Para tabla Trazabilidad (Admin)
// --- Toast Notification Function ---
    function showToast(message, type = 'info', duration = 4000) {
        const container = document.getElementById('toast-container');
        if (!container) {
            console.error('Toast container not found');
            return;
        }

        const toastId = 'toast-' + Date.now();
        const toast = document.createElement('div');
        toast.id = toastId;
        
        // Asignamos clases basadas en el tipo
        let borderColorClass = 'toast-info'; // default
        if (type === 'success') borderColorClass = 'toast-success';
        if (type === 'error') borderColorClass = 'toast-error'; // 'error' para duplicados
        if (type === 'warning') borderColorClass = 'toast-alta'; // 'alta' o 'warning'
        if (type === 'critica') borderColorClass = 'toast-critica';

        toast.className = `toast ${borderColorClass}`; // Usamos las clases del CSS

        const content = document.createElement('div');
        content.className = 'toast-content';

        const title = document.createElement('div');
        title.className = 'toast-title';
        // Capitalizar el tipo para el título
        title.textContent = type.charAt(0).toUpperCase() + type.slice(1);

        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;

        content.appendChild(title);
        content.appendChild(body);

        const closeButton = document.createElement('button');
        closeButton.className = 'toast-close';
        closeButton.innerHTML = '&times;';
        closeButton.onclick = () => closeToast(toastId);

        toast.appendChild(content);
        toast.appendChild(closeButton);

        container.appendChild(toast);

        // Trigger the animation
        setTimeout(() => {
            toast.classList.add('show');
        }, 10); // Small delay

        // Auto-close
        if (duration) {
            setTimeout(() => {
                closeToast(toastId);
            }, duration);
        }
    }
    // (La función closeToast ya la tienes, así que no la duplicamos)

    // === SUBMIT DEL FORM DE CHECK-IN (GUARDA EN LA BD) ===
    async function handleCheckinSubmit(event) {
        event.preventDefault();
        const form = event.target;
        const payload = {
            invoice_number: document.getElementById('invoice_number').value,
            seal_number: document.getElementById('seal_number').value,
            client_id: document.getElementById('client_id').value,
            route_id: document.getElementById('route_id').value,
            fund_id: document.getElementById('fund_id_hidden') ? document.getElementById('fund_id_hidden').value : null,
            declared_value: document.getElementById('declared_value').value
        };

        // Validar que el fondo no esté vacío si el cliente está seleccionado
        if (payload.client_id && !payload.fund_id) {
            showToast('El fondo se está cargando o el cliente no tiene fondo. Intente de nuevo.', 'error');
            return; 
        }
        
        // Validación básica para campos vacíos
        if (!payload.invoice_number || !payload.seal_number || !payload.client_id || !payload.route_id || !payload.declared_value) {
            showToast('Todos los campos son obligatorios.', 'error');
            return;
        }

        try {
            const response = await fetch(`${apiUrlBase}/checkin_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (result.success) {
                // ¡AQUÍ ESTÁ LA NOTIFICACIÓN DE ÉXITO!
                showToast(result.message || 'Check-in guardado con éxito', 'success');
                form.reset(); // Limpia el formulario
                // Resetea el display del fondo
                document.getElementById('fund_display').textContent = 'Seleccione un cliente...';
                document.getElementById('fund_display').classList.add('italic');
                if (document.getElementById('fund_id_hidden')) document.getElementById('fund_id_hidden').value = '';
                
                pollCheckins(); // Actualiza la tabla
            } else {
                // ¡AQUÍ ESTÁ LA NOTIFICACIÓN DE ERROR/DUPLICADO!
                showToast(result.error || 'Error al guardar', 'error');
            }
        } catch (error) {
            console.error('Error saving check-in:', error);
            showToast('Error de conexión al guardar check-in', 'error');
        }
    }
    // --- UI Element References ---
    const remindersPanel = document.getElementById('reminders-panel');
    const taskNotificationsPanel = document.getElementById('task-notifications-panel');
    const mediumPriorityPanel = document.getElementById('medium-priority-panel');
    const modalOverlay = document.getElementById('user-modal-overlay');
    const modalTitle = document.getElementById('modal-title');
    const userForm = document.getElementById('user-form');
    const userIdInput = document.getElementById('user-id');
    const passwordHint = document.getElementById('password-hint');
    const userPasswordInput = document.getElementById('user-password');
    const alertPopupOverlay = document.getElementById('alert-popup-overlay');
    const alertPopup = document.getElementById('alert-popup');
    const alertPopupTitle = document.getElementById('alert-popup-title');
    const alertPopupDescription = document.getElementById('alert-popup-description');
    const alertPopupHeader = document.getElementById('alert-popup-header');

    // --- UI Interaction Functions ---
     // Function to toggle notification panels
    function togglePanel(panelId) {
         const panel = document.getElementById(panelId);
         if (!panel) return;

         // Usar classList.toggle() es más simple, pero para asegurar que solo uno esté abierto:
         const isHidden = panel.classList.contains('hidden');

         // Close all panels
         document.getElementById('reminders-panel')?.classList.add('hidden');
         document.getElementById('task-notifications-panel')?.classList.add('hidden');
         document.getElementById('medium-priority-panel')?.classList.add('hidden');

         // Toggle the clicked one
         if (isHidden) {
             panel.classList.remove('hidden'); // Use classList
         }
         // Si ya estaba abierto (isHidden=false), el bucle anterior ya lo cerró.
     }
     function closeModal() { modalOverlay?.classList.add('hidden'); }
     function closeAlertPopup() {
        if (!alertPopupOverlay || !alertPopup) return;
        alertPopup.classList.add('scale-95', 'opacity-0');
        alertPopup.classList.remove('scale-100', 'opacity-100');
        setTimeout(() => { alertPopupOverlay.classList.add('hidden'); }, 300);
     }
     function toggleForm(formId, button) {
         const form = document.getElementById(formId);
         if (!form) {
              console.error(`Formulario con ID ${formId} no encontrado.`);
              return;
         }
        const parentItem = button.closest('.task-card');
        parentItem.querySelectorAll('.task-form').forEach(f => {
            if (f.id !== formId && f.classList.contains('active')) {
                f.classList.remove('active');
            }
        });
        form.classList.toggle('active');
     }
     function toggleBreakdown(id) {
        const row = document.getElementById(`breakdown-row-${id}`);
        const content = document.getElementById(`breakdown-content-${id}`);
        row?.classList.toggle('hidden');
        if (content) setTimeout(() => content.classList.toggle('active'), 10);
     }

    // --- Data Formatting ---
    function formatCurrency(value) {
       const numberValue = Number(value);
       if (isNaN(numberValue)) return '$ 0';
       return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(numberValue);
    }
    function formatDate(dateString) {
       if (!dateString) return '';
       try {
           const date = new Date(dateString); if (isNaN(date)) return '';
           return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' }) + ' ' + date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
       } catch (e) { return ''; }
    }
// === Dibuja la tabla de "Últimos Check-ins" (Pendientes/Rechazados) ===
function populateCheckinsTable(rows) {
  const tbody = document.getElementById('checkins-table-body');
  if (!tbody) return;
  tbody.innerHTML = '';

  // Si quieres ver SOLO Pendientes/Rechazados:
  const visibles = Array.isArray(rows)
    ? rows.filter(r => ['Pendiente','Rechazado'].includes((r.status || '').trim()))
    : [];

  if (visibles.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="11" class="text-center text-gray-500 py-6">
        No hay registros de check-in.
      </td></tr>`;
    return;
  }

  visibles.forEach(r => {
    const tr = document.createElement('tr');
    tr.className = 'border-b';

    const corr = (r.correction_count ?? 0);
    const fecha = r.created_at ? new Date(r.created_at).toLocaleString('es-CO') : '';

    tr.innerHTML = `
      <td class="px-3 py-2 text-center font-mono">${corr}</td>
      <td class="px-3 py-2">${r.status || ''}</td>
      <td class="px-3 py-2 font-mono">${r.invoice_number || ''}</td>
      <td class="px-3 py-2 font-mono">${r.seal_number || ''}</td>
      <td class="px-3 py-2 font-mono">${Number(r.declared_value || 0).toLocaleString('es-CO')}</td>
      <td class="px-3 py-2">${r.route_name || ''}</td>
      <td class="px-3 py-2 text-xs">${fecha}</td>
      <td class="px-3 py-2">${r.checkinero_name || ''}</td>
      <td class="px-3 py-2">${r.client_name || ''}</td>
      <td class="px-3 py-2">${r.fund_name || 'N/A'}</td>
      <td class="px-3 py-2 text-right">
         <!-- aquí puedes poner botones si quieres -->
      </td>
    `;
    tbody.appendChild(tr);
  });
}


    function renderCheckinsTable() {
  const tbody = document.getElementById('checkins-table-body');
  if (!tbody) return;

  // Mostramos Pendiente, Rechazado, Procesado y Discrepancia
  const visibleStatuses = new Set(['Pendiente','Rechazado','Procesado','Discrepancia']);
  const rows = (currentCheckinData || []).filter(ci => visibleStatuses.has(ci.status));

  if (rows.length === 0) {
  const colspan = currentUserRole === 'Admin' ? 12 : 11;
tbody.innerHTML = `<tr><td colspan="${colspan}"class="px-6 py-4 text-center text-gray-500">
        No hay registros pendientes, rechazados o procesados.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(ci => {
    const dateTxt = formatDate(ci.created_at);
    const fund = ci.fund_name || 'N/A';
    return `
      <tr class="border-b">
        <td class="p-3 font-mono">${ci.invoice_number ?? ''}</td>
        <td class="p-3">${ci.client_name ?? ''}</td>
        <td class="p-3">${ci.route_name ?? ''}</td>
        <td class="p-3">${fund}</td>
        <td class="p-3">${ci.status}</td>
        <td class="p-3 text-xs">${dateTxt}</td>
      </tr>`;
  }).join('');
}

// Lánzalo al cargar:
document.addEventListener('DOMContentLoaded', renderCheckinsTable);

// Si tienes polling en “tiempo real”, actualiza la tabla al refrescar datos:
async function refreshCheckinsRealtime() {
  try {
    const resp = await fetch(`${apiRealtimeBase}/realtime_checkins_api.php`);
    const json = await resp.json();
    if (json && json.success && Array.isArray(json.data)) {
      currentCheckinData = json.data;
      renderCheckinsTable();
    }
  } catch (e) { console.error('realtime_checkins:', e); }
}
// Arranque y cada 15s (ajusta si quieres)
refreshCheckinsRealtime();
checkinPollingInterval = setInterval(refreshCheckinsRealtime, 15000);

    function getPriorityClass(priority) {
       if (priority === 'Alta' || priority === 'Critica') return 'bg-red-100 text-red-800';
       if (priority === 'Media') return 'bg-yellow-100 text-yellow-800';
       return 'bg-gray-100 text-gray-800';
    }
    function getRoleDisplayNameJS(role, gender) {
       if (gender === 'F') {
           switch (role) {
               case 'Digitador': return 'Digitadora';
               case 'Operador': return 'Operadora';
               case 'Checkinero': return 'Checkinera';
               default: return role;
           }
       }
       return role;
    }

    // --- API Call Functions ---
    async function deleteReminder(reminderId, button) {
        try {
            const response = await fetch(`${apiUrlBase}/alerts_api.php?reminder_id=${reminderId}`, { method: 'DELETE' });
            const result = await response.json();
            if (result.success) {
                button.closest('.reminder-item').remove();
                updateReminderCount();
            } else { alert('Error: ' + result.error); }
        } catch (error) { console.error('Error deleting reminder:', error); alert('Error de conexión.'); }
    }

async function completeTask(taskId, formIdPrefix) {
    const noteTextarea = document.getElementById(`resolution-note-${formIdPrefix}`);
    const resolution_note = noteTextarea ? noteTextarea.value : '';

        if (!resolution_note.trim()) {
            alert('Por favor, ingrese una observación de cierre.');
            if (noteTextarea) noteTextarea.focus();
            return;
        }

        if (!confirm('¿Estás seguro de que quieres marcar esta tarea como completada?')) return;

        try {
            const response = await fetch(`${apiUrlBase}/task_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    task_id: taskId,
                    resolution_note: resolution_note
                })
            });
            const result = await response.json();
            if (result.success) {
                alert('Tarea completada con éxito.');
                location.reload();
            } else { alert('Error al completar la tarea: ' + result.error); }
        } catch (error) { console.error('Error completing task:', error); alert('Error de conexión.'); }
    }

    async function submitAssignment(alertId, taskId, formIdPrefix) {
        const assignSelect = document.getElementById(`assign-user-${formIdPrefix}`);
        const instructionTextarea = document.getElementById(`task-instruction-${formIdPrefix}`);

         if (!assignSelect || !instructionTextarea) {
              console.error("Elementos de formulario no encontrados para asignar tarea.");
              return;
         }

        const selectedValue = assignSelect.value;
        const instruction = instructionTextarea.value;

        // Validar que se seleccionó algo
        if (!selectedValue) {
             alert('Por favor, selecciona un usuario o grupo para asignar.');
             return;
        }

        let payload = {
            instruction: instruction,
            type: 'Asignacion', // Siempre es 'Asignacion' al (re)asignar desde la interfaz de tareas
            task_id: taskId,
            alert_id: alertId
        };
        if (selectedValue.startsWith('group-')) {
             payload.assign_to_group = selectedValue.replace('group-', '');
             delete payload.assign_to; // Asegurar que no se envíe asignación individual
        } else {
             payload.assign_to = selectedValue;
             delete payload.assign_to_group; // Asegurar que no se envíe asignación de grupo
        }
        await sendTaskRequest(payload);
    }

    async function setReminder(alertId, taskId, formIdPrefix) {
        const reminderSelect = document.getElementById(`reminder-user-${formIdPrefix}`);
        if (!reminderSelect) {
            console.error("Elementos de formulario de recordatorio no encontrados.");
            return;
        }
        const userId = reminderSelect.value;

        if (!userId) {
            alert('Por favor, selecciona un usuario para el recordatorio.');
            return;
        }

        await sendTaskRequest({
            assign_to: userId,
            type: 'Recordatorio',
            task_id: taskId,
            alert_id: alertId,
            notify_by_email: true // Siempre notificar por correo
        });
    }

    async function sendTaskRequest(payload) {
        try {
            const response = await fetch(`${apiUrlBase}/alerts_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (!response.ok) {
                const errorResult = await response.json().catch(() => ({ error: `Error HTTP ${response.status}` }));
                 throw new Error(errorResult.error || `Error HTTP ${response.status}`);
            }
            const result = await response.json();
            if (result.success) {
                alert('Acción completada con éxito.');
                location.reload();
            } else {
                alert('Error desde la API: ' + result.error);
            }
        } catch (error) { console.error('Error en sendTaskRequest:', error); alert(`Error de conexión: ${error.message}`); }
    }

// === SUBMIT DEL FORM DE CHECK-IN (GUARDA EN LA BD) ===

async function handleCheckinSubmit(event) {
        event.preventDefault();
        const form = event.target;
        const payload = {
            invoice_number: document.getElementById('invoice_number').value,
            seal_number: document.getElementById('seal_number').value,
            client_id: document.getElementById('client_id').value,
            route_id: document.getElementById('route_id').value,
            // Corregido para leer el input oculto
            fund_id: document.getElementById('fund_id_hidden') ? document.getElementById('fund_id_hidden').value : null,
            declared_value: document.getElementById('declared_value').value
        };

        // Validar que el fondo no esté vacío si el cliente está seleccionado
        if (payload.client_id && !payload.fund_id) {
            showToast('El fondo se está cargando o el cliente no tiene fondo. Intente de nuevo.', 'error');
            return; 
        }

        try {
            const response = await fetch(`${apiUrlBase}/checkin_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                showToast(result.message || 'Check-in guardado', 'success');
                form.reset(); // Limpia el formulario
                // Resetea el display del fondo
                document.getElementById('fund_display').textContent = 'Seleccione un cliente...';
                document.getElementById('fund_display').classList.add('italic');
                if (document.getElementById('fund_id_hidden')) document.getElementById('fund_id_hidden').value = '';
                
                pollCheckins(); // Actualiza la tabla
            } else {
                showToast(result.error || 'Error al guardar', 'error');
            }
        } catch (error) {
            console.error('Error saving check-in:', error);
            showToast('Error de conexión al guardar check-in', 'error');
        }
    }



    document.getElementById('manual-task-form')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const title = document.getElementById('manual-task-title').value;
        const instruction = document.getElementById('manual-task-desc').value;
        const selectedValue = document.getElementById('manual-task-user').value;
        const priority = document.getElementById('manual-task-priority').value;
        const start_datetime = document.getElementById('manual-task-start').value;
        const end_datetime = document.getElementById('manual-task-end').value;

        if (!selectedValue) { alert('Selecciona un usuario o grupo.'); return; }
        if (start_datetime && end_datetime && start_datetime >= end_datetime) { alert('La fecha de fin debe ser posterior a la fecha de inicio.'); return; }

        let payload = { title, instruction, type: 'Manual', priority, start_datetime: start_datetime || null, end_datetime: end_datetime || null, notify_by_email: true };
        if (selectedValue.startsWith('group-')) {
             payload.assign_to_group = selectedValue.replace('group-', '');
        } else {
             payload.assign_to = selectedValue;
        }

        await sendTaskRequest(payload); // Reutilizar la función sendTaskRequest
    });


    userForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        try {
            const response = await fetch(`${apiUrlBase}/users_api.php`, { method: 'POST', body: new FormData(userForm) });
             const result = await response.json(); // Leer la respuesta independientemente del status

             if (!response.ok) {
                 throw new Error(result.error || `Error HTTP ${response.status}`);
             }

            if (result.success) {
                // showToast(result.message || 'Conteo guardado correctamente.', 'success'); // <-- Esto estaba mal
                closeModal(); // <-- Esto es lo que estaba antes
                setTimeout(() => location.reload(), 100); // <-- Recargar
            } else { // <--- AÑADIR 'else'
                alert('Error al guardar: ' + result.error);
            } // <--- AÑADIR LLAVE DE CIERRE
        } catch (error) {
        // ... (resto sin cambios) ...
             console.error("Error en submit de formulario de usuario:", error);
             alert(`Error de conexión o del servidor: ${error.message}`);
        }
    });

    async function deleteUser(id) {
        if (!confirm('¿Eliminar usuario? Esta acción también eliminará sus tareas y recordatorios asociados.')) return;
        try {
            const response = await fetch(`${apiUrlBase}/users_api.php`, {
                 method: 'DELETE',
                 headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                 body: `id=${id}`
            });
            const result = await response.json();
            if (result.success) {
                 const row = document.getElementById(`user-row-${id}`);
                 if (row) row.remove();
                 alert(result.message || 'Usuario eliminado.');
                 setTimeout(() => location.reload(), 500);
            } else {
                 alert('Error al eliminar: ' + result.error);
            }
         }catch (error) {
             console.error("Error en deleteUser:", error);
             alert(`Error de conexión al eliminar: ${error.message}`);
            }
    
        } 
    
     
    
    

        async function handleConsultation(event) {
        event.preventDefault();
        const invoiceInput = document.getElementById('consult-invoice');
        const operatorPanel = document.getElementById('operator-panel');
        if (!invoiceInput.value) { alert('Por favor, ingrese un número de planilla.'); return; }
        try {
            const response = await fetch(`api/operator_api.php?planilla=${invoiceInput.value}`);
            const result = await response.json();
            if (result.success) {
                const data = result.data;
                document.getElementById('display-invoice').textContent = data.invoice_number;
                document.getElementById('display-seal').textContent = data.seal_number;
                document.getElementById('display-client').textContent = data.client_name;
                document.getElementById('display-declared').textContent = formatCurrency(data.declared_value);
                document.getElementById('display-declared').dataset.value = data.declared_value;
                document.getElementById('op-checkin-id').value = data.id;
                document.getElementById('denomination-form').reset();
                calculateTotals();
                operatorPanel.classList.remove('hidden');
            } else { alert('Error: ' + result.error); operatorPanel.classList.add('hidden'); }
        } catch (error) { console.error('Error en la consulta:', error); alert('Error de conexión.'); }
    }
    function animateProgressBar(callback) {
        const progressBar = document.getElementById('progress-bar');
        const progressBarText = document.getElementById('progress-bar-text');
        let width = 0;
        progressBar.style.width = '0%';
        progressBarText.textContent = '0%';

        const interval = setInterval(() => {
            if (width >= 100) {
                clearInterval(interval);
                if (callback) {
                    // Short delay to ensure 100% is visible before executing callback
                    setTimeout(callback, 100);
                }
            } else {
                width++;
                progressBar.style.width = width + '%';
                progressBarText.textContent = width + '%';
            }
        }, 60); // 60ms * 100 = 6000ms = 6 seconds total
    }

    async function handleDenominationSave(event) {
        event.preventDefault();
        const loadingOverlay = document.getElementById('loading-overlay');
        loadingOverlay.classList.remove('hidden');

        animateProgressBar(async () => {
            const payload = {
            check_in_id: document.getElementById('op-checkin-id').value,
            bills_100k: parseInt(document.querySelector('#denomination-form [data-value="100000"] .denomination-qty').value) || 0,
            bills_50k: parseInt(document.querySelector('#denomination-form [data-value="50000"] .denomination-qty').value) || 0,
            bills_20k: parseInt(document.querySelector('#denomination-form [data-value="20000"] .denomination-qty').value) || 0,
            bills_10k: parseInt(document.querySelector('#denomination-form [data-value="10000"] .denomination-qty').value) || 0,
            bills_5k: parseInt(document.querySelector('#denomination-form [data-value="5000"] .denomination-qty').value) || 0,
            bills_2k: parseInt(document.querySelector('#denomination-form [data-value="2000"] .denomination-qty').value) || 0,
            coins: parseFloat(document.getElementById('coins-value').value) || 0,
            total_counted: 0, discrepancy: 0, observations: document.getElementById('observations').value
        };
        let total = (payload.bills_100k * 100000) + (payload.bills_50k * 50000) + (payload.bills_20k * 20000) + (payload.bills_10k * 10000) + (payload.bills_5k * 5000) + (payload.bills_2k * 2000) + payload.coins;
        payload.total_counted = total;
        payload.discrepancy = total - (parseFloat(document.getElementById('display-declared').dataset.value) || 0);

        try {
            const response = await fetch('api/operator_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const result = await response.json();

            if (result.success) {
                showToast(result.message || 'Conteo guardado correctamente.', 'success');
                pollAlerts();

                // 1. Ocultar y resetear el panel del operador
                document.getElementById('operator-panel').classList.add('hidden');
                document.getElementById('denomination-form').reset();
                calculateTotals();

                // 2. Añadir el nuevo registro a la tabla de historial
                const historyTbody = document.getElementById('operator-history-table-body');
                if (historyTbody) {
                    const noRecordsRow = historyTbody.querySelector('td[colspan]');
                    if (noRecordsRow) noRecordsRow.parentElement.remove();

                    const newItem = {
                        check_in_id: payload.check_in_id,
                        invoice_number: document.getElementById('display-invoice').textContent,
                        client_name: document.getElementById('display-client').textContent,
                        declared_value: parseFloat(document.getElementById('display-declared').dataset.value) || 0,
                        total_counted: payload.total_counted,
                        discrepancy: payload.discrepancy,
                        operator_name: window.currentUserName,
                        count_date: new Date().toISOString(),
                        observations: payload.observations
                    };

                    const discrepancyClass = newItem.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
                    const showOperatorColumn = (currentUserRole === 'Admin' || currentUserRole === 'Digitador');
                    const operatorColumn = showOperatorColumn ? `<td class="p-3">${newItem.operator_name}</td>` : '';
                    const adminDeleteButton = currentUserRole === 'Admin' ? `<td class="p-3 text-center"><button onclick="deleteCheckIn(${newItem.check_in_id})" class="text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button></td>` : '';

                    const newRowHtml = `
                        <tr class="border-b" style="background-color: #fefce8;">
                            <td class="p-3 font-mono">${newItem.invoice_number}</td>
                            <td class="p-3">${newItem.client_name}</td>
                            <td class="p-3 text-right">${formatCurrency(newItem.declared_value)}</td>
                            <td class="p-3 text-right">${formatCurrency(newItem.total_counted)}</td>
                            <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(newItem.discrepancy)}</td>
                            ${operatorColumn}
                            <td class="p-3 text-xs whitespace-nowrap">${new Date(newItem.count_date).toLocaleString('es-CO')}</td>
                            <td class="p-3 text-xs max-w-xs truncate" title="${newItem.observations || ''}">${newItem.observations || 'N/A'}</td>
                            ${adminDeleteButton}
                        </tr>`;

                    historyTbody.insertAdjacentHTML('afterbegin', newRowHtml);
                    setTimeout(() => {
                        const newRow = historyTbody.querySelector('tr[style]');
                        if (newRow) newRow.style.backgroundColor = '';
                    }, 3000);
                }

                // 3. Eliminar la planilla de la tabla de pendientes (si es visible para el Admin)
                const pendingRowToRemove = document.getElementById(`operator-pending-row-${payload.check_in_id}`);
                if (pendingRowToRemove) {
                    pendingRowToRemove.remove();
                    const pendingTbody = document.getElementById('operator-checkins-table-body');
                    if (pendingTbody && pendingTbody.children.length === 0) {
                        pendingTbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay planillas pendientes por detallar.</td></tr>';
                    }
                }
                 // También se actualiza la tabla de historial para el digitador.
                const digitadorHistoryTbody = document.getElementById('digitador-operator-history-tbody');
                if(digitadorHistoryTbody) {
                    const noRecordsRow = digitadorHistoryTbody.querySelector('td[colspan]');
                    if (noRecordsRow) noRecordsRow.parentElement.remove();

                    const newItem = {
                        check_in_id: payload.check_in_id,
                        invoice_number: document.getElementById('display-invoice').textContent,
                        client_name: document.getElementById('display-client').textContent,
                        declared_value: parseFloat(document.getElementById('display-declared').dataset.value) || 0,
                        total_counted: payload.total_counted,
                        discrepancy: payload.discrepancy,
                        operator_name: window.currentUserName,
                        count_date: new Date().toISOString(),
                        observations: payload.observations
                    };
                     const discrepancyClass = newItem.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
                     const newRowHtml = `
                        <tr class="border-b" style="background-color: #fefce8;">
                            <td class="p-3 font-mono">${newItem.invoice_number}</td>
                            <td class="p-3">${newItem.client_name}</td>
                            <td class="p-3 text-right">${formatCurrency(newItem.declared_value)}</td>
                            <td class="p-3 text-right">${formatCurrency(newItem.total_counted)}</td>
                            <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(newItem.discrepancy)}</td>
                            <td class="p-3">${newItem.operator_name}</td>
                            <td class="p-3 text-xs whitespace-nowrap">${new Date(newItem.count_date).toLocaleString('es-CO')}</td>
                            <td class="p-3 text-xs max-w-xs truncate" title="${newItem.observations || ''}">${newItem.observations || 'N/A'}</td>
                        </tr>`;

                    digitadorHistoryTbody.insertAdjacentHTML('afterbegin', newRowHtml);
                     setTimeout(() => {
                        const newRow = digitadorHistoryTbody.querySelector('tr[style]');
                        if (newRow) newRow.style.backgroundColor = '';
                    }, 3000);
                }


            } else {
                showToast(result.error || 'Error al guardar el conteo', 'error');
            }
        } catch (error) {
            console.error('Error al guardar conteo:', error);
            showToast('Error de conexión al guardar conteo', 'error');
        } finally {
            loadingOverlay.classList.add('hidden');
        }
    });
}



    async function deleteCheckIn(checkInId) {
        if (!confirm('¿Está seguro de que desea eliminar permanentemente esta planilla y todos sus registros asociados (conteo, alertas, etc.)? Esta acción no se puede deshacer.')) {
            return;
        }
        try {
            const response = await fetch(`${apiUrlBase}/delete_checkin_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ check_in_id: checkInId })
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert('Error al eliminar: ' + result.error);
            }
        } catch (error) {
            console.error('Error de conexión al eliminar la planilla:', error);
            alert('Error de conexión. No se pudo completar la solicitud.');
        }
    }
    async function deleteTask(taskId) {
        if (!confirm('¿Está seguro de que desea eliminar este registro de tarea del historial de trazabilidad?')) {
            return;
        }
        try {
            const response = await fetch(`${apiUrlBase}/delete_task_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: taskId })
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert('Error al eliminar la tarea: ' + result.error);
            }
        } catch (error) {
            console.error('Error de conexión al eliminar la tarea:', error);
            alert('Error de conexión.');
        }
    }
    async function closeFund() {
        if (!selectedFundForClosure) {
            alert('Por favor, seleccione un fondo primero.');
            return;
        }
        if (!confirm('¿Está seguro de que desea cerrar este fondo? Todas las planillas aprobadas se marcarán como cerradas y pasarán a informes.')) return;

        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=close_fund`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fund_id: selectedFundForClosure })
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'No se pudo cerrar el fondo.'));
            }
        } catch (error) {
            console.error('Error al cerrar fondo:', error);
            alert('Error de conexión.');
        }
    }
    async function loadFundsForCierre() {
        const container = document.getElementById('funds-list-container');
        if (!container) return;
        container.innerHTML = '<p class="text-center text-sm text-gray-500">Cargando fondos...</p>';
        document.getElementById('services-list-container').innerHTML = '<p class="text-gray-500 text-sm">Seleccione un fondo de la lista.</p>';
        document.getElementById('close-fund-button').classList.add('hidden');
        selectedFundForClosure = null;

        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=list_funds_to_close`);
            const funds = await response.json();
            container.innerHTML = '';
            if (funds.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm text-center">No hay fondos listos para cerrar.</p>';
                return;
            }
            funds.forEach(fund => {
                container.innerHTML += `<div id="fund-to-close-${fund.id}" class="p-3 border rounded-lg cursor-pointer hover:bg-gray-100" onclick="loadServicesForFund(${fund.id}, this)">
                                            <p class="font-semibold">${fund.name}</p>
                                            <span class="text-xs text-gray-500">${fund.client_name}</span>
                                        </div>`;
            });
        } catch (error) {
            console.error('Error cargando fondos para cierre:', error);
            container.innerHTML = '<p class="text-center text-red-500 text-sm">Error al cargar fondos.</p>';
        }
    }
    async function loadServicesForFund(fundId, element) {
        selectedFundForClosure = fundId;
        document.querySelectorAll('#funds-list-container > div').forEach(el => el.classList.remove('bg-blue-100', 'border-blue-400'));
        element.classList.add('bg-blue-100', 'border-blue-400');

        const container = document.getElementById('services-list-container');
        container.innerHTML = '<p class="text-center text-sm text-gray-500">Cargando planillas...</p>';

        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=get_services_for_closing&fund_id=${fundId}`);
            const services = await response.json();
            container.innerHTML = '';
            if (services.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm">Este fondo no tiene planillas aprobadas.</p>';
                document.getElementById('close-fund-button').classList.add('hidden');
                return;
            }

            let total = 0;
            services.forEach(service => {
                total += parseFloat(service.total_counted);
                container.innerHTML += `<div class="p-2 border-b text-sm">
                                            <div class="flex justify-between">
                                                <span class="font-mono">#${service.invoice_number}</span>
                                                <span class="font-medium">${formatCurrency(service.total_counted)}</span>
                                            </div>
                                        </div>`;
            });
            container.innerHTML += `<div class="p-2 text-sm font-bold border-t-2 border-gray-500">
                                        <div class="flex justify-between">
                                            <span>Total Fondo:</span>
                                            <span>${formatCurrency(total)}</span>
                                        </div>
                                    </div>`;
            document.getElementById('close-fund-button').classList.remove('hidden');
        } catch (error) {
            console.error('Error cargando servicios:', error);
            container.innerHTML = '<p class="text-center text-red-500 text-sm">Error al cargar servicios.</p>';
        }
    }
   async function loadInformes() {
        const tbody = document.getElementById('informes-table-body');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-sm text-gray-500">Cargando informes...</td></tr>';

        try {
            const response = await fetch(`${apiUrlBase}/digitador_informes_api.php?action=list_closed_funds`);
            const funds = await response.json();
            tbody.innerHTML = '';
            if (funds.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-sm text-gray-500">No hay fondos cerrados para informar.</td></tr>';
                return;
            }
            funds.forEach(fund => {
                // fund.close_date vendrá como 'YYYY-MM-DD'
                // Sumamos un día a la fecha (zona horaria) para que new Date() la interprete correctamente como fecha local
                const dateParts = fund.close_date.split('-');
                const closeDateObj = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
                const closeDateFormatted = closeDateObj.toLocaleDateString('es-CO', { year: 'numeric', month: 'long', day: 'numeric', timeZone: 'America/Bogota' });

                tbody.innerHTML += `<tr class="border-b">
                                        <td class="p-3 font-semibold">${fund.fund_name}</td>
                                        <td class="p-3">${fund.client_name}</td>
                                        <td class="p-3 text-xs font-medium">${closeDateFormatted}</td>
                                        <td class="p-3 text-center">
                                            <button onclick="generatePDF(${fund.id}, '${fund.fund_name.replace(/'/g, "\\'")}', '${fund.client_name.replace(/'/g, "\\'")}', '${fund.close_date}')" class="bg-green-600 text-white font-bold py-1 px-3 rounded-md hover:bg-green-700 text-xs">
                                                Generar PDF
                                            </button>
                                        </td>
                                    </tr>`;
            });
        } catch (error) {
            console.error('Error cargando informes:', error);
            tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-red-500 text-sm">Error al cargar informes.</td></tr>';
        }
    }
  async function generatePDF(fundId, fundName, clientName, closeDate) { // <-- 1. AÑADIMOS closeDate
    if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
        alert('Error: La librería jsPDF no se cargó.');
        return;
    }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();

    if (typeof doc.autoTable === 'undefined') {
        alert('Error: La extensión autoTable para PDF no se cargó.');
        return;
    }

    try {
        // 2. AÑADIMOS close_date AL FETCH
        const response = await fetch(`${apiUrlBase}/digitador_informes_api.php?action=get_report_details&fund_id=${fundId}&close_date=${closeDate}`);
        const planillas = await response.json();

        if (planillas.length === 0) {
            alert('No se encontraron datos para este informe.');
            return;
        }

        const head = [['Planilla', 'V. Contado', 'Discrepancia', 'Operador', 'Desglose de Billetes/Monedas']];
        const body = [];
        let totalContado = 0;
        let totalDiscrepancia = 0;

        planillas.forEach(p => {
            let desgloseText = [
                `$100.000: ${p.bills_100k || 0}`,
                `$50.000: ${p.bills_50k || 0}`,
                `$20.000: ${p.bills_20k || 0}`,
                `$10.000: ${p.bills_10k || 0}`,
                `$5.000: ${p.bills_5k || 0}`,
                `$2.000: ${p.bills_2k || 0}`,
                `Monedas: ${formatCurrency(p.coins)}`
            ].join('\n');

            body.push([
                p.planilla,
                formatCurrency(p.total),
                formatCurrency(p.discrepancy),
                p.operador,
                desgloseText
            ]);
            totalContado += parseFloat(p.total);
            totalDiscrepancia += parseFloat(p.discrepancy);
        });

        body.push([
            { content: 'TOTALES', styles: { fontStyle: 'bold', halign: 'right' } },
            { content: formatCurrency(totalContado), styles: { fontStyle: 'bold' } },
            { content: formatCurrency(totalDiscrepancia), styles: { fontStyle: 'bold', textColor: totalDiscrepancia != 0 ? [220, 38, 38] : [22, 163, 74] } },
            { content: '', colSpan: 2 }
        ]);

        // 3. MOSTRAMOS LA FECHA DE CIERRE EN EL PDF
        const dateParts = closeDate.split('-');
        // Se suma 1 al mes porque new Date() los toma de 0-11
        const closeDateObj = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
        const reportDateFormatted = closeDateObj.toLocaleDateString('es-CO', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric', 
            timeZone: 'America/Bogota' // Asegura la zona horaria correcta
        });

        doc.setFontSize(18);
        doc.text(`Informe de Cierre: ${fundName}`, 14, 22);
        doc.setFontSize(11);
        doc.text(`Cliente: ${clientName}`, 14, 30);
        doc.text(`Fecha de Cierre (Día): ${reportDateFormatted}`, 14, 36); // <-- Fecha del reporte

        doc.autoTable({
            head: head,
            body: body,
            startY: 42,
            headStyles: { fillColor: [29, 78, 216] },
            columnStyles: {
                0: { cellWidth: 20 },
                1: { cellWidth: 30, halign: 'right' },
                2: { cellWidth: 30, halign: 'right' },
                3: { cellWidth: 30 },
                4: { fontSize: 8, cellWidth: 'auto' }
            }
        });

        const pageCount = doc.internal.getNumberOfPages();
        for(let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(9);
            doc.text(`Generado por EAGLE 3.0 - Página ${i} de ${pageCount}`, 14, doc.internal.pageSize.height - 10);
        }

        // 4. AÑADIMOS LA FECHA AL NOMBRE DEL ARCHIVO
        doc.save(`Informe_${fundName.replace(/ /g, '_')}_${closeDate}.pdf`);

    } catch (error) {
        console.error('Error generando PDF:', error);
        alert('No se pudo generar el informe en PDF.');
    }
}

    // --- Table Population & UI Update Functions ---
    function updateReminderCount() {
        const list = document.getElementById('reminders-list');
        const badge = document.getElementById('reminders-badge');
        if (!list || !badge) return; // Salir si los elementos no existen
        const count = list.getElementsByClassName('reminder-item').length;

        if (count > 0) {
            badge.textContent = count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
            list.innerHTML = '<p class="text-sm text-gray-500">No tienes recordatorios pendientes.</p>';
        }
    }
    function openModal(user = null) {
        userForm.reset();
        if (user) {
            modalTitle.textContent = 'Editar Usuario';
            userIdInput.value = user.id;
            document.getElementById('user-name').value = user.name;
            document.getElementById('user-email').value = user.email;
            document.getElementById('user-role').value = user.role;
            document.getElementById('user-gender').value = user.gender || ''; // Usar '' si es null
            userPasswordInput.required = false;
            passwordHint.textContent = 'Dejar en blanco para no cambiar.';
        } else {
            modalTitle.textContent = 'Agregar Nuevo Usuario';
            userIdInput.value = '';
            document.getElementById('user-gender').value = '';
            userPasswordInput.required = true;
            passwordHint.textContent = 'La contraseña es requerida.';
        }
        modalOverlay.classList.remove('hidden');
        }
        function populateUserTable(users) {handleConsultation
        const tbody = document.getElementById('user-table-body');
        if (!tbody) {
            console.error("Elemento tbody 'user-table-body' no encontrado.");
            return;
        }
        tbody.innerHTML = '';
        if (!users || users.length === 0) {
             tbody.innerHTML = '<tr><td colspan="5" class="p-6 text-center text-gray-500">No hay usuarios registrados.</td></tr>'; // Colspan a 5
             return;
        }
        users.forEach(user => {
             if (!user || typeof user !== 'object') {
                  console.warn("Dato de usuario inválido:", user);
                  return; // Saltar este usuario si es inválido
             }
            const safeUser = {
                id: user.id,
                name: user.name || '',
                email: user.email || '',
                role: user.role || '',
                gender: user.gender || ''
            };
            const userJson = JSON.stringify(safeUser).replace(/'/g, "&apos;");
            const displayRole = getRoleDisplayNameJS(safeUser.role, safeUser.gender);
            tbody.innerHTML += `
                <tr id="user-row-${safeUser.id}">
                    <td class="px-6 py-4">${safeUser.name}</td>
                    <td class="px-6 py-4">${safeUser.email}</td>
                    <td class="px-6 py-4"><span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">${displayRole}</span></td>
                    <td class="px-6 py-4 text-center">${safeUser.gender || 'N/A'}</td> <td class="px-6 py-4 text-center">
                        <button onclick='openModal(${userJson})' class="font-medium text-blue-600 hover:text-blue-800">Editar</button>
                        <button onclick="deleteUser(${safeUser.id})" class="font-medium text-red-600 hover:text-red-800 ml-4">Eliminar</button>
                    </td>
                </tr>`;
        });
        }

      // Función para dibujar la tabla de Check-ins (incluyendo inicialización de IDs vistos)
        function populateCheckinsTable(checkins) {
         const tbody = document.getElementById('checkins-table-body');
         if (!tbody) {
        console.error("Elemento tbody 'checkins-table-body' no encontrado.");
        return; // Salir si no se encuentra la tabla
        }

        // --- AÑADIDO: Limpiar el set al redibujar la tabla completa ---
        activeCheckinsIds.clear(); // Asegura empezar limpio

        const thead = tbody.previousElementSibling; // el <thead>
        const hasAdmin = (window.currentUserRole === 'Admin'); // Usar la variable global

        // Encabezado (ya estaba corregido para quitar Corrección/Acciones)
            thead.innerHTML = `
      <tr>
        <th class="p-2 w-28">Estado</th>
        <th class="p-2">Planilla</th>
        <th class="p-2">Sello</th>
        <th class="p-2">Declarado</th>
        <th class="p-2">Ruta</th>
        <th class="p-2">Fecha de Registro</th>
        <th class="p-2">Checkinero</th>
        <th class="p-2">Cliente</th>
        <th class="p-2">Fondo</th>
        ${hasAdmin ? '<th class="p-2 w-20">Admin</th>' : ''}
      </tr>
        `;

        tbody.innerHTML = ''; // Limpiar el cuerpo de la tabla antes de redibujar

        if (!checkins || checkins.length === 0) {
        const colspan = hasAdmin ? 10 : 9; // Colspan ajustado
        tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">
          No hay registros de check-in.
        </td></tr>`;
        return; // Salir si no hay datos
        }

        checkins.forEach(ci => {
        // --- AÑADIDO: Registrar el ID en el set ---
        const checkinId = parseInt(ci.id);
        if (!isNaN(checkinId)) { // Asegurar que sea un número válido
            activeCheckinsIds.add(checkinId);
        }
        // --- FIN AÑADIDO ---

        // Badge de estado (lógica sin cambios)
        let statusBadge = '';
        switch (ci.status) {
            case 'Rechazado':
                statusBadge = `<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-1 rounded-full">Rechazado</span>`;
                break;
            case 'Procesado':
                statusBadge = `<span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">Procesado</span>`;
                break;
            case 'Discrepancia':
                statusBadge = `<span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-1 rounded-full">Discrepancia</span>`;
                break;
            default: // Pendiente
                statusBadge = `<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-1 rounded-full">Pendiente</span>`;
        }

        // Botón de eliminar para Admin (lógica sin cambios)
        const adminDeleteButton = hasAdmin
            ? `<td class="p-2"><button data-id="${ci.id}"
                 class="text-red-600 hover:text-red-800 text-xs font-semibold delete-checkin-btn">
                 Eliminar
               </button></td>` // Usamos una clase para el listener
            : '';

        // Construcción de la fila (ya estaba corregida para quitar Corrección/Acciones)
        const row = `
          <tr class="border-b hover:bg-gray-50" id="checkin-row-${checkinId}">
            <td class="p-2">${statusBadge}</td>
            <td class="p-2 font-mono">${ci.invoice_number || ''}</td>
            <td class="p-2 font-mono">${ci.seal_number || ''}</td>
            <td class="p-2 text-right">${formatCurrency(ci.declared_value)}</td>
            <td class="p-2">${ci.route_name || ''}</td>
            <td class="p-2 text-xs whitespace-nowrap">${new Date(ci.created_at).toLocaleString('es-CO')}</td>
            <td class="p-2">${ci.checkinero_name || ''}</td>
            <td class="p-2">${ci.client_name || ''}</td>
            <td class="p-2">${ci.fund_name || 'N/A'}</td>
            ${adminDeleteButton}
          </tr>`;
        tbody.insertAdjacentHTML('beforeend', row);
    });

    // --- AÑADIDO: Añadir listeners para los botones de eliminar del Admin ---
    if (hasAdmin) {
        tbody.querySelectorAll('.delete-checkin-btn').forEach(button => {
            const id = button.getAttribute('data-id');
            // Usar addEventListener para evitar problemas si se redibuja
            button.addEventListener('click', () => deleteCheckIn(id)); // Llama a la función deleteCheckIn que ya existe
        });
    }
}

function populateOperatorCheckinsTable(checkins) {
    const tbody = document.getElementById('operator-checkins-table-body');
    if (!tbody) return;

    // --- AÑADIDO: Limpiar el set al redibujar ---
    operatorPendingIds.clear();

    tbody.innerHTML = '';
    const pendingCheckins = checkins.filter(ci => ci.status === 'Pendiente'); // Solo pendientes

    if (pendingCheckins.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay planillas pendientes por detallar.</td></tr>';
        return;
    }

    const thead = tbody.previousElementSibling;
    thead.innerHTML = `
        <tr>
            <th class="p-3">Planilla</th><th class="p-3">Sello</th><th class="p-3">Declarado</th>
            <th class="p-3">Cliente</th><th class="p-3">Checkinero</th><th class="p-3">Fecha de Registro</th>
            <th class="p-3">Estado</th><th class="p-3">Acción</th>
        </tr>
    `;

    pendingCheckins.forEach(ci => {
        // --- AÑADIDO: Registrar el ID en el set ---
        const checkinId = parseInt(ci.id);
        if (!isNaN(checkinId)) {
            operatorPendingIds.add(checkinId);
        }
        // --- FIN AÑADIDO ---

        const row = `
            <tr class="border-b">
                <td class="p-3 font-mono">${ci.invoice_number}</td>
                <td class="p-3 font-mono">${ci.seal_number}</td>
                <td class="p-3 text-right">${formatCurrency(ci.declared_value)}</td>
                <td class="p-3">${ci.client_name}</td>
                <td class="p-3">${ci.checkinero_name}</td>
                <td class="p-3 text-xs whitespace-nowrap">${new Date(ci.created_at).toLocaleString('es-CO')}</td>
                <td class="p-3"><span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-1 rounded-full">${ci.status}</span></td>
                <td class="p-3">
                    <button onclick="selectPlanilla('${ci.invoice_number}')" class="bg-blue-500 text-white px-3 py-1 text-xs font-semibold rounded-md hover:bg-blue-600">Seleccionar</button>
                </td>
            </tr>
        `;
        tbody.innerHTML += row;
    });
}

    function populateOperatorHistoryTable(historyData) {
        const tbody = document.getElementById('operator-history-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';

        const showOperatorColumn = (currentUserRole === 'Admin' || currentUserRole === 'Digitador');
        const colspan = showOperatorColumn ? (currentUserRole === 'Admin' ? 9 : 8) : 7;

        if (!historyData || historyData.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay conteos registrados.</td></tr>`; return;
        }

        historyData.forEach(item => {
            const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            const operatorColumn = showOperatorColumn ? `<td class="p-3">${item.operator_name}</td>` : '';
            const adminDeleteButton = currentUserRole === 'Admin' ? `<td class="p-3 text-center"><button onclick="deleteCheckIn(${item.check_in_id})" class="text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button></td>` : '';

            tbody.innerHTML += `<tr class="border-b">
                                    <td class="p-3 font-mono">${item.invoice_number}</td>
                                    <td class="p-3">${item.client_name}</td>
                                    <td class="p-3 text-right">${formatCurrency(item.declared_value)}</td>
                                    <td class="p-3 text-right">${formatCurrency(item.total_counted)}</td>
                                    <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(item.discrepancy)}</td>
                                    ${operatorColumn}
                                    <td class="p-3 text-xs whitespace-nowrap">${new Date(item.count_date).toLocaleString('es-CO')}</td>
                                    <td class="p-3 text-xs max-w-xs truncate" title="${item.observations || ''}">${item.observations || 'N/A'}</td>
                                    ${adminDeleteButton}
                               </tr>`;
        });
    }

// --- NUEVA FUNCIÓN PARA EL PANEL DE DIGITADOR ---
function populateDigitadorOperatorHistoryTable(historyData) {
    const tbody = document.getElementById('digitador-operator-history-tbody'); // <-- ID NUEVO
    if (!tbody) return;
    tbody.innerHTML = '';

    const colspan = 8; // 8 columnas

    if (!historyData || historyData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay conteos registrados por el operador.</td></tr>`;
        return;
    }

    historyData.forEach(item => {
        const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
        const operatorColumn = `<td class="p-3">${item.operator_name}</td>`;
        const adminDeleteButton = ''; // <-- Sin botón de eliminar

        tbody.innerHTML += `<tr class="border-b">
                                <td class="p-3 font-mono">${item.invoice_number}</td>
                                <td class="p-3">${item.client_name}</td>
                                <td class="p-3 text-right">${formatCurrency(item.declared_value)}</td>
                                <td class="p-3 text-right">${formatCurrency(item.total_counted)}</td>
                                <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(item.discrepancy)}</td>
                                ${operatorColumn}
                                <td class="p-3 text-xs whitespace-nowrap">${new Date(item.count_date).toLocaleString('es-CO')}</td>
                                <td class="p-3 text-xs max-w-xs truncate" title="${item.observations || ''}">${item.observations || 'N/A'}</td>
                           </tr>`;
    });
}
// --- FIN DE NUEVA FUNCIÓN ---


    function populateUserHistoryTable(tasks) {
        const tbody = document.getElementById('historial-individual-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!tasks || tasks.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="p-6 text-center text-gray-500">No has completado ninguna tarea todavía.</td></tr>'; // Colspan a 10
            return;
        }

        tasks.forEach(task => {
             const completedBy = task.completed_by || 'N/A';
             const createdBy = task.created_by_name || 'Sistema';

            tbody.innerHTML += `<tr class="border-b">
                                    <td class="px-6 py-4 font-medium">${task.title || ''}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.instruction || ''}">${task.instruction || ''}</td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.priority)}">${task.priority || ''}</span></td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.final_priority)}">${task.final_priority || ''}</span></td>
                                    <td class="px-6 py-4">${createdBy}</td>
                                    <td class="px-6 py-4 font-semibold">${completedBy}</td> <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.created_at)}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.completed_at)}</td>
                                    <td class="px-6 py-4 font-mono">${task.response_time || ''}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.resolution_note || ''}">${task.resolution_note || 'N/A'}</td>
                               </tr>`;
        });
    }

    
    function populateDigitadorClosedHistory(history) {
        const tbody = document.getElementById('digitador-closed-history-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        const showCerradaPor = (currentUserRole === 'Admin' || currentUserRole === 'Digitador');
        const colspan = showCerradaPor ? 9 : 8; // Adjust colspan
        if (!history || history.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay historial de planillas cerradas.</td></tr>`;
            return;
        }
        history.forEach(item => {
            const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            let statusBadge = '';
            if (item.digitador_status === 'Conforme') statusBadge = `<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-green-100 text-green-800">Conforme</span>`;
            else if (item.digitador_status === 'Cerrado') statusBadge = `<span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">Cerrado</span>`;
            else if (item.digitador_status === 'Rechazado') statusBadge = `<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-1 rounded-full">Rechazado</span>`; // Added Rechazado
            else statusBadge = `<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-1 rounded-full">${item.digitador_status || 'N/A'}</span>`;

            let actionButtons = `<button onclick="toggleBreakdown('hist-${item.id}')" class="text-blue-600 text-xs font-semibold">Desglose</button>`;
            if (currentUserRole === 'Admin') actionButtons += `<button onclick="deleteCheckIn(${item.id})" class="text-red-500 hover:text-red-700 font-semibold text-xs ml-2">Eliminar</button>`;

            const cerradaPorCol = showCerradaPor ? `<td class="p-3">${item.digitador_name || 'N/A'}</td>` : '';

            tbody.innerHTML += `
                <tr class="border-b hover:bg-gray-50">
                    <td class="p-3 font-mono">${item.invoice_number}</td>
                    <td class="p-3">${item.fund_name || 'N/A'}</td>
                    <td class="p-3 text-xs">${item.closed_by_digitador_at ? formatDate(item.closed_by_digitador_at) : 'N/A'}</td>
                    <td class="p-3 text-right font-mono">${formatCurrency(item.total_counted)}</td>
                    <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(item.discrepancy)}</td>
                    <td class="p-3">${statusBadge}</td>
                    <td class="p-3 text-xs max-w-xs truncate" title="${item.digitador_observations || ''}">${item.digitador_observations || 'N/A'}</td>
                    ${cerradaPorCol}
                    <td class="p-3 text-center">${actionButtons}</td>
                </tr>
                 <tr class="details-row hidden" id="breakdown-row-hist-${item.id}">
                    <td colspan="${colspan}" class="p-0"><div id="breakdown-content-hist-${item.id}" class="cash-breakdown bg-gray-50"><div class="p-4 grid grid-cols-3 sm:grid-cols-7 gap-x-8 gap-y-2 text-xs"><span><strong>$100.000:</strong> ${item.bills_100k||0}</span><span><strong>$50.000:</strong> ${item.bills_50k||0}</span><span><strong>$20.000:</strong> ${item.bills_20k||0}</span><span><strong>$10.000:</strong> ${item.bills_10k||0}</span><span><strong>$5.000:</strong> ${item.bills_5k||0}</span><span><strong>$2.000:</strong> ${item.bills_2k||0}</span><span class="font-bold">Monedas: ${formatCurrency(item.coins)}</span></div></div></td>
                </tr>
           `;
        });
    }

    function flagForReview(checkbox) {
        const panel = document.getElementById('operator-panel-digitador');
        document.querySelectorAll('.review-checkbox').forEach(cb => { if (cb !== checkbox) cb.checked = false; });
        if (checkbox.checked) {
            const data = JSON.parse(checkbox.dataset.info);
            const discrepancyClass = data.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            panel.innerHTML = `<div class="flex justify-between items-start"><h3 class="text-xl font-semibold mb-4 text-blue-800">Planilla en Revisión: ${data.invoice_number}</h3><button onclick="closeReviewPanel()" class="text-gray-500 hover:text-red-600 font-bold text-2xl">&times;</button></div><div class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm border-t pt-4 mt-2"><p><strong>Cliente:</strong><br>${data.client_name}</p><p><strong>Operador:</strong><br>${data.operator_name}</p><p><strong>V. Declarado:</strong><br>${formatCurrency(data.declared_value)}</p><p><strong>V. Contado:</strong><br>${formatCurrency(data.total_counted)}</p><p><strong class="${discrepancyClass}">Discrepancia:</strong><br><span class="${discrepancyClass}">${formatCurrency(data.discrepancy)}</span></p><p class="col-span-2 lg:col-span-3"><strong>Obs. del Operador:</strong><br>${data.observations || 'Sin observaciones'}</p></div><div class="mt-6 border-t pt-4"><h4 class="text-md font-semibold mb-2">Decisión de Cierre</h4><div><label for="digitador-observations" class="block text-sm font-medium text-gray-700">Observaciones Finales (Requerido)</label><textarea id="digitador-observations" rows="3" class="mt-1 w-full border rounded-md p-2 shadow-sm" placeholder="Escriba aquí el motivo de la aprobación o rechazo..."></textarea></div><div class="mt-4 flex space-x-4"><button onclick="submitDigitadorReview(${data.check_in_id}, 'Rechazado')" class="w-full bg-red-600 text-white font-bold py-2 px-4 rounded-md hover:bg-red-700">Rechazar Conteo</button><button onclick="submitDigitadorReview(${data.check_in_id}, 'Conforme')" class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded-md hover:bg-green-700">Aprobar (Conforme)</button></div></div>`;
            panel.classList.remove('hidden');
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else { panel.classList.add('hidden'); }
    }
    /*function closeReviewPanel() { document.getElementById('operator-panel-digitador').classList.add('hidden'); document.querySelectorAll('.review-checkbox').forEach(cb => cb.checked = false); }*/
    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
    function populateTrazabilidadTable(tasks) {
        const tbody = document.getElementById('trazabilidad-tbody');
         if (!tbody) return;
        tbody.innerHTML = '';
        if (!tasks || tasks.length === 0) { tbody.innerHTML = '<tr><td colspan="12" class="p-6 text-center text-gray-500">No hay tareas que coincidan con los filtros.</td></tr>'; return; }

        tasks.forEach(task => {
            let assignedTo = '';
            if (task.assigned_to_group) {
                assignedTo = `<span class="font-medium text-purple-700">Grupo ${task.assigned_to_group}</span>`;
            } else if (task.assigned_to) {
                assignedTo = task.assigned_to;
            }

            tbody.innerHTML += `<tr class="border-b">
                                    <td class="px-6 py-4 font-medium">${task.title || ''}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.instruction || ''}">${task.instruction || ''}</td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.priority)}">${task.priority || ''}</span></td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.final_priority)}">${task.final_priority || ''}</span></td>
                                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.created_at)}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.completed_at)}</td>
                                    <td class="px-6 py-4 font-mono">${task.response_time || ''}</td>
                                    <td class="px-6 py-4">${assignedTo || 'N/A'}</td>
                                    <td class="px-6 py-4">${task.created_by_name || 'Sistema'}</td>
                                    <td class="px-6 py-4 font-semibold">${task.completed_by || 'N/A'}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.resolution_note || ''}">${task.resolution_note || 'N/A'}</td>
                                    <td class="px-6 py-4 text-center"><button onclick="deleteTask(${task.id})" class="text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button></td>
                               </tr>`;
        });
    }
    function applyTrazabilidadFilters() {
         const startDate = document.getElementById('filter-start-date').value, endDate = document.getElementById('filter-end-date').value, user = document.getElementById('filter-user').value, checker = document.getElementById('filter-checker').value, priority = document.getElementById('filter-priority').value;
         currentFilteredTrazabilidadData = completedTasksData.filter(task => {
             let isValid = true;
             const taskStartDate = task.created_at ? task.created_at.split(' ')[0] : null;
             const taskEndDate = task.completed_at ? task.completed_at.split(' ')[0] : null;

             if (startDate && taskEndDate && taskEndDate < startDate) isValid = false;
             if (endDate && taskEndDate && taskEndDate > endDate) isValid = false;
             if (startDate && !endDate && taskEndDate && taskEndDate < startDate) isValid = false;
             if (!startDate && endDate && taskEndDate && taskEndDate > endDate) isValid = false;

             if (user && task.assigned_to !== user) isValid = false;
             if (checker && task.completed_by !== checker) isValid = false;
             if (priority && task.final_priority !== priority) isValid = false;
             return isValid;
         });
          document.querySelectorAll('th.sortable').forEach(th => {
              delete th.dataset.sortDir;
              const iconSpan = th.querySelector('span');
              if (iconSpan) iconSpan.textContent = '';
          });
         populateTrazabilidadTable(currentFilteredTrazabilidadData);
     }
    function sortTableByDate(column) {
        const header = document.querySelector(`th[data-column-name="${column}"]`);
         if (!header) return;
        const currentDirection = header.dataset.sortDir || 'none';
        const nextDirection = (currentDirection === 'desc') ? 'asc' : 'desc';

         const dataToSort = (currentFilteredTrazabilidadData.length > 0) ? [...currentFilteredTrazabilidadData] : [...completedTasksData];

        dataToSort.sort((a, b) => {
            const timeA = a[column] ? new Date(a[column]).getTime() : 0;
            const timeB = b[column] ? new Date(b[column]).getTime() : 0;

            if (isNaN(timeA) && isNaN(timeB)) return 0;
            if (isNaN(timeA)) return 1;
            if (isNaN(timeB)) return -1;

            return nextDirection === 'asc' ? timeA - timeB : timeB - timeA;
        });

        document.querySelectorAll('th.sortable').forEach(th => {
            const iconSpan = th.querySelector('span');
             if (!iconSpan) return;
            if (th === header) {
                th.dataset.sortDir = nextDirection;
                iconSpan.textContent = nextDirection === 'asc' ? ' ▲' : ' ▼';
                 iconSpan.classList.remove('text-gray-400');
            } else {
                delete th.dataset.sortDir;
                iconSpan.textContent = '';
                 iconSpan.classList.add('text-gray-400');
            }
        });

         populateTrazabilidadTable(dataToSort);
         currentFilteredTrazabilidadData = dataToSort;
    }
    function exportToExcel() { const table = document.getElementById("trazabilidad-table"), wb = XLSX.utils.table_to_book(table, { sheet: "Trazabilidad" }); XLSX.writeFile(wb, "Trazabilidad_EAGLE.xlsx"); }
    <?php endif; ?>

    // --- Operator Panel Specific ---
    function selectPlanilla(invoiceNumber) {
        document.getElementById('consult-invoice').value = invoiceNumber;
        document.getElementById('consultation-form').dispatchEvent(new Event('submit'));
        window.scrollTo(0, 0);
    }
   
    function updateQty(button, amount) {
        const input = button.parentElement.querySelector('input');
        let currentValue = parseInt(input.value) || 0;
        currentValue += amount;
        if (currentValue < 0) currentValue = 0;
        input.value = currentValue;
        calculateTotals();
    }
    function calculateTotals() {
        const form = document.getElementById('denomination-form');
        if (!form) return;
        let totalCounted = 0;
        form.querySelectorAll('.denomination-row').forEach(row => {
            totalCounted += (parseInt(row.querySelector('.denomination-qty').value) || 0) * parseFloat(row.dataset.value);
            row.querySelector('.subtotal').textContent = formatCurrency((parseInt(row.querySelector('.denomination-qty').value) || 0) * parseFloat(row.dataset.value));
        });
        const coinsValue = parseFloat(document.getElementById('coins-value').value) || 0;
        document.getElementById('coins-subtotal').textContent = formatCurrency(coinsValue);
        totalCounted += coinsValue;
        document.getElementById('total-counted').textContent = formatCurrency(totalCounted);
        const declaredValue = parseFloat(document.getElementById('display-declared').dataset.value) || 0;
        const discrepancy = totalCounted - declaredValue;
        const discrepancyEl = document.getElementById('discrepancy');
        discrepancyEl.textContent = formatCurrency(discrepancy);
        discrepancyEl.classList.toggle('text-red-500', discrepancy !== 0);
        discrepancyEl.classList.toggle('text-green-500', discrepancy === 0);
    }

    // --- Alert Pop-up & Polling ---
    // --- Toast Notification Function ---
function showToastNotification(alertData) {
  // adapta tus campos reales:
  const msg = alertData?.message || 'Notificación';
  // mapea prioridad a tipo:
  const type = ({ high:'error', medium:'warning', low:'info' }[alertData?.priority]) || 'info';
  showToast(msg, type, 5000);
}


function closeToast(toastId) {
    const toast = document.getElementById(toastId);
    if (toast) {
        toast.classList.remove('show');
        // Espera a que termine la animación antes de eliminar
        setTimeout(() => {
            toast.remove();
        }, 500); // Coincide con la duración de la transición CSS
    }
}
// --- End Toast Notification Function ---
    function showAlertPopup(alertData) {
        if (!alertPopupOverlay || !alertPopup || !alertPopupTitle || !alertPopupDescription || !alertPopupHeader) return;

        const alertTitle = alertData?.title || 'Nueva Alerta';
        const alertPriority = alertData?.priority || 'Alta';
        const descriptionParts = [];

        if (alertData?.description) {
            descriptionParts.push(alertData.description);
        } else if (alertData?.instruction) {
            descriptionParts.push(alertData.instruction);
        } else {
            descriptionParts.push('Revisa tus tareas pendientes.');
        }

        if (alertData?.invoice_number) {
            descriptionParts.push(`Planilla: ${alertData.invoice_number}`);
        }

        alertPopupTitle.textContent = `¡${alertPriority}! ${alertTitle}`;
        alertPopupDescription.textContent = descriptionParts.join(' • ');

        alertPopupHeader.className = 'p-4 border-b rounded-t-lg'; // Reset classes
        alertPopupTitle.className = 'text-xl font-bold'; // Reset classes

        if (alertPriority === 'Critica') {
            alertPopupHeader.classList.add('bg-red-100', 'border-red-200');
            alertPopupTitle.classList.add('text-red-800');
        } else {
            alertPopupHeader.classList.add('bg-orange-100', 'border-orange-200');
            alertPopupTitle.classList.add('text-orange-800');
        }

        alertPopup.classList.add('scale-95', 'opacity-0');
        alertPopup.classList.remove('scale-100', 'opacity-100');
        alertPopupOverlay.classList.remove('hidden');

        requestAnimationFrame(() => {
            alertPopup.classList.remove('scale-95', 'opacity-0');
            alertPopup.classList.add('scale-100', 'opacity-100');
        });
    }

    
     window.addEventListener('beforeunload', () => {
        stopAlertPolling();
         stopAlertPolling();
         stopCheckinPolling();
     });
//nuevooooo

// --- LÓGICA PARA ACTUALIZACIONES EN TIEMPO REAL (NUEVO/MODIFICADO) ---

    // Función para actualizar la tabla de check-ins (Checkinero) - Modificada para usar Set
    function updateCheckinsTable(newCheckins) {
        const tbody = document.getElementById('checkins-table-body');
        if (!tbody) return;
        const noRecordsRow = tbody.querySelector('td[colspan]');
        if (noRecordsRow && newCheckins.length > 0) tbody.innerHTML = '';
        let newRowsHtml = '';
        let updated = false;
        newCheckins.forEach(ci => {
            const checkinId = parseInt(ci.id);
            if (!activeCheckinsIds.has(checkinId)) {
                let statusBadge = '';
                switch(ci.status) {
                    case 'Rechazado': statusBadge = `<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-1 rounded-full">Rechazado</span>`; break;
                    case 'Procesado': statusBadge = `<span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">Procesado</span>`; break;
                    case 'Discrepancia': statusBadge = `<span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-1 rounded-full">Discrepancia</span>`; break;
                    default: statusBadge = `<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-1 rounded-full">Pendiente</span>`; break;
                }
                const adminDeleteButton = currentUserRole === 'Admin' ? `<td class="p-2"><button onclick="deleteCheckIn(${ci.id})" class="text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button></td>` : '';
                const declaredValueFormatted = formatCurrency(ci.declared_value);
                const createdAtFormatted = new Date(ci.created_at).toLocaleString('es-CO');
                newRowsHtml += `<tr class="border-b hover:bg-gray-50 new-checkin-row" id="checkin-row-${checkinId}"><td class="p-2">${statusBadge}</td><td class="p-2 font-mono">${ci.invoice_number || ''}</td><td class="p-2 font-mono">${ci.seal_number || ''}</td><td class="p-2 text-right">${declaredValueFormatted}</td><td class="p-2">${ci.route_name || ''}</td><td class="p-2 text-xs whitespace-nowrap">${createdAtFormatted}</td><td class="p-2">${ci.checkinero_name || ''}</td><td class="p-2">${ci.client_name || ''}</td><td class="p-2">${ci.fund_name || 'N/A'}</td>${adminDeleteButton}</tr>`;
                activeCheckinsIds.add(checkinId); updated = true;
            }
        });
        if (updated) {
            tbody.insertAdjacentHTML('afterbegin', newRowsHtml);
            document.querySelectorAll('.new-checkin-row').forEach(row => { row.style.backgroundColor = '#e0f2fe'; setTimeout(() => { row.style.backgroundColor = ''; row.classList.remove('new-checkin-row'); }, 3000); });
        }
        if (tbody.children.length === 0) { const colspan = currentUserRole === 'Admin' ? 10 : 9; tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay registros pendientes o rechazados.</td></tr>`; }
    }

// Función para actualizar tabla Operador Pendientes
    function updateOperatorPendingTable(newCheckins) {
        const tbody = document.getElementById('operator-checkins-table-body'); if (!tbody) return;
        const pendingCheckins = newCheckins.filter(ci => ci.status === 'Pendiente');
        const noRecordsRow = tbody.querySelector('td[colspan="8"]');
        if (noRecordsRow && pendingCheckins.length > 0) tbody.innerHTML = '';
        let newRowsHtml = ''; let updated = false;
        pendingCheckins.forEach(ci => {
            const checkinId = parseInt(ci.id);
            if (!operatorPendingIds.has(checkinId)) {
                const declaredValueFormatted = formatCurrency(ci.declared_value);
                const createdAtFormatted = new Date(ci.created_at).toLocaleString('es-CO');
                const escapedInvoiceNumber = (ci.invoice_number || '').replace(/'/g, "\\'");
                newRowsHtml += `<tr class="border-b new-operator-row" id="operator-pending-row-${checkinId}"><td class="p-3 font-mono">${ci.invoice_number || ''}</td><td class="p-3 font-mono">${ci.seal_number || ''}</td><td class="p-3 text-right">${declaredValueFormatted}</td><td class="p-3">${ci.client_name || ''}</td><td class="p-3">${ci.checkinero_name || ''}</td><td class="p-3 text-xs whitespace-nowrap">${createdAtFormatted}</td><td class="p-3"><span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-1 rounded-full">Pendiente</span></td><td class="p-3"><button onclick="selectPlanilla('${escapedInvoiceNumber}')" class="bg-blue-500 text-white px-3 py-1 text-xs font-semibold rounded-md hover:bg-blue-600">Seleccionar</button></td></tr>`;
                operatorPendingIds.add(checkinId); updated = true;
            }
        });
        if (updated) {
            tbody.insertAdjacentHTML('afterbegin', newRowsHtml);
            document.querySelectorAll('.new-operator-row').forEach(row => { row.style.backgroundColor = '#dbeafe'; setTimeout(() => { row.style.backgroundColor = ''; row.classList.remove('new-operator-row'); }, 3000); });
        }
        if (tbody.children.length === 0) { tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay planillas pendientes.</td></tr>'; }
    }


    // Función para actualizar tabla Digitador Pendientes (AHORA ELIMINADA)
    /* function updateDigitadorPendingTable(newCheckins) { ... } // Comentada o eliminada */

    // Función para Notificaciones
  // Función para actualizar Paneles/Badges de Alertas
function updateAlertsDisplay(newAlerts) {
    console.log('updateAlertsDisplay recibió:', JSON.stringify(newAlerts)); // <-- MODIFICADO: Ver todo el array
    const highPriorityList = document.getElementById('task-notifications-list');
    const mediumPriorityList = document.getElementById('medium-priority-list');
    const highPriorityBadge = document.getElementById('task-notification-badge');
    const mediumPriorityBadge = document.getElementById('medium-priority-badge');

    if (!highPriorityList || !mediumPriorityList || !highPriorityBadge || !mediumPriorityBadge) return;

    let highCount = parseInt(highPriorityBadge.textContent || '0');
    let mediumCount = parseInt(mediumPriorityBadge.textContent || '0');
    let highUpdated = false;
    let mediumUpdated = false;
    let newHighPriorityAlertsFound = false; // Para saber si tocar el sonido

    // Cargar IDs ya vistos desde LocalStorage al inicio de la función
    const seenData = loadSeenDiscrepFromLS(); // Reutilizamos esta función
    let seenIds = new Set(seenData.ids);
    console.log("IDs ya vistos al inicio:", Array.from(seenIds)); // <-- AÑADIDO: Ver IDs previos

    newHighPriorityAlertsFound = false; // Resetear bandera

    newAlerts.forEach(alert => {
        if (!alert) return; // --- FIX: Prevent crash on null/undefined alerts ---
        const isHighPriority = alert.priority === 'Critica' || alert.priority === 'Alta';
        const list = isHighPriority ? highPriorityList : mediumPriorityList;
        const colorClass = isHighPriority ? (alert.priority === 'Critica' ? 'red' : 'orange') : 'yellow';
        const alertId = alert.id; // Usamos el ID de la tarea

        // Solo procesar si tenemos un ID válido
        if (!alertId) {
            console.warn("Alerta recibida sin ID:", alert); // <-- AÑADIDO: Aviso si falta ID
            return;
        }

        // --- Lógica de Mostrar en Paneles Superiores (sin cambios) ---
        if (!list.querySelector(`[data-alert-id="${alertId}"]`)) {
             const alertHtml = `
                <div class="p-2 bg-${colorClass}-50 rounded-md border border-${colorClass}-200 text-sm" data-alert-id="${alertId}">
                    <p class="font-semibold text-${colorClass}-800">${alert.title || ''}</p>
                    <p class="text-gray-700 text-xs mt-1">${alert.description || ''}</p>
                </div>`;
            list.insertAdjacentHTML('afterbegin', alertHtml);

            if (isHighPriority) {
                highCount++;
                highUpdated = true;
            } else {
                mediumCount++;
                mediumUpdated = true;
            }
        }
        // --- Fin Lógica Paneles ---


        // --- Lógica Unificada para Toasts de Alta Prioridad ---
        if (isHighPriority) {
            const isDiscrepancyAlert = typeof alert.type === 'string' && alert.type.toLowerCase().includes('discrep');
            if (!seenIds.has(alertId)) {
                console.log(`Alerta ${alertId} es nueva.`);
                const canNotify = typeof canSeeDiscrepancyToasts === 'function' && canSeeDiscrepancyToasts();
                console.log(`Rol permite notificar: ${canNotify}, Pestaña visible: ${!document.hidden}`);

                if (canNotify) {
                    if (isDiscrepancyAlert && typeof showAlertPopup === 'function') {
                        console.log(`Mostrando popup emergente para discrepancia ${alertId}`);
                        showAlertPopup(alert);
                    }

                    if (!document.hidden) {
                        const toastType = (alert.priority === 'Critica') ? 'error' : 'warning';
                        console.log(`Llamando showToast para ${alertId} con tipo ${toastType}`);
                        showToast(`${alert.title || 'Alerta Importante'}`, toastType, 6000);
                        newHighPriorityAlertsFound = true;
                    } else if (isDiscrepancyAlert) {
                        // Mantener la bandera para reproducir sonido cuando corresponda
                        newHighPriorityAlertsFound = true;
                    } else {
                        console.log(`Toast omitido para ${alertId} (pestaña no visible).`);
                    }
                } else {
                    console.log(`Notificación omitida para ${alertId} (rol sin permisos).`);
                }

                seenIds.add(alertId);
            } else {
                console.log(`Alerta ${alertId} ya vista/notificada.`);
            }
        }
        // --- Fin Lógica Toasts ---
    }); // Fin del forEach

    // Tocar sonido UNA VEZ si se encontraron nuevas alertas de alta prioridad
    if (newHighPriorityAlertsFound && typeof beepOnce === 'function') {
        beepOnce();
    }

    // Guardar los IDs actualizados en LocalStorage
    console.log("IDs vistos al final:", Array.from(seenIds)); // <-- AÑADIDO: Ver IDs finales
    saveSeenDiscrepToLS(seenIds);

    // Actualizar badges y mensajes de "No hay alertas" (sin cambios)
    if (highUpdated) {
        highPriorityBadge.textContent = highCount;
        highPriorityBadge.classList.remove('hidden');
        const noAlertsMsg = highPriorityList.querySelector('.text-gray-500');
        if (noAlertsMsg) noAlertsMsg.remove();
    }
    if (mediumUpdated) {
        mediumPriorityBadge.textContent = mediumCount;
        mediumPriorityBadge.classList.remove('hidden');
        const noAlertsMsg = mediumPriorityList.querySelector('.text-gray-500');
        if (noAlertsMsg) noAlertsMsg.remove();
    }
} // <-- Esta es la llave de cierre que faltaba
// --- Polling Control ---

function startAlertPolling(intervalSeconds = 15) {
  if (alertPollingInterval) clearInterval(alertPollingInterval);
  // primera carga inmediata
  pollAlerts().catch(()=>{});
  // siguientes ciclos
  alertPollingInterval = setInterval(() => { pollAlerts().catch(()=>{}); }, intervalSeconds * 1000);
}

// …al final del DOM ready:
startAlertPolling(15);


    function stopAlertPolling() {
        if (alertPollingInterval) {
            console.log("Deteniendo sondeo de alertas.");
            clearInterval(alertPollingInterval);
            alertPollingInterval = null;
        }
    }

    // --- Asegúrate que también tengas estas si las necesitas ---
    function startCheckinPolling(intervalSeconds = 15) {
        stopCheckinPolling();
        console.log(`Iniciando sondeo de checkins cada ${intervalSeconds}s.`);
        pollCheckins(); // Llama una vez
        checkinPollingInterval = setInterval(pollCheckins, intervalSeconds * 1000);
    }
    function stopCheckinPolling() {
        if (checkinPollingInterval) {
            console.log("Deteniendo sondeo de checkins.");
            clearInterval(checkinPollingInterval);
            checkinPollingInterval = null;
        }
    }
    function startTrazabilidadPolling(intervalSeconds = 20) {
        stopTrazabilidadPolling();
        console.log(`Iniciando sondeo de trazabilidad cada ${intervalSeconds}s.`);
        pollTrazabilidad(); // Llama una vez
        trazabilidadPollingInterval = setInterval(pollTrazabilidad, intervalSeconds * 1000);
    }
    function stopTrazabilidadPolling() {
        if (trazabilidadPollingInterval) {
            console.log("Deteniendo sondeo de trazabilidad.");
            clearInterval(trazabilidadPollingInterval);
            trazabilidadPollingInterval = null;
        }
    }
   

    // ... (El resto de tus funciones JavaScript) ...
// Función Polling Checkins (Modificada)
    async function pollCheckins() {
        try {
            // --- CAMBIO: Ruta de la API ---
            const response = await fetch(`${apiRealtimeBase}/realtime_checkins_api.php`);
            if (!response.ok) { console.error('Error pollCheckins:', response.statusText); return; }
            const data = await response.json();
            if (data.success && data.checkins) {
                 updateCheckinsTable(data.checkins); // Actualiza tabla Checkinero
                 updateOperatorPendingTable(data.checkins); // Actualiza tabla Operador
                 // updateDigitadorPendingTable(data.checkins); // Llamada eliminada
            } else { console.error('API error pollCheckins:', data.error); }
        } catch (error) { console.error('Network error pollCheckins:', error); }
    }


// Función Polling Alertas
async function pollAlerts() {
  try {
    const urlToFetch = `${apiRealtimeBase}/realtime_alerts_api.php?since=${lastCheckedAlertTime}`; // Construir URL
    console.log("Polling URL:", urlToFetch); // <-- AÑADIDO: Ver URL

    const r = await fetch(urlToFetch, { headers: { 'Accept': 'application/json' } });

    // --- AÑADIDO: Ver respuesta cruda ---
    const rawResponseText = await r.text(); // Obtener texto crudo
    console.log("Raw Response:", rawResponseText);
    // --- FIN AÑADIDO ---

    if (!r.ok) {
        console.error("HTTP Error Status:", r.status, r.statusText); // <-- AÑADIDO: Log de error HTTP
        throw new Error('HTTP ' + r.status);
    }

    // Intentar parsear el texto crudo
    const data = JSON.parse(rawResponseText);
    console.log("Parsed Data:", data); // <-- AÑADIDO: Ver datos parseados

    if (data && data.timestamp) {
        lastCheckedAlertTime = data.timestamp;
    } else {
        console.warn("No timestamp received from API."); // <-- AÑADIDO: Aviso si falta timestamp
    }

    const alerts = Array.isArray(data?.alerts) ? data.alerts : []; // Asegurar que sea un array
    console.log("Alerts to display:", alerts); // <-- AÑADIDO: Ver alertas a procesar
    updateAlertsDisplay(alerts);

    // --- AÑADIDO: Limpiar el flag de error si el polling tuvo éxito ---
    window._pollToastShown = false;
    // --- FIN AÑADIDO ---

  } catch (err) {
    // --- MODIFICADO: Mostrar error solo una vez y loguear detalles ---
    if (!window._pollToastShown) {
        showToast('Fallo el polling de alertas. Verifica tu conexión y la consola (F12).', 'error', 7000);
        window._pollToastShown = true; // Evitar spam de errores de polling
    }
    console.error('pollAlerts error:', err); // Mostrar el error específico en consola
    // --- FIN MODIFICADO ---
  }
}



     // Función Polling Trazabilidad
    async function pollTrazabilidad() {
        const trazabilidadContent = document.getElementById('content-trazabilidad');
        if (currentUserRole !== 'Admin' || !trazabilidadContent || trazabilidadContent.classList.contains('hidden')) return;
        try {
             // --- CAMBIO: Ruta de la API ---
            const response = await fetch(`${apiRealtimeBase}/realtime_trazabilidad_api.php?since_id=${lastTrazabilidadTaskId}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();
            if (data.success && data.tasks && data.tasks.length > 0) { updateTrazabilidadTable(data.tasks); }
            if (data.last_id && data.last_id > lastTrazabilidadTaskId) { lastTrazabilidadTaskId = data.last_id; }
        } catch (error) { console.error('Error pollTrazabilidad:', error); }
    }



//fin nuevo
    // --- Tab Switching & Dynamic Content Loading ---
    async function switchTab(tabName) {
        sessionStorage.setItem('activeTab', tabName);

        const staticContentPanels = ['operaciones', 'checkinero', 'operador', 'digitador', 'mi-historial', 'roles', 'trazabilidad'];
        const dynamicContentPanels = ['manage-clients', 'manage-routes', 'manage-funds'];
        const allContentPanels = staticContentPanels.concat(dynamicContentPanels);

        document.querySelectorAll('.nav-tab, .header-panel-button').forEach(t => t.classList.remove('active'));
        allContentPanels.forEach(panel => document.getElementById(`content-${panel}`)?.classList.add('hidden'));

        const activeContent = document.getElementById(`content-${tabName}`);
        // Find the button whether it's in the header or nav
        const activeTabElement = document.getElementById(`tab-${tabName}`);

        if (activeContent) {
            activeContent.classList.remove('hidden');
            activeTabElement?.classList.add('active'); // Activate the button/tab

            stopCheckinPolling();
            if (tabName === 'checkinero' || (tabName === 'operador' && currentUserRole === 'Admin')) {
                 startCheckinPolling(10);
            }
// ***** PEGA ESTE BLOQUE NUEVO AQUÍ *****
            if (tabName === 'checkinero') {
                const clientSelect = document.getElementById('client_id');
                const fundDisplay = document.getElementById('fund_display');
                const fundIdHidden = document.getElementById('fund_id_hidden');

                if (clientSelect && fundDisplay) {
                     // --- Evitar añadir el listener múltiples veces ---
                     if (!clientSelect.hasAttribute('data-listener-added')) {
                        clientSelect.addEventListener('change', async () => {
                            const clientId = clientSelect.value;
                            fundDisplay.textContent = 'Cargando...';
                            fundDisplay.classList.add('italic');
                            if (fundIdHidden) fundIdHidden.value = '';

                            if (!clientId) {
                                fundDisplay.textContent = 'Seleccione un cliente...';
                                return;
                            }

                            try {
                                const response = await fetch(`api/funds_api.php?client_id=${clientId}`);
                                if (!response.ok) throw new Error(`Error HTTP ${response.status}`);
                                const funds = await response.json();

                                fundDisplay.textContent = '';
                                fundDisplay.classList.remove('italic', 'text-red-500');

                                if (Array.isArray(funds) && funds.length > 0) {
                                    const firstFund = funds[0];
                                    fundDisplay.textContent = firstFund.name;
                                    if (fundIdHidden) fundIdHidden.value = firstFund.id;

                                    if (funds.length > 1) {
                                        console.warn(`Advertencia: Cliente ${clientId} tiene ${funds.length} fondos. Mostrando el primero.`);
                                    }
                                } else {
                                    fundDisplay.textContent = 'Cliente sin fondo asignado';
                                    fundDisplay.classList.add('italic', 'text-red-500');
                                }
                            } catch (e) {
                                console.error('Error cargando fondos:', e);
                                fundDisplay.textContent = 'Error al cargar fondos';
                                fundDisplay.classList.add('italic', 'text-red-500');
                            }
                        });
                        clientSelect.setAttribute('data-listener-added', 'true'); // Marcar que ya tiene listener
                     } // --- Fin del if !hasAttribute ---
                } else {
                    if (!clientSelect) console.error("Error: No se encontró 'client_id' al cambiar a la pestaña checkinero.");
                    if (!fundDisplay) console.error("Error: No se encontró 'fund_display' al cambiar a la pestaña checkinero.");
                }
            }
            // ***** FIN DEL BLOQUE NUEVO *****
        // ***** FIN CÓDIGO PEGADO *****

            if (dynamicContentPanels.includes(tabName) && !loadedContent[tabName]) {
                activeContent.innerHTML = '<div class="loader"></div><p class="text-center text-gray-500">Cargando...</p>';
                try {
                    let phpFile = '';
                    if (tabName === 'manage-clients') phpFile = 'manage_clients.php';
                    else if (tabName === 'manage-routes') phpFile = 'manage_routes.php';
                    else if (tabName === 'manage-funds') phpFile = 'manage_funds.php';

                    if (phpFile) {
                        const response = await fetch(`${phpFile}?content_only=1`);
                        if (!response.ok) throw new Error(`Failed to load ${phpFile}: ${response.statusText}`);
                        const htmlContent = await response.text();
                        activeContent.innerHTML = htmlContent;
                        loadedContent[tabName] = true;

                        activeContent.querySelectorAll('script').forEach(script => {
                            try {
                                const newScript = document.createElement('script');
                                newScript.textContent = script.textContent;
                                script.parentNode.replaceChild(newScript, script);
                            } catch (e) { console.error("Error executing dynamic script:", e); }
                        });
                   }
                } catch (error) {
                    console.error("Error loading dynamic content:", error);
                    activeContent.innerHTML = `<p class="text-center p-8 text-red-500">Error: ${error.message}</p>`;
                 }
            }
        } else { console.error(`Content container 'content-${tabName}' not found.`); }
    }

    // --- Initialization ---
    document.addEventListener('DOMContentLoaded', () => {
        startAlertPolling(10);
        const savedTab = sessionStorage.getItem('activeTab') || 'operaciones';

        if (document.getElementById('user-table-body') && currentUserRole === 'Admin') populateUserTable(adminUsersData);
        if (document.getElementById('historial-individual-tbody')) populateUserHistoryTable(userCompletedTasksData);
        if (currentUserRole === 'Admin' && document.getElementById('trazabilidad-tbody')) {
            currentFilteredTrazabilidadData = [...completedTasksData];
            populateTrazabilidadTable(currentFilteredTrazabilidadData);
        }
     if (document.getElementById('content-checkinero')) {
             populateCheckinsTable(initialCheckins);
             document.getElementById('checkin-form')?.addEventListener('submit', handleCheckinSubmit);
        }
        if (document.getElementById('content-operador')) {
            if (currentUserRole === 'Admin') populateOperatorCheckinsTable(initialCheckins);
            populateOperatorHistoryTable(operatorHistoryData);
            document.getElementById('consultation-form')?.addEventListener('submit', handleConsultation);
            document.getElementById('denomination-form')?.addEventListener('submit', handleDenominationSave);
        }
        if (document.getElementById('content-digitador')) {
        // --- CAMBIO: Se elimina botón y panel de supervisión ---
            // const btnSupervision = document.getElementById('btn-supervision');
            const btnCierre = document.getElementById('btn-cierre');
            const btnHistorialCierre = document.getElementById('btn-historial-cierre');
            const btnInformes = document.getElementById('btn-informes');
            // --- VARIABLES AÑADIDAS ---
    const btnSupervisionOperador = document.getElementById('btn-supervision-operador');
    const panelSupervisionOperador = document.getElementById('panel-supervision-operador');
    // --- FIN VARIABLES AÑADIDAS ---
            // const panelSupervision = document.getElementById('panel-supervision');
            const panelCierre = document.getElementById('panel-cierre');
            const panelHistorialCierre = document.getElementById('panel-historial-cierre');
            const panelInformes = document.getElementById('panel-informes');
           // --- AÑADIR AL ARRAY ---
    const digitadorSubPanels = [panelCierre, panelHistorialCierre, panelInformes, panelSupervisionOperador];
    const digitadorSubButtons = [btnCierre, btnHistorialCierre, btnInformes, btnSupervisionOperador];
    // --- FIN AÑADIR AL ARRAY ---
           const setActiveDigitadorButton = (activeBtn) => { digitadorSubButtons.forEach(btn => { if(btn) { btn.classList.remove('bg-blue-600', 'text-white'); btn.classList.add('bg-gray-200', 'text-gray-700'); } }); if(activeBtn) { activeBtn.classList.add('bg-blue-600', 'text-white'); activeBtn.classList.remove('bg-gray-200', 'text-gray-700'); } };
            const showDigitadorPanel = (activePanel) => { digitadorSubPanels.forEach(panel => { if(panel) panel.classList.add('hidden'); }); if (activePanel) activePanel.classList.remove('hidden'); };

           // btnSupervision?.addEventListener('click', ...); // Event listener eliminado
            btnCierre?.addEventListener('click', () => { setActiveDigitadorButton(btnCierre); showDigitadorPanel(panelCierre); sessionStorage.setItem('activeDigitadorSubTab', 'cierre'); loadFundsForCierre(); });
            btnHistorialCierre?.addEventListener('click', () => { setActiveDigitadorButton(btnHistorialCierre); showDigitadorPanel(panelHistorialCierre); sessionStorage.setItem('activeDigitadorSubTab', 'historial'); });
            btnInformes?.addEventListener('click', () => { setActiveDigitadorButton(btnInformes); showDigitadorPanel(panelInformes); sessionStorage.setItem('activeDigitadorSubTab', 'informes'); loadInformes(); });
// --- LISTENER AÑADIDO ---
            btnSupervisionOperador?.addEventListener('click', () => { 
                setActiveDigitadorButton(btnSupervisionOperador); 
                showDigitadorPanel(panelSupervisionOperador); 
                sessionStorage.setItem('activeDigitadorSubTab', 'supervision_operador'); 
            });
            // --- FIN LISTENER AÑADIDO ---
         // --- LÓGICA DE PESTAÑA POR DEFECTO MODIFICADA ---
            const savedSubTab = sessionStorage.getItem('activeDigitadorSubTab');
            if (savedSubTab === 'historial' && btnHistorialCierre) { btnHistorialCierre.click(); }
            else if (savedSubTab === 'informes' && btnInformes) { btnInformes.click(); }
            else if (savedSubTab === 'cierre' && btnCierre) { btnCierre.click(); }
            else if (savedSubTab === 'supervision_operador' && btnSupervisionOperador) { btnSupervisionOperador.click(); }
            // --- Hacer que la nueva pestaña sea la default si no hay nada guardado ---
            else if (btnSupervisionOperador) { btnSupervisionOperador.click(); }
            else if (btnCierre) { btnCierre.click(); } // Fallback
            // --- FIN LÓGICA MODIFICADA ---
            // --- CAMBIO: Llamada eliminada ---
            populateDigitadorOperatorHistoryTable(operatorHistoryData);
            // populateOperatorHistoryForDigitador(operatorHistoryData);
            populateDigitadorClosedHistory(digitadorClosedHistory);
        }

        switchTab(savedTab); // Activa la pestaña inicial (y el polling si corresponde)

         updateReminderCount();
      // Reemplaza TODO el setInterval existente por este (mismo lugar)
setInterval(() => {
  document.querySelectorAll('.countdown-timer').forEach(timerEl => {
    let raw = timerEl.dataset.endTime || '';
    // Normaliza "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM:SS"
    const iso = raw.includes(' ') ? raw.replace(' ', 'T') : raw;
    const endTime = new Date(iso).getTime();
    if (isNaN(endTime)) return;

    const now = Date.now();
    const distance = endTime - now;

    if (distance < 0) {
      // Ya venció -> mostrar retraso
      const elapsed = now - endTime;
      const days = Math.floor(elapsed / 86400000);
      const hours = Math.floor((elapsed % 86400000) / 3600000);
      const minutes = Math.floor((elapsed % 3600000) / 60000);
      const seconds = Math.floor((elapsed % 60000) / 1000);
      let elapsedTime = '';
      if (days > 0) elapsedTime += `${days}d `;
      if (hours > 0 || days > 0) elapsedTime += `${hours}h `;
      elapsedTime += `${minutes}m ${seconds}s`;
      timerEl.innerHTML = `Retraso: <span class="text-red-600 font-bold">${elapsedTime}</span>`;
    } else {
      // Aún falta -> mostrar cuenta regresiva
      const days = Math.floor(distance / 86400000);
      const hours = Math.floor((distance % 86400000) / 3600000);
      const minutes = Math.floor((distance % 3600000) / 60000);
      const seconds = Math.floor((distance % 60000) / 1000);
      let timeLeft = '';
      if (days > 0) timeLeft += `${days}d `;
      if (hours > 0 || days > 0) timeLeft += `${hours}h `;
      timeLeft += `${minutes}m ${seconds}s`;

      // Colores según urgencia (igual a tu lógica actual)
      let textColor = 'text-green-600';
      if (days === 0 && hours < 1) textColor = 'text-red-600';
      else if (days === 0 && hours < 24) textColor = 'text-yellow-700';

      timerEl.innerHTML = `Vence en: <span class="${textColor}">${timeLeft}</span>`;
    }
  });
}, 1000);

        const startDateInput = document.getElementById('manual-task-start'), endDateInput = document.getElementById('manual-task-end');
        if(startDateInput && endDateInput) {
            const getLocalISOString = (date) => {
                 if (!(date instanceof Date) || isNaN(date)) return '';
                 const pad = (num) => num.toString().padStart(2, '0');
                 const localDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
                 try {
                      return localDate.toISOString().slice(0, 16);
                 } catch (e) {
                      console.error("Error formatting date:", e);
                      return '';
                 }
             };
            const now = new Date();
             const nowString = getLocalISOString(now);

             startDateInput.min = nowString;
             endDateInput.min = nowString;

            startDateInput.addEventListener('input', () => {
                 if (startDateInput.value) {
                      endDateInput.min = startDateInput.value;
                      if (endDateInput.value && endDateInput.value < startDateInput.value) {
                           endDateInput.value = startDateInput.value;
                      }
                 } else {
                     endDateInput.min = nowString;
                 }
            });
             endDateInput.addEventListener('input', () => {
                  if (startDateInput.value && endDateInput.value && endDateInput.value < startDateInput.value) {
                       endDateInput.value = startDateInput.value;
                  }
             });
        } // Cierre del if(startDateInput && endDateInput)
    
    }); // Cierre del addEventListener 'DOMContentLoaded'
</script>

   <div id="toast-container" class="fixed bottom-4 right-4 z-[110] space-y-2">
   </div>
    
</body>
</html>