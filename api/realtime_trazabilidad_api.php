<?php
// api/realtime_trazabilidad_api.php
require '../config.php';
require '../db_connection.php'; // Ajusta la ruta si es necesario
header('Content-Type: application/json');

// 1. Seguridad: Solo Admin puede ver trazabilidad completa
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado.']);
    exit;
}

// 2. Obtener el ID de la última tarea vista por el cliente
$since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

// 3. Consultar tareas completadas MÁS RECIENTES que since_id
$new_tasks = [];
$max_id = $since_id; // Guardará el ID más alto encontrado

// La misma consulta base que usas en index.php para la tabla de trazabilidad
$sql = "
    SELECT
        t.id, COALESCE(a.title, t.title) as title, t.instruction, t.priority,
        t.start_datetime, t.end_datetime, u_assigned.name as assigned_to,
        u_completed.name as completed_by, t.created_at, t.completed_at,
        TIMEDIFF(t.completed_at, t.created_at) as response_time
    FROM tasks t
    LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
    LEFT JOIN users u_completed ON t.completed_by_user_id = u_completed.id
    LEFT JOIN alerts a ON t.alert_id = a.id
    WHERE t.status = 'Completada' AND t.id > ?  -- La condición clave
    ORDER BY t.id ASC -- Traer las más nuevas primero (aunque la tabla JS las inserte al inicio)
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $since_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Establecer zona horaria para cálculos de 'final_priority' si es necesario
     date_default_timezone_set('America/Bogota'); // Asegúrate que sea la correcta

    while ($row = $result->fetch_assoc()) {
        // Calcular final_priority (lógica copiada/adaptada de index.php)
        $original_priority = $row['priority'];
        $final_priority = $original_priority; // Valor por defecto
        if (!empty($row['end_datetime']) && !empty($row['completed_at'])) {
             try {
                  $end_time = new DateTime($row['end_datetime']);
                  $completed_time = new DateTime($row['completed_at']);
                  if ($completed_time > $end_time) {
                       $final_priority = 'Alta'; // O 'Critica' si prefieres
                  }
             } catch (Exception $e) {
                  // Manejar error si las fechas son inválidas
                  error_log("Error calculando final_priority en API: " . $e->getMessage());
             }
        } elseif (empty($row['end_datetime']) && ($original_priority === 'Critica' || $original_priority === 'Alta')) {
             // Si no tiene fecha fin pero era critica/alta, mantenla
             $final_priority = $original_priority;
        }
        // Si no tiene fecha fin y era Media/Baja, se queda como estaba ($final_priority = $original_priority;)

        $row['final_priority'] = $final_priority;
        $new_tasks[] = $row;

        // Actualizar el ID máximo encontrado
        if ($row['id'] > $max_id) {
            $max_id = $row['id'];
        }
    }
    $stmt->close();
} else {
    // Loguear error, pero no necesariamente fallar la respuesta
    error_log("Error preparando consulta en realtime_trazabilidad_api: " . $conn->error);
}

$conn->close();

// 4. Devolver el JSON
echo json_encode([
    'success' => true,
    'tasks' => $new_tasks, // Lista de nuevas tareas completadas
    'last_id' => $max_id   // El ID más alto encontrado (para la próxima consulta)
]);
?>