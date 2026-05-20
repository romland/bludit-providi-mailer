<?php
namespace ProvidiMailer\Engines;

interface MailEngineInterface {
    public function send(string $to, string $subject, string $message, array $headers = []): bool;
}

