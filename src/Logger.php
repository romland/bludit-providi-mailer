<?php
namespace ProvidiMailer;

class Logger {
    private string $logDir;
    private string $currentLogId;
    private array $currentTranscript = [];

    public function __construct(string $logDir) {
        $this->logDir = rtrim($logDir, '/') . '/';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function getCurrentLogId(): ?string {
        return $this->currentLogId ?? null;
    }

    public function startLog(): string {
        // Creates an ID like 'mail_64f9b2...'
        $this->currentLogId = uniqid('mail_');
        $this->currentTranscript = [];
        return $this->currentLogId;
    }

    public function appendTranscript(string $data): void {
        // Strip trailing newlines for cleaner JSON arrays
        $this->currentTranscript[] = trim($data);
    }

    public function finishLog(string $id, bool $success, string $message, array $emailData): void {
        $logFile = $this->logDir . $id . '.json';
        $indexFile = $this->logDir . 'index.json';

        // 1. Write the massive detailed loose file
        $fileData = [
            'id' => $id,
            'timestamp' => date('c'),
            'success' => $success,
            'message' => $message,
            'email_data' => $emailData,
            'transcript' => $this->currentTranscript
        ];
        file_put_contents($logFile, json_encode($fileData, JSON_PRETTY_PRINT));

        // 2. Update the lightweight Index file
        $index = file_exists($indexFile) ? json_decode(file_get_contents($indexFile), true) : [];
        
        // Prepend so the newest emails are at the top of the array
        array_unshift($index, [
            'id' => $id,
            'timestamp' => date('c'),
            'to' => $emailData['to'],
            'subject' => $emailData['subject'],
            'success' => $success
        ]);
        
        // Optional: Keep the index file small (e.g., only hold the last 500 emails)
        if (count($index) > 500) array_pop($index);

        file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT));
    }
}
