<?php
namespace ProvidiMailer\Admin;

class DiagnosticAuditor {
    /**
     * Audits the domain of the provided email address for vital email DNS records.
     * Returns an array of results: ['status' => 'success|warning|danger', 'msg' => '...']
     */
    public static function run(object $plugin): array {
        $results = [];
        $fromEmail = $plugin->getValue('from_email');
        $engine = $plugin->getValue('engine');
        $domain = substr(strrchr($fromEmail, "@"), 1);

        if (!$domain) {
            return [['status' => 'danger', 'msg' => 'Invalid email format.']];
        }

        // 1. FOOTGUN: Domain Mismatch 
        if ($engine === 'secure_smtp') {
            $smtpUser = $plugin->getValue('smtp_user');
            if (strpos($smtpUser, '@') !== false) {
                $smtpDomain = substr(strrchr($smtpUser, "@"), 1);
                if (strtolower($domain) !== strtolower($smtpDomain)) {
                    $results[] = [
                        'status' => 'danger', 
                        'msg' => "<strong>Domain Mismatch:</strong> Your From Email is @{$domain}, but your SMTP User is @{$smtpDomain}. Unless your mail server explicitly aliases these, strict DMARC/SPF anti-spoofing filters will silently drop your emails."
                    ];
                }
            }
        }

        // 1.5 FOOTGUN: Malformed SMTP Host
        if ($engine === 'secure_smtp') {
            $host = trim((string)$plugin->getValue('smtp_host'));
            if (preg_match('#^https?://#i', $host)) {
                $results[] = ['status' => 'danger', 'msg' => "<strong>Invalid SMTP Host:</strong> Remove 'http://' or 'https://' from your SMTP hostname. It should look like <code>smtp.example.com</code>, not a web URL."];
            } elseif (!empty($host) && !filter_var($host, FILTER_VALIDATE_IP)) {
                // 1.6 FOOTGUN: Unresolvable SMTP Host
                if (!checkdnsrr($host, "A") && !checkdnsrr($host, "AAAA") && !checkdnsrr($host, "CNAME")) {
                    $results[] = ['status' => 'danger', 'msg' => "<strong>Unresolvable SMTP Host:</strong> The server <code>{$host}</code> could not be found via DNS. Please check for typos."];
                }
            }
        }

        // 2. FOOTGUN: Free Email Providers
        $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com'];
        if (in_array(strtolower($domain), $freeProviders)) {
            $results[] = [
                'status' => 'danger',
                'msg' => "<strong>Free Email Provider:</strong> Sending from a @{$domain} address via a website plugin violates their DMARC policies. Your emails will likely bounce or go to spam. Use a domain you own."
            ];
            return $results; // Skip DNS checks for free providers, we know they will fail DMARC.
        }

        // 2.5 FOOTGUN: Non-Routable Testing Domains
        if (preg_match('/\.local$|\.test$|\.example$|localhost/i', $domain)) {
            $results[] = [
                'status' => 'danger',
                'msg' => "<strong>Non-Routable Domain:</strong> The domain <code>@{$domain}</code> is restricted for local testing. It cannot route email on the public internet."
            ];
            return $results; // Skip DNS checks
        }


        // 3. FOOTGUN: Port & Encryption Mismatches
        if ($engine === 'secure_smtp') {
            $port = $plugin->getValue('smtp_port');
            $secure = $plugin->getValue('smtp_secure');
            $pass = $plugin->getValue('smtp_pass');

            if (empty($pass)) {
                $results[] = ['status' => 'danger', 'msg' => "<strong>Missing Password:</strong> Secure SMTP is selected but the password field is empty."];
            }
            if ($port == '465' && $secure === 'tls') {
                $results[] = ['status' => 'warning', 'msg' => "<strong>Port Mismatch:</strong> Port 465 traditionally requires <strong>SSL</strong>, but you have <strong>TLS</strong> selected. If connection fails, switch to SSL or port 587."];
            }
            if ($port == '587' && $secure === 'ssl') {
                $results[] = ['status' => 'warning', 'msg' => "<strong>Port Mismatch:</strong> Port 587 traditionally uses <strong>TLS</strong> (STARTTLS), but you have <strong>SSL</strong> selected. If connection fails, switch to TLS."];
            }
            if ($port == '25') {
                $results[] = ['status' => 'warning', 'msg' => "<strong>Port 25:</strong> You are using port 25. Many hosting providers block outbound port 25 to prevent spam."];
            }

            // Check for OpenSSL extension if encryption is requested
            if (in_array($secure, ['ssl', 'tls']) && !extension_loaded('openssl')) {
                $results[] = ['status' => 'danger', 'msg' => "<strong>Missing PHP Extension:</strong> You requested {$secure} encryption, but the <code>openssl</code> PHP extension is not loaded on your server. The connection will fail."];
            }
        }

        // 4. FOOTGUN: Native PHP Mail
        if ($engine === 'native_php') {
            $results[] = ['status' => 'warning', 'msg' => "<strong>Native PHP Mail:</strong> You are using the fallback PHP mail() function. This is notoriously unreliable for deliverability and highly susceptible to spam filters."];
        }

        // 5. FOOTGUN: Amazon SES Formatting
        if ($engine === 'amazon_ses') {
            $access = $plugin->getValue('ses_access_key');
            $secret = $plugin->getValue('ses_secret_key');
            if (empty($access) || empty($secret)) {
                $results[] = ['status' => 'danger', 'msg' => "<strong>Missing AWS Credentials:</strong> Amazon SES requires both an Access Key and a Secret Key."];
            } elseif (strlen(trim($access)) !== 20) {
                $results[] = ['status' => 'warning', 'msg' => "<strong>Unusual AWS Access Key:</strong> AWS Access Keys are typically exactly 20 characters long. Double-check your entry for spaces or missing characters."];
            }
        }

        // 6. FOOTGUN: Missing Dependencies
        if ($engine === 'secure_smtp') {
            $pluginRoot = dirname(__DIR__, 2);
            if (!file_exists($pluginRoot . '/vendor/PHPMailer/src/PHPMailer.php')) {
                $results[] = [
                    'status' => 'danger',
                    'msg' => "<strong>Missing Dependency:</strong> PHPMailer is not installed. Run <code>cd vendor && git clone https://github.com/PHPMailer/PHPMailer.git</code> in your plugin directory."
                ];
            }
        }

        // 1. MX Records Check (Can the domain receive mail/bounces?)
        $mx = @dns_get_record($domain, DNS_MX);
        if (empty($mx)) {
            $results[] = ['status' => 'danger', 'msg' => "No MX records found for <strong>{$domain}</strong>. Receiving bounces or replies will fail."];
        } else {
            $results[] = ['status' => 'success', 'msg' => "MX records exist for <strong>{$domain}</strong>."];
        }

        // 2. SPF Record Check (Does the domain authorize senders?)
        $txtRecords = @dns_get_record($domain, DNS_TXT);
        $spfFound = false;
        if ($txtRecords) {
            foreach ($txtRecords as $record) {
                if (isset($record['txt']) && stripos($record['txt'], 'v=spf1') === 0) {
                    $spfFound = true;
                    break;
                }
            }
        }
        if (!$spfFound) {
            $results[] = ['status' => 'warning', 'msg' => "No SPF record (v=spf1) found for <strong>{$domain}</strong>. Unauthenticated emails are highly likely to be flagged as spam."];
        } else {
            $results[] = ['status' => 'success', 'msg' => "SPF record detected for <strong>{$domain}</strong>."];
        }

        // 3. DMARC Record Check (Do strict policies exist?)
        $dmarcDomain = '_dmarc.' . $domain;
        $dmarcRecords = @dns_get_record($dmarcDomain, DNS_TXT);
        $dmarcFound = false;
        if ($dmarcRecords) {
            foreach ($dmarcRecords as $record) {
                if (isset($record['txt']) && stripos($record['txt'], 'v=DMARC1') === 0) {
                    $dmarcFound = true;
                    break;
                }
            }
        }
        if (!$dmarcFound) {
            // Detect the DNS provider via NS records to give accurate UI instructions
            $nsRecords = @dns_get_record($domain, DNS_NS);
            $instructions = "Add a TXT record for <code>_dmarc</code> (or <code>_dmarc.{$domain}</code> depending on your host) with value: <code>v=DMARC1; p=none;</code>";
            
            if ($nsRecords) {
                $providerMap = [
                    'dreamhost' => "In DreamHost's DNS panel, add a TXT record with Host: <code>_dmarc</code> (do NOT type the domain) and Value: <code>v=DMARC1; p=none;</code>",
                    'cloudflare' => "In Cloudflare, add a TXT record with Name: <code>_dmarc</code> and Content: <code>v=DMARC1; p=none;</code>",
                    'awsdns' => "In AWS Route53, add a TXT record with Record Name: <code>_dmarc</code> and Value: <code>\"v=DMARC1; p=none;\"</code> (AWS requires quotes).",
                    'domaincontrol' => "In GoDaddy, add a TXT record with Name: <code>_dmarc</code> and Value: <code>v=DMARC1; p=none;</code>",
                    'digitalocean' => "In DigitalOcean, add a TXT record with Hostname: <code>_dmarc</code> and Value: <code>v=DMARC1; p=none;</code>",
                    'upcloud' => "In UpCloud, add a TXT record with Name: <code>_dmarc</code> and Text: <code>v=DMARC1; p=none;</code>",
                    'googledomains' => "In Google Domains, add a TXT record with Host: <code>_dmarc</code> and Data: <code>v=DMARC1; p=none;</code>",
                    'googlecloud' => "In Google Cloud DNS, add a TXT record with DNS Name: <code>_dmarc</code> and Data: <code>v=DMARC1; p=none;</code>",
                    'transip' => "In TransIP (NL), add a TXT record with Name: <code>_dmarc</code> and Value: <code>v=DMARC1; p=none;</code>",
                    'hostnet' => "In Hostnet (NL), add a TXT record with Name: <code>_dmarc</code> and Value: <code>v=DMARC1; p=none;</code>",
                    'mijndomein' => "In MijnDomein (NL), add a TXT record with Name: <code>_dmarc</code> and Content: <code>v=DMARC1; p=none;</code>",
                    'loopia' => "In Loopia (SE), add a TXT record with Subdomain: <code>_dmarc</code> and Data: <code>v=DMARC1; p=none;</code>",
                    'oderland' => "In Oderland (SE) cPanel, go to Zone Editor, add a TXT record with Name: <code>_dmarc</code> and Record: <code>v=DMARC1; p=none;</code>",
                    'one.com' => "In One.com, add a TXT record with Hostname: <code>_dmarc</code> and Value: <code>v=DMARC1; p=none;</code>",
                    'registrar-servers' => "In Namecheap, add a TXT record with Host: <code>_dmarc</code> and Value: <code>v=DMARC1; p=none;</code>",
                    'hetzner' => "In Hetzner Console, add a TXT record with Name: <code>_dmarc</code> and Value: <code>v=DMARC1; p=none;</code>"
                ];

                foreach ($nsRecords as $ns) {
                    $target = strtolower($ns['target'] ?? '');
                    foreach ($providerMap as $keyword => $instruction) {
                        if (strpos($target, $keyword) !== false) {
                            $instructions = $instruction;
                            break 2; // Break out of both the map loop and the NS loop
                        }
                    }
                }
            }
            $results[] = ['status' => 'warning', 'msg' => "No DMARC record found at <strong>{$dmarcDomain}</strong>. Gmail and Yahoo strictly require this for reliable delivery.<br><span class='text-muted small mt-1 d-block'><span class='fa fa-wrench mr-1'></span><strong>Fix:</strong> {$instructions}</span>"];
        } else {
            $results[] = ['status' => 'success', 'msg' => "DMARC record detected for <strong>{$domain}</strong>."];
        }

        // 4. DKIM Record Check (Heuristic)
        $commonSelectors = ['default', 'google', 'mail', 'dreamhost', 's1', 's2', 'api'];
        $dkimFound = false;
        $foundSelector = '';
        foreach ($commonSelectors as $selector) {
            $dkimDomain = $selector . '._domainkey.' . $domain;
            $dkimRecords = @dns_get_record($dkimDomain, DNS_TXT);
            if ($dkimRecords) {
                foreach ($dkimRecords as $record) {
                    if (isset($record['txt']) && stripos($record['txt'], 'v=DKIM1') === 0) {
                        $dkimFound = true;
                        $foundSelector = $selector;
                        break 2;
                    }
                }
            }
        }
        if ($dkimFound) {
            $results[] = ['status' => 'success', 'msg' => "DKIM record detected for <strong>{$domain}</strong> (Selector: <code>{$foundSelector}</code>)."];
        } else {
            $results[] = ['status' => 'info', 'msg' => "<strong>DKIM Check:</strong> Could not automatically detect a DKIM record. We checked common selectors (like 'google' and 'api'), but if you use a custom selector, this is normal. Ensure DKIM is set up in your DNS."];
        }

        return $results;
    }
}

