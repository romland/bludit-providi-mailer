## Mail engine for Bludit CMS

**ProvidiMailer** is a robust, developer-first mail engine for the Bludit CMS built specifically to eliminate the "black box" of email delivery. Instead of blindly firing off emails and hoping they arrive, this plugin gives you complete observability and proactively stops you from making common configuration mistakes.

Let's be honest: configuring website email delivery is a nightmare of silent failures, spam filters, and DNS footguns. If you only set up SMTP once a year, you are practically guaranteed to step on a landmine -- whether it's a mismatched DMARC policy, a missing PHP extension, or an anti-spoofing filter quietly dropping your emails into the void.


### Core Features

* **Proactive Footgun Auditor:** Before you even send an email, the dashboard runs a live diagnostic check against your sender domain. It instantly flags missing SPF/DMARC records, domain mismatches, unresolvable hosts, missing OpenSSL extensions, and free-mail provider conflicts.
* **Raw SMTP Transcripts:** No more guessing why an email failed. ProvidiMailer intercepts the raw socket chatter between your website and the SMTP server and displays it in a clean, color-coded transcript right in the Bludit admin panel.
* **Centralized Logging:** Every email sent through the system is logged. You get a dashboard tracking your success rates, error signatures, and a full history of payloads that you can manually mark as "investigated" once resolved.
* **Global Developer API:** Designed to be the central nervous system for your site's emails. Other plugins and themes (like contact forms or e-commerce modules) can safely hook into `\ProvidiMailer\BluditMailer::send()` to guarantee reliable delivery, keeping all outbound mail routing through one heavily monitored chokepoint.
* **Multiple Engines:** Native support for Secure SMTP (Office365, Dreamhost, etc.), with an extensible architecture ready for Amazon SES and native PHP mail fallbacks.

## Installation

**1. Clone the plugin**
Navigate to your Bludit plugins directory and clone this repository:

```bash
cd bl-plugins
git clone https://github.com/YOUR_USERNAME/providi-mailer.git providi-mailer

```

**2. Install dependencies (PHPMailer)**
The plugin intentionally avoids Composer to keep things lightweight. You just need to drop PHPMailer directly into the vendor folder:

```bash
cd providi-mailer/vendor
git clone https://github.com/PHPMailer/PHPMailer.git

```

**3. Activate**
Log in to your Bludit Admin panel, go to **Settings > Plugins**, and click **Activate** on ProvidiMailer. Configure your sender settings and run the built-in diagnostic test to verify your DNS!



## How to use ProvidiMailer in your Bludit plugin or theme
Excessive and defensive on purpose, you don't want this to silently fail.
```
        $to = 'admin@example.com';
        $subject = 'New Form Submission';
        $template = '<h1>Hello</h1><p>This is the body...</p>';

        if (class_exists('\ProvidiMailer\BluditMailer')) {
            try {
                // Bludit hook execution order runs beforeSiteLoad() before beforeAll().
                // We must force ProvidiMailer to initialize if it hasn't already.
                global $plugins;
                $mailerObj = $plugins['all']['pluginProvidiMailer'] ?? ($plugins['all']['pluginprovidimailer'] ?? null);
                if ($mailerObj) {
                    $mailerObj->beforeAll();
                } else {
                    error_log("AdvancedForms -> WARNING: Could not find ProvidiMailer object in global \$plugins!");
                }
                $success = \ProvidiMailer\BluditMailer::send($to, $subject, $template);

            } catch (\Throwable $e) {
                error_log("AdvancedForms -> ProvidiMailer Error: " . $e->getMessage());
                $success = @mail($to, $subject, $template);
            }
        } else {
            // Fallback if plugin is disabled
            error_log("AdvancedForms -> ProvidiMailer Falling back to mail()");
            $success = mail($to, $subject, $template);
        }

```

## Adding a New Mail Engine to ProvidiMailer

ProvidiMailer uses a factory-driven, interface-based architecture. To add a new mailing provider (e.g., Mailgun, Microsoft Graph, SendGrid), you need to touch exactly four files.

The UI includes a vanilla JavaScript toggle that automatically hides and shows configuration fields based on the selected engine. You do not need to write any new JavaScript to support your new engine.

## Step 1: Create the Engine Class

Create a new file in `src/Engines/`. For this example, we will add **Mailgun**.

