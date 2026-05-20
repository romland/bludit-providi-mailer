<?php

// We declare the Plugin class FIRST so Bludit's core bootloader grabs the right one.
class pluginProvidiMailer extends Plugin {

    public function init() {
        // Load our architectural files inside init() so they don't hijack the loader
        require_once __DIR__ . '/src/BluditMailer.php';
        require_once __DIR__ . '/src/Logger.php';
        require_once __DIR__ . '/src/Engines/MailEngineInterface.php';
        require_once __DIR__ . '/src/Engines/SecureSmtpEngine.php';
        require_once __DIR__ . '/src/Admin/AdminUi.php';
        require_once __DIR__ . '/src/Admin/DiagnosticAuditor.php';

        $this->dbFields = array(
            'engine' => 'secure_smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '465',
            'smtp_secure' => 'tls',
            'smtp_user' => 'example@example.com',
            'smtp_pass' => '',

            // Amazon SES Settings
            'ses_access_key' => '',
            'ses_secret_key' => '',
            'ses_region' => 'us-east-1',

            'from_email' => 'noreply@example.com',
            'from_name' => 'Joe Doe'
        );
    }

    public function beforeAll() {
        $logPath = $this->workspace() . 'logs/';
        
        // SECURITY AUDIT: Hard-deny web access to the raw JSON logs
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }
        if (!file_exists($logPath . '.htaccess')) {
            file_put_contents($logPath . '.htaccess', "Deny from all\n");
        }
        
        \ProvidiMailer\BluditMailer::initialize($this->db, $logPath);
    }

    // Intercept AJAX before Bludit's core controllers touch the CSRF token
    public function beforeAdminLoad() {
        global $security, $login;

        if (isset($_GET['providi_ajax']) && $_GET['providi_ajax'] === 'send_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');

            // --- RAW DEBUG DATA GATHERING ---
            $expectedCsrf = $security->getTokenCSRF();
            //$receivedCsrf = $_POST['tokenCSRF'] ?? 'MISSING_FROM_POST';
            $receivedCsrf = $_POST['plugin_csrf'] ?? 'MISSING_FROM_POST';
            
            $debugState = [
                'received_post_data' => $_POST,
                'expected_csrf' => $expectedCsrf,
                'received_csrf' => $receivedCsrf,
                'php_session_id' => session_id(),
                'bludit_username' => $login->username() ?? 'Not logged in'
            ];

            // Security: Must be an admin
            if ($login->role() !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required.', 'debug' => $debugState]);
                exit;
            }

            // Security: CSRF Validation
            if ($receivedCsrf !== $expectedCsrf) {
                echo json_encode(['success' => false, 'message' => 'CSRF Token Mismatch.', 'debug' => $debugState]);
                exit;
            }

            // Security: Sanitize & Validate Email
            $to = filter_var($_POST['test_email'] ?? '', FILTER_SANITIZE_EMAIL);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address format: ' . $to, 'debug' => $debugState]);
                exit;
            }

            // Execute the live test inside a try/catch so PHP fatals don't break our JSON!
            try {
                // Hook order constraint: we are exiting before beforeAll() naturally fires, so force it
                $this->beforeAll();

                $subject = "ProvidiMailer System Test";
                $message = "<p>This is a live diagnostic test from your ProvidiMailer dashboard.</p>";
                
                $success = \ProvidiMailer\BluditMailer::send($to, $subject, $message);
                
                $logId = \ProvidiMailer\BluditMailer::getLastLogId();
                $transcript = [];
                $logPath = $this->workspace() . 'logs/' . $logId . '.json';
                
                if ($logId && file_exists($logPath)) {
                    $logData = json_decode(file_get_contents($logPath), true);
                    $transcript = $logData['transcript'] ?? [];
                }

                echo json_encode([
                    'success' => $success,
                    'transcript' => $transcript,
//                    'debug' => $debugState
                ]);
            } catch (\Exception $e) {
                // Catching Mailer Exceptions so they don't 500 error out
                echo json_encode(['success' => false, 'message' => 'Mailer Exception: ' . $e->getMessage(), 'debug' => $debugState]);
            }
            exit;
        }

        // AJAX: Mark a failure log as investigated/resolved
        if (isset($_POST['providi_action']) && $_POST['providi_action'] === 'resolve_log') {
            header('Content-Type: application/json');
            $expectedCsrf = $security->getTokenCSRF();
            
            if ($login->role() !== 'admin' || ($_POST['plugin_csrf'] ?? '') !== $expectedCsrf) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized or CSRF mismatch']);
                exit;
            }

            $logId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['log_id'] ?? '');
            $indexFile = $this->workspace() . 'logs/index.json';
            
            if ($logId && file_exists($indexFile)) {
                $indexData = json_decode(file_get_contents($indexFile), true);
                foreach ($indexData as &$entry) {
                    if ($entry['id'] === $logId) { $entry['resolved'] = true; break; }
                }
                file_put_contents($indexFile, json_encode($indexData, JSON_PRETTY_PRINT));
            }
            echo json_encode(['success' => true]);
            exit;
        }
    }



    // Delegating rendering straight to the UI subsystem
    public function form() {
        $logPath = $this->workspace() . 'logs/';
        return \ProvidiMailer\Admin\AdminUi::render($this, $logPath);
    }

    // Injects a dedicated link into the main left-hand admin menu
    public function adminSidebar() {
        // The URL to our custom form interface
        $url = HTML_PATH_ADMIN_ROOT . 'configure-plugin/' . $this->className();
        
        // Return the HTML for the sidebar item using Bludit's native name() method
        $html = '<a id="current-request-providimailer" class="nav-link" href="' . $url . '">';
        $html .= '<span class="fa fa-envelope" style="margin-right: 5px;"></span>'; 
        $html .= $this->name();
        $html .= '</a>';
        
        return $html;
    }
}
