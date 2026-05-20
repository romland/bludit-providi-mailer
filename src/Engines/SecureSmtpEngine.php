<?php
namespace ProvidiMailer\Engines;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class SecureSmtpEngine implements MailEngineInterface {
    public function __construct(
        private array $config, 
        private \ProvidiMailer\Logger $logger
    ) {}

    public function send(string $to, string $subject, string $message, array $headers = []): bool {
        // Load PHPMailer (Assuming manual placement in vendor/)
        require_once dirname(__DIR__, 2) . '/vendor/PHPMailer/src/Exception.php';
        require_once dirname(__DIR__, 2) . '/vendor/PHPMailer/src/PHPMailer.php';
        require_once dirname(__DIR__, 2) . '/vendor/PHPMailer/src/SMTP.php';

        $mail = new PHPMailer(true);

        try {
            // Intercept the raw SMTP Session!
            $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
            $mail->Debugoutput = function($str, $level) {
                // Pipe the raw server chatter directly to our loose file
                $this->logger->appendTranscript($str);
            };

            $mail->isSMTP();
            $mail->Host       = $this->config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['smtp_user'];
            $mail->Password   = $this->config['smtp_pass'];
            $mail->SMTPSecure = $this->config['smtp_secure']; // 'ssl' or 'tls'
            $mail->Port       = (int)$this->config['smtp_port'];

            // Sender / Recipient
            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $message;

            // Suppress the PHPMailer footprint header
            $mail->XMailer = ' ';

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            // PHPMailer's specific error is passed up to the Facade
            throw new \Exception($mail->ErrorInfo);
        }
    }
}
