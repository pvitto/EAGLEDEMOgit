<?php
// Incluir el autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';

// Importar las clases de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_task_email($to_email, $to_name, $subject, $body) {
    // --- CONFIGURACIÓN DE GMAIL ---
    // --- CONFIGURACIÓN DE GMAIL ---
    // IMPORTANTE: Reemplaza con tus propias credenciales.
    // Es recomendable usar variables de entorno o un archivo de configuración seguro.
    $gmail_user = 'demo.pmv@gmail.com'; // Tu dirección de correo de Gmail
    $gmail_password = 'hwrg ezoi ases ssut'; // Tu contraseña de aplicación de Gmail

    $mail = new PHPMailer(true);

    try {
        // Configuración del servidor
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $gmail_user;
        $mail->Password = $gmail_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        // Remitente
        $mail->setFrom($gmail_user, 'Sistema de Alertas EAGLE 3.0');

        // Destinatario
        $mail->addAddress($to_email, $to_name);

        // Contenido del correo
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body); // Versión en texto plano

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Registrar el error para depuración
        error_log("Error al enviar correo a {$to_email}: " . $mail->ErrorInfo);
        return false;
    }
}