Create `src/Engines/MailgunEngine.php`. Your class **must** implement `MailEngineInterface`.

```php
<?php
namespace ProvidiMailer\Engines;

class MailgunEngine implements MailEngineInterface {
    public function __construct(
        private array $config, 
        private \ProvidiMailer\Logger $logger
    ) {}

    public function send(string $to, string $subject, string $message, array $headers = []): bool {
        // 1. Log initialization
        $this->logger->appendTranscript("Initializing Mailgun API API connection...");
        $this->logger->appendTranscript("Endpoint: " . $this->config['mailgun_domain']);
        
        try {
            // 2. Perform your cURL or SDK logic here using $this->config
            
            // 3. Log the raw API response for debugging
            $this->logger->appendTranscript("Mailgun Response: 200 OK - Queued.");
            return true;
            
        } catch (\Exception $e) {
            // Exceptions caught here are logged as fatal errors by the Logger
            throw new \Exception("Mailgun API Error: " . $e->getMessage());
        }
    }
}

```

## Step 2: Register the Database Schema

Bludit needs to know what fields to save to its JSON database. Open `/plugin.php` and add your new engine's default fields to the `$this->dbFields` array inside the `init()` method.

```php
    public function init() {
        $this->dbFields = array(
            'engine' => 'secure_smtp',
            
            // Existing settings...
            
            // --- NEW MAILGUN SETTINGS ---
            'mailgun_api_key' => '',
            'mailgun_domain' => '',
            'mailgun_region' => 'us',
            
            // Global Sender
            'from_email' => 'noreply@yourdomain.com',
            // ...
        );
    }

```

## Step 3: Update the Factory Router

Tell the global mailer how to instantiate your new class. Open `src/BluditMailer.php` and add your engine to the `match` statement in the `initialize()` method.

```php
    public static function initialize(array $config, string $logPath): void {
        self::$logger = new Logger($logPath);
        
        self::$engine = match ($config['engine']) {
            'amazon_ses' => new Engines\AmazonSesEngine($config, self::$logger),
            'mailgun'    => new Engines\MailgunEngine($config, self::$logger), // <-- ADD THIS
            'native_php' => new Engines\NativePhpEngine($config, self::$logger),
            default      => new Engines\SecureSmtpEngine($config, self::$logger),
        };
        
        self::$isReady = true;
    }

```

## Step 4: Add the UI Configuration Blocks

Open `src/Admin/AdminUi.php` and locate the `renderSettings()` method. You need to make two additions.

**A. Add it to the dropdown:**
Add your engine's `value` to the `<select id="engine-selector">` list. The `value` **must exactly match** the string you used in Step 3.

```php
$html .= '<option value="mailgun" '.($currentEngine==='mailgun'?'selected':'').'>Mailgun API</option>';

```

**B. Create the input group:**
Scroll down below the existing engine groups. Create a new `<div>` wrapping your fields.
*CRITICAL:* The `id` of this div must be exactly `group-` followed by your engine value (e.g., `group-mailgun`). The included JavaScript will automatically show/hide this div when the user changes the dropdown.

```php
        // --- MAILGUN SETTINGS GROUP ---
        $html .= '<div class="engine-group mt-4" id="group-mailgun" style="display: none;">';
        $html .= '<h4 class="mb-3 border-bottom pb-2">Mailgun Configuration</h4>';
        
        $html .= '<div class="form-group">';
        $html .= '<label>Mailgun API Key</label>';
        $html .= '<input type="text" class="form-control" name="mailgun_api_key" value="'.\Sanitize::html($plugin->getValue('mailgun_api_key')).'">';
        $html .= '</div>';
        
        $html .= '<div class="form-group">';
        $html .= '<label>Sending Domain</label>';
        $html .= '<input type="text" class="form-control" name="mailgun_domain" value="'.\Sanitize::html($plugin->getValue('mailgun_domain')).'">';
        $html .= '</div>';
        
        $html .= '</div>';

```

## Step 5: (Optional) Localize the Strings

If you are building for distribution, do not hardcode the labels in Step 4. Add keys like `"mailgun-api-key": "Mailgun API Nyckel"` to `languages/sv.json` and `languages/en.json`, and call them using `$L->get('mailgun-api-key')` in the UI renderer.

