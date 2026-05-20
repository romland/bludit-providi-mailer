<?php
namespace ProvidiMailer\Engines;

class AmazonSesEngine implements MailEngineInterface {
    
    public function __construct(
        private array $config, 
        private \ProvidiMailer\Logger $logger
    ) {}

    public function send(string $to, string $subject, string $message, array $headers = []): bool {
        $region = $this->config['ses_region'] ?: 'us-east-1';
        $host = 'email.' . $region . '.amazonaws.com';
        $endpoint = 'https://' . $host . '/v2/email/outbound-emails';

        $this->logger->appendTranscript("Initializing Amazon SES v2 API connection...");
        $this->logger->appendTranscript("Endpoint: " . $endpoint);

        // 1. Construct the SES v2 JSON Payload
        $fromFormatted = !empty($this->config['from_name']) 
            ? '"' . $this->config['from_name'] . '" <' . $this->config['from_email'] . '>'
            : $this->config['from_email'];

        $payloadArray = [
            'FromEmailAddress' => $fromFormatted,
            'Destination' => [
                'ToAddresses' => [$to]
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data' => $subject,
                        'Charset' => 'UTF-8'
                    ],
                    'Body' => [
                        'Html' => [
                            'Data' => $message,
                            'Charset' => 'UTF-8'
                        ]
                    ]
                ]
            ]
        ];

        $payload = json_encode($payloadArray);
        $this->logger->appendTranscript("CLIENT -> SERVER (Payload): " . $payload);

        // 2. Generate AWS Signature V4 Headers
        try {
            $authHeaders = $this->generateSigV4Headers($host, $region, $payload);
            $this->logger->appendTranscript("CLIENT -> SERVER (Headers generated using AWS SigV4)");
        } catch (\Exception $e) {
            throw new \Exception("AWS Cryptography Error: " . $e->getMessage());
        }

        // 3. Execute cURL Request
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $authHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 4. Handle Response & Logging
        if ($response === false) {
            $this->logger->appendTranscript("SERVER -> CLIENT (cURL Error): " . $curlError);
            throw new \Exception("cURL Error: " . $curlError);
        }

        $this->logger->appendTranscript("SERVER -> CLIENT (HTTP " . $httpCode . "): " . $response);

        // HTTP 200 is success in SES API v2
        if ($httpCode === 200) {
            return true;
        }

        // Parse AWS JSON error response so it shows cleanly in our Bludit UI Dashboard
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['Message'] ?? $errorData['message'] ?? 'Unknown AWS Error';
        
        throw new \Exception("SES Error ($httpCode): " . $errorMessage);
    }

    /**
     * Calculates the AWS Signature V4 cryptographic headers manually.
     * Keeps the plugin lightweight without needing the massive AWS SDK.
     */
    private function generateSigV4Headers(string $host, string $region, string $payload): array {
        $accessKey = $this->config['ses_access_key'];
        $secretKey = $this->config['ses_secret_key'];
        
        if (empty($accessKey) || empty($secretKey)) {
            throw new \Exception("Missing Amazon SES Access Key or Secret Key.");
        }

        $service = 'ses';
        $algorithm = 'AWS4-HMAC-SHA256';
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        // Step 1: Create canonical request
        $canonicalUri = '/v2/email/outbound-emails';
        $canonicalQueryString = '';
        $canonicalHeaders = "content-type:application/json\nhost:" . $host . "\nx-amz-date:" . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $payloadHash = hash('sha256', $payload);

        $canonicalRequest = "POST\n" . $canonicalUri . "\n" . $canonicalQueryString . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        // Step 2: Create string to sign
        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

        // Step 3: Calculate the signature
        $kSecret = 'AWS4' . $secretKey;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // Step 4: Build Authorization header
        $authorizationHeader = $algorithm . ' Credential=' . $accessKey . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        return [
            'Content-Type: application/json',
            'X-Amz-Date: ' . $amzDate,
            'Authorization: ' . $authorizationHeader
        ];
    }
}
