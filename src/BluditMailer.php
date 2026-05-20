<?php
namespace ProvidiMailer;

class BluditMailer {
    private static Engines\MailEngineInterface $engine;
    private static Logger $logger;
    private static bool $isReady = false;
    private static ?string $lastLogId = null;

    public static function initialize(array $config, string $logPath): void {
        self::$logger = new Logger($logPath);

        // Factory pattern: expand this when you add Amazon SES, Mailgun, etc.
        self::$engine = match ($config['engine']) {
            'amazon_ses' => new Engines\AmazonSesEngine($config, self::$logger),
            'native_php' => new Engines\NativePhpEngine($config, self::$logger),
            default => new Engines\SecureSmtpEngine($config, self::$logger),
        };
 
        self::$isReady = true;
    }

    public static function getLastLogId(): ?string {
        return self::$lastLogId;
    }

    public static function send(string $to, string $subject, string $message, array $headers = []): bool {
        if (!self::$isReady) {
            throw new \Exception("BluditMailer not initialized.");
        }

        // 1. Generate a unique ID for this loose file
        $logId = self::$logger->startLog();
        self::$lastLogId = $logId;

        $emailData = [
            'to' => $to,
            'subject' => $subject,
            'headers' => $headers,
            'body_length' => strlen($message)
        ];

        try {
            // 2. Attempt delivery
            $result = self::$engine->send($to, $subject, $message, $headers);
            
            // 3. Write success log
            self::$logger->finishLog($logId, true, 'Delivery OK', $emailData);
            return $result;
            
        } catch (\Exception $e) {
            // 3. Write failure log with exception
            self::$logger->finishLog($logId, false, $e->getMessage(), $emailData);
            error_log("ProvidiMailer [Log ID: {$logId}]: Failed to send email to {$to}. Reason: " . $e->getMessage());
            return false;
        }
    }
}
