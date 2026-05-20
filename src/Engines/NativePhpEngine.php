<?php
namespace ProvidiMailer\Engines;

class NativePhpEngine implements MailEngineInterface {
    public function __construct(
        private array $config, 
        private \ProvidiMailer\Logger $logger
    ) {}

    public function send(string $to, string $subject, string $message, array $headers = []): bool {
        $this->logger->appendTranscript("Using native PHP mail() function. (No raw socket data available).");
        
        $headerStr = "From: " . $this->config['from_name'] . " <" . $this->config['from_email'] . ">\r\n";
        $headerStr .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        return mail($to, $subject, $message, $headerStr);
    }
}
