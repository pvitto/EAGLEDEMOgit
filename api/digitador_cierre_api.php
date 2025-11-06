<?php
require '../config.php';
require '../db_connection.php'; // Asegúrate que esta ruta sea correcta
header('Content-Type: application/json');

// --- Seguridad: Verificar rol Digitador o Admin ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado para esta acción.']);
    exit;
}

// --- Parámetros y Variables ---
$action = $_GET['action'] ?? ''; // Acción solicitada por GET
$method = $_SERVER['REQUEST_METHOD']; // Método HTTP (GET, POST, etc.)
$user_id = $_SESSION['user_id']; // ID del usuario que realiza la acción

// ===============================================
// === OBTENER FONDOS LISTOS PARA CERRAR (GET) ===
// ===============================================
if ($method === 'GET' && $action === 'list_funds_to_close') {

    // Busca fondos que tengan al menos una planilla procesada por el Operador
    // y que aún no haya sido cerrada por el Digitador.
    $query = "
        SELECT DISTINCT f.id, f.name, c.name as client_name
        FROM funds f
        JOIN clients c ON f.client_id = c.id
        JOIN check_ins ci ON ci.fund_id = f.id
        WHERE ci.status IN ('Procesado', 'Discrepancia')
          AND (ci.digitador_status IS NULL OR ci.digitador_status <> 'Cerrado')
        ORDER BY f.name ASC
    ";

    $result = $conn->query($query);
    $funds = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $funds[] = $row;
        }
    } else {
        // Loggear error si la consulta falla, pero devolver array vacío
        error_log("Error list_funds_to_close: " . $conn->error);
    }
    echo json_encode($funds); // Devuelve la lista de fondos (puede estar vacía)

// =========================================================
// === OBTENER PLANILLAS DE UN FONDO PARA CERRAR (GET) ===
// =========================================================
} elseif ($method === 'GET' && $action === 'get_services_for_closing' && isset($_GET['fund_id'])) {

    // Lista las planillas de un fondo específico que están listas para ser incluidas en el cierre.
    $fund_id = filter_input(INPUT_GET, 'fund_id', FILTER_VALIDATE_INT);

    if (!$fund_id) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'error' => 'ID de fondo inválido.']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT ci.id, ci.invoice_number, ci.declared_value, oc.total_counted, oc.discrepancy
        FROM check_ins ci
        LEFT JOIN (
            -- Subconsulta para obtener solo el último conteo del operador por check_in
            SELECT a.*
            FROM operator_counts a
            INNER JOIN (
                SELECT check_in_id, MAX(id) as max_id
                FROM operator_counts
                GROUP BY check_in_id
            ) b ON a.id = b.max_id
        ) oc ON ci.id = oc.check_in_id
        WHERE ci.fund_id = ?
          AND ci.status IN ('Procesado','Discrepancia')  -- Estado del Operador
          AND (ci.digitador_status IS NULL OR ci.digitador_status <> 'Cerrado') -- Estado del Digitador
        ORDER BY ci.invoice_number ASC -- Opcional: Ordenar por número de planilla
    ");

    if (!$stmt) {
        http_response_code(500);
        error_log("Error preparando get_services_for_closing: " . $conn->error);
        echo json_encode(['success' => false, 'error' => 'Error interno al preparar consulta de planillas.']);
        exit;
    }

    $stmt->bind_param("i", $fund_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $services = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $services[] = $row;
        }
    } else {
        error_log("Error ejecutando get_services_for_closing: " . $stmt->error);
        // Devolver array vacío en caso de error de ejecución
    }
    echo json_encode($services); // Devuelve la lista de planillas (puede estar vacía)
    $stmt->close();

