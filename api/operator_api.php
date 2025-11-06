<?php
require '../config.php';
require '../db_connection.php';
require_once '../send_email.php'; // Incluir la utilidad de correo
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'Operador'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['user_id'];

if ($method === 'GET') {
    $planilla = $_GET['planilla'] ?? null;
    if (!$planilla) {
        echo json_encode(['success' => false, 'error' => 'No se proporcionó número de planilla.']);
        exit;
    }
    $stmt = $conn->prepare("
        SELECT ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, c.name as client_name
        FROM check_ins ci
        JOIN clients c ON ci.client_id = c.id
        WHERE ci.invoice_number = ? AND ci.status = 'Pendiente'
    ");
    $stmt->bind_param("s", $planilla);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Planilla no encontrada o ya fue procesada.']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $conn->begin_transaction();
    try {
        $stmt_insert = $conn->prepare(
            "INSERT INTO operator_counts (check_in_id, operator_id, bills_100k, bills_50k, bills_20k, bills_10k, bills_5k, bills_2k, coins, total_counted, discrepancy, observations) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt_insert->bind_param("iiiiiiidddis", 
            $data['check_in_id'], $user_id, $data['bills_100k'], $data['bills_50k'], $data['bills_20k'],
            $data['bills_10k'], $data['bills_5k'], $data['bills_2k'], $data['coins'], $data['total_counted'],
            $data['discrepancy'], $data['observations']
        );
        $stmt_insert->execute();
        $stmt_insert->close();

        // Lógica estándar: marca como Procesado o Discrepancia. No auto-aprueba.
        $new_status = ($data['discrepancy'] == 0) ? 'Procesado' : 'Discrepancia';
        $stmt_update = $conn->prepare("UPDATE check_ins SET status = ? WHERE id = ?");
        $stmt_update->bind_param("si", $new_status, $data['check_in_id']);
        $stmt_update->execute();
        $stmt_update->close();

        // Generar alerta solo si hay discrepancia
        if ($data['discrepancy'] != 0) {
            $check_in_id = $data['check_in_id'];
            $res = $conn->query("SELECT invoice_number FROM check_ins WHERE id = $check_in_id");
            $invoice_number = $res->fetch_assoc()['invoice_number'];
            $discrepancy_formatted = number_format($data['discrepancy'], 0, ',', '.');

            $alert_title = "Discrepancia en Planilla: " . $invoice_number;
            $alert_desc = "Diferencia de $" . $discrepancy_formatted . ". Requiere revisión y seguimiento.";
            
            $stmt_alert = $conn->prepare("INSERT INTO alerts (title, description, priority, status, suggested_role, check_in_id) VALUES (?, ?, 'Critica', 'Pendiente', 'Digitador', ?)");
            $stmt_alert->bind_param("ssi", $alert_title, $alert_desc, $check_in_id);
        // --- NUEVO CÓDIGO MEJORADO ---
            $stmt_alert->execute();
            $alert_id = $stmt_alert->insert_id;
            $stmt_alert->close();

            // Asignar UNA tarea al GRUPO 'Digitador'
            if ($alert_id) {
                $instruction = "Realizar seguimiento a la discrepancia (" . $invoice_number . "), contactar a los responsables y documentar la resolución.";

                // Preparamos la inserción de la tarea asignada al grupo
                $stmt_task = $conn->prepare("INSERT INTO tasks (alert_id, assigned_to_group, instruction, type, status, priority, created_by_user_id) VALUES (?, 'Digitador', ?, 'Asignacion', 'Pendiente', 'Critica', ?)");

                // El created_by_user_id es el Operador que generó la discrepancia
                $operator_user_id = $_SESSION['user_id'];

                $stmt_task->bind_param("isi", $alert_id, $instruction, $operator_user_id);
                $stmt_task->execute();
                $stmt_task->close();

                // Actualizamos el estado de la alerta
                $conn->query("UPDATE alerts SET status = 'Asignada' WHERE id = $alert_id");
            }
// --- FIN DEL NUEVO CÓDIGO ---
        }

        // --- Confirmar transacción en la BD ANTES de enviar correos ---
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Conteo guardado. Notificando...']);

        // --- Enviar correos DESPUÉS de confirmar la transacción ---
        try {
            $check_in_id_for_email = $data['check_in_id'];
            $query_details = "
                SELECT
                    ci.invoice_number,
                    c.name as client_name,
                    u.name as operator_name
                FROM check_ins ci
                JOIN clients c ON ci.client_id = c.id
                JOIN users u ON u.id = ?
                WHERE ci.id = ?
            ";
            $stmt_details = $conn->prepare($query_details);
            $stmt_details->bind_param("ii", $user_id, $check_in_id_for_email);
            $stmt_details->execute();
            $result_details = $stmt_details->get_result();
            $details = $result_details->fetch_assoc();
            $stmt_details->close();

            if ($details) {
                $recipients = [];
                $result_users = $conn->query("SELECT email, name FROM users WHERE role IN ('Admin', 'Digitador') AND email IS NOT NULL AND email != ''");
                if ($result_users) {
                    while ($user_row = $result_users->fetch_assoc()) {
                        $recipients[] = $user_row;
                    }
                }

                if (!empty($recipients)) {
                    if ($data['discrepancy'] != 0) {
                        $email_subject = "Discrepancia en Planilla: " . $details['invoice_number'];
                    } else {
                        $email_subject = "Nuevo Conteo de Operador: Planilla " . $details['invoice_number'];
                    }
                    $email_body = "<h1>Reporte de Conteo de Operador</h1><p>El operador <strong>" . htmlspecialchars($details['operator_name']) . "</strong> ha guardado un nuevo conteo.</p><hr><h2>Detalles de la Planilla</h2><ul><li><strong>Número de Planilla:</strong> " . htmlspecialchars($details['invoice_number']) . "</li><li><strong>Cliente:</strong> " . htmlspecialchars($details['client_name']) . "</li></ul><h2>Desglose del Conteo</h2><table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 300px;'><tr><td><strong>Denominación</strong></td><td style='text-align: right;'><strong>Cantidad</strong></td></tr><tr><td>$100.000</td><td style='text-align: right;'>" . number_format($data['bills_100k']) . "</td></tr><tr><td>$50.000</td><td style='text-align: right;'>" . number_format($data['bills_50k']) . "</td></tr><tr><td>$20.000</td><td style='text-align: right;'>" . number_format($data['bills_20k']) . "</td></tr><tr><td>$10.000</td><td style='text-align: right;'>" . number_format($data['bills_10k']) . "</td></tr><tr><td>$5.000</td><td style='text-align: right;'>" . number_format($data['bills_5k']) . "</td></tr><tr><td>$2.000</td><td style='text-align: right;'>" . number_format($data['bills_2k']) . "</td></tr><tr><td>Monedas</td><td style='text-align: right;'>$ " . number_format($data['coins']) . "</td></tr></table><h2>Totales</h2><ul><li><strong>Total Contado:</strong> $" . number_format($data['total_counted']) . "</li><li><strong>Discrepancia:</strong> <strong style='color: " . ($data['discrepancy'] != 0 ? 'red' : 'green') . ";'>$" . number_format($data['discrepancy']) . "</strong></li></ul><p><strong>Observaciones del Operador:</strong> " . (!empty($data['observations']) ? htmlspecialchars($data['observations']) : 'N/A') . "</p><br><p><em>Este es un correo automático del sistema EAGLE 3.0.</em></p>";

                    foreach ($recipients as $recipient) {
                        send_task_email($recipient['email'], $recipient['name'], $email_subject, $email_body);
                    }
                }
            }
        } catch (Exception $e) {
            // Log del error de correo sin afectar al cliente
            error_log("Error al enviar correo de notificación de conteo: " . $e->getMessage());
        }

    } catch (Exception $e) {
        $conn->rollback();
        // Solo enviar error si falla la transacción de la BD
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $e->getMessage()]);
    }
}

$conn->close();
?>