// =========================================================================
// === CERRAR UN FONDO Y ELIMINAR ALERTAS/TAREAS ASOCIADAS (POST) ===
// =========================================================================
} elseif ($method === 'POST' && $action === 'close_fund') {
    // Cierra TODAS las planillas procesadas/con discrepancia de un fondo específico
    // Y BORRA las alertas/tareas asociadas a esas planillas.
    $data = json_decode(file_get_contents('php://input'), true);

    // Validar JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido en la solicitud: ' . json_last_error_msg()]);
        exit; // Salir aquí
    }

    $fund_id = isset($data['fund_id']) ? filter_var($data['fund_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;

    if ($fund_id) {
        $conn->begin_transaction(); // <--- INICIA TRANSACCIÓN
        try {
            // 1. Obtener los IDs de los check_ins que cumplen las condiciones para cerrar
            $stmt_select_ids = $conn->prepare("
                SELECT id FROM check_ins
                WHERE fund_id = ? AND status IN ('Procesado', 'Discrepancia') AND (digitador_status IS NULL OR digitador_status <> 'Cerrado')
            ");
            if (!$stmt_select_ids) throw new Exception("Error preparando SELECT IDs: " . $conn->error);

            $stmt_select_ids->bind_param("i", $fund_id);
            $stmt_select_ids->execute();
            $result_ids = $stmt_select_ids->get_result();
            $check_in_ids_to_close = [];
            while ($row = $result_ids->fetch_assoc()) {
                $check_in_ids_to_close[] = $row['id'];
            }
            $stmt_select_ids->close();

            // 2. Si no hay planillas para cerrar, informar y salir limpiamente
            if (empty($check_in_ids_to_close)) {
                $conn->rollback(); // Revertir (aunque no se hizo nada)
                echo json_encode(['success' => false, 'error' => 'No se encontraron planillas procesadas o con discrepancia listas para cerrar en este fondo.']);
                // Salimos del try-catch y del if($fund_id)

            } else {
                // 3. Hay planillas para cerrar -> Actualizar check_ins a 'Cerrado'
                $stmt_update = $conn->prepare("
                    UPDATE check_ins
                    SET digitador_status = 'Cerrado',
                        closed_by_digitador_at = NOW(),
                        closed_by_digitador_id = ?
                    WHERE id IN (" . implode(',', $check_in_ids_to_close) . ") -- Usar los IDs obtenidos
                ");
                if (!$stmt_update) throw new Exception("Error preparando UPDATE: " . $conn->error);

                $stmt_update->bind_param("i", $user_id); // Solo se necesita el user_id

                if ($stmt_update->execute()) {
                    $affected_rows = $stmt_update->affected_rows;
                    $stmt_update->close(); // Cerrar statement UPDATE

                    // --- INICIO: Lógica para borrar alertas y tareas asociadas ---
                    if ($affected_rows > 0) {
                        // Crear placeholders (?,?,?) para bind_param
                        $ids_placeholder = implode(',', array_fill(0, count($check_in_ids_to_close), '?'));
                        // Crear string de tipos ('iii...') para bind_param
                        $types = str_repeat('i', count($check_in_ids_to_close));

                        // 4. Borrar tareas asociadas a las alertas de estos check_ins
                        // IMPORTANTE: Si tienes FK con ON DELETE CASCADE de alerts a tasks, este paso podría no ser necesario.
                        $stmt_delete_tasks = $conn->prepare("
                            DELETE FROM tasks WHERE alert_id IN (SELECT id FROM alerts WHERE check_in_id IN ({$ids_placeholder}))
                        ");
                        if (!$stmt_delete_tasks) throw new Exception("Error preparando DELETE tasks: " . $conn->error);

                        $stmt_delete_tasks->bind_param($types, ...$check_in_ids_to_close); // Spread operator (...) para pasar los IDs
                        if (!$stmt_delete_tasks->execute()) {
                            // Loggear el error pero no detener necesariamente la transacción
                            error_log('Error al eliminar tareas asociadas al cerrar fondo: ' . $stmt_delete_tasks->error);
                        }
                        $stmt_delete_tasks->close();

                        // 5. Borrar las alertas asociadas a estos check_ins
                        $stmt_delete_alerts = $conn->prepare("
                            DELETE FROM alerts WHERE check_in_id IN ({$ids_placeholder})
                        ");
                        if (!$stmt_delete_alerts) throw new Exception("Error preparando DELETE alerts: " . $conn->error);

                        $stmt_delete_alerts->bind_param($types, ...$check_in_ids_to_close); // Reusar $types y IDs
                        if (!$stmt_delete_alerts->execute()) {
                            // Loggear el error pero no detener necesariamente la transacción
                            error_log('Error al eliminar alertas al cerrar fondo: ' . $stmt_delete_alerts->error);
                        }
                        $stmt_delete_alerts->close();
                    }
                    // --- FIN: Lógica para borrar alertas y tareas asociadas ---

                    $conn->commit(); // Confirma transacción (UPDATE y DELETEs)
                    echo json_encode(['success' => true, 'message' => 'Fondo cerrado exitosamente y alertas/tareas asociadas eliminadas.']);

                } else {
                    // Error al ejecutar el UPDATE
                    $update_error = $stmt_update->error; // Guardar error antes de cerrar
                    $stmt_update->close(); // Cerrar statement
                    throw new Exception('Error al actualizar check_ins: ' . $update_error);
                }
            } // Fin del else (si hay check_in_ids_to_close)

        } catch (Exception $e) {
            $conn->rollback(); // Revierte en caso de CUALQUIER error dentro del try
            http_response_code(500); // Internal Server Error
            error_log("Error cerrando fondo $fund_id por user $user_id: " . $e->getMessage());
            // Proporcionar un mensaje de error más detallado si es posible y seguro
            echo json_encode(['success' => false, 'error' => 'Error interno al cerrar el fondo: ' . $e->getMessage()]);
        }

    } else {
        // ID de fondo no proporcionado o inválido
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'error' => 'ID de fondo no proporcionado o inválido.']);
    }

// =============================
// === MÉTODO NO SOPORTADO ===
// =============================
} else {
    // Si no es GET con las acciones válidas o POST con acción 'close_fund'
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Acción o método no soportado.']);
}

// --- Cerrar conexión al final del script ---
if (isset($conn)) {
    $conn->close();
}
?>