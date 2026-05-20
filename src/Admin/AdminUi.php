<?php
namespace ProvidiMailer\Admin;

use Sanitize;

class AdminUi {
    public static function render(object $plugin, string $logDir): string {
        global $L;
        
        $subpage = $_GET['subpage'] ?? 'dashboard';
        $baseUrl = HTML_PATH_ADMIN_ROOT . 'configure-plugin/' . $plugin->className();

	// High-level structural tabs
        $html = '<ul class="nav nav-tabs mb-4">';
        $html .= '<li class="nav-item"><a class="nav-link '.($subpage==='dashboard'?'active':'').'" href="'.$baseUrl.'?subpage=dashboard">'.$L->get('tab-dashboard').'</a></li>';
        $html .= '<li class="nav-item"><a class="nav-link '.($subpage==='settings'?'active':'').'" href="'.$baseUrl.'?subpage=settings">'.$L->get('tab-settings').'</a></li>';
        $html .= '<li class="nav-item"><a class="nav-link '.($subpage==='logs'||$subpage==='view-log'?'active':'').'" href="'.$baseUrl.'?subpage=logs">'.$L->get('tab-logs').'</a></li>';
        $html .= '</ul>';

        // Load transactional index
        $indexFile = $logDir . 'index.json';
        $indexData = file_exists($indexFile) ? json_decode(file_get_contents($indexFile), true) : [];

        switch ($subpage) {
            case 'settings':
                $html .= self::renderSettings($plugin);
                break;
            case 'logs':
                $html .= self::renderLogs($indexData, $baseUrl);
                break;
            case 'view-log':
                $html .= self::renderLogDetail($logDir, $_GET['id'] ?? '', $baseUrl);
                break;
            case 'dashboard':
            default:
                $html .= self::renderDashboard($indexData, $logDir);
                break;
        }

        return $html;
    }

    private static function renderDashboard(array $index, string $logDir): string {
        global $L;
        $total = count($index);
        $successes = 0;
        $activeFailures = 0;
        $errorBreakdown = [];

        foreach ($index as $entry) {
            if ($entry['success']) {
                $successes++;
            } elseif (!empty($entry['resolved'])) {
                // Skip investigated failures in the dashboard warnings
                continue;
            } else {
                $activeFailures++;
                // Read loose file quickly to see exact fatal error message type
                $looseFile = $logDir . $entry['id'] . '.json';
                if (file_exists($looseFile)) {
                    $detail = json_decode(file_get_contents($looseFile), true);
                    $errMsg = $detail['message'] ?? 'Unknown Connection Drop';
                    // Strip system paths to keep error grouping clean
                    $cleanMsg = current(explode(':', $errMsg));
                    $errorBreakdown[$cleanMsg] = ($errorBreakdown[$cleanMsg] ?? 0) + 1;
                }
            }
        }

        $rate = $total > 0 ? round(($successes / $total) * 100, 1) : 100;

        // Metrics Summary Row
        $html = '<div class="row mb-4">';
        $html .= '<div class="col-md-4"><div class="card bg-light"><div class="card-body"><h5>'.$L->get('stats-processed').'</h5><h2 class="text-primary font-weight-bold">'.$total.'</h2></div></div></div>';
        $html .= '<div class="col-md-4"><div class="card bg-light"><div class="card-body"><h5>'.$L->get('stats-success-rate').'</h5><h2 class="text-success font-weight-bold">'.$rate.'%</h2></div></div></div>';
        $html .= '<div class="col-md-4"><div class="card bg-light"><div class="card-body"><h5>'.$L->get('stats-failed').'</h5><h2 class="text-danger font-weight-bold">'.$activeFailures.'</h2></div></div></div>';
        $html .= '</div>';

        // Error Signature Analysis
        if ($activeFailures > 0) {
            $html .= '<div class="card card-default mb-4"><div class="card-header font-weight-bold text-danger">'.$L->get('stats-error-breakdown').'</div><div class="card-body">';
            foreach ($errorBreakdown as $error => $count) {
                $percentage = round(($count / $activeFailures) * 100, 1);
                $html .= '<div class="mb-2"><strong>'.$error.'</strong> <span class="float-right text-muted">'.$count.' occurrences</span>';
                $html .= '<div class="progress mt-1"><div class="progress-bar bg-danger" role="progressbar" style="width: '.$percentage.'%"></div></div></div>';
            }
            $html .= '</div></div>';
        }

        return $html;
    }

    private static function renderLogs(array $index, string $baseUrl): string {
        global $L;
        if (empty($index)) {
            return '<div class="alert alert-info">'.$L->get('log-empty').'</div>';
        }

        $html = '<table class="table table-striped table-bordered mt-3">';
        $html .= '<thead><tr><th>'.$L->get('log-time').'</th><th>'.$L->get('log-to').'</th><th>'.$L->get('log-subject').'</th><th>'.$L->get('log-status').'</th><th>'.$L->get('log-actions').'</th></tr></thead><tbody>';
        
        foreach ($index as $entry) {
            if ($entry['success']) {
                $badge = '<span class="badge badge-success">'.$L->get('status-ok').'</span>';
            } elseif (!empty($entry['resolved'])) {
                $badge = '<span class="badge badge-warning">Investigated</span>';
            } else {
                $badge = '<span class="badge badge-danger">'.$L->get('status-fail').'</span>';
            }
            $html .= '<tr>';
            $html .= '<td>'.date('Y-m-d H:i:s', strtotime($entry['timestamp'])).'</td>';
            $html .= '<td>'.sanitize::html($entry['to']).'</td>';
            $html .= '<td>'.sanitize::html($entry['subject']).'</td>';
            $html .= '<td>'.$badge.'</td>';
            $html .= '<td><a href="'.$baseUrl.'?subpage=view-log&id='.$entry['id'].'" class="btn btn-sm btn-outline-secondary">'.$L->get('log-view-details').'</a></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private static function renderLogDetail(string $logDir, string $id, string $baseUrl): string {
        global $L;
        $file = $logDir . $id . '.json';
        if (!file_exists($file)) {
            return '<div class="alert alert-danger">Log asset missing.</div>';
        }

        $data = json_decode(file_get_contents($file), true);
        
        $html = '<div class="mb-3 clearfix">';
        $html .= '<a href="'.$baseUrl.'?subpage=logs" class="btn btn-sm btn-secondary float-left">&larr; '.$L->get('back-to-logs').'</a>';
        if (!$data['success']) {
            $html .= '<button id="btn-resolve-log" data-id="'.$id.'" class="btn btn-sm btn-outline-success float-right" style="cursor:pointer;"><span class="fa fa-check mr-1"></span> Mark as Investigated</button>';
        }
        $html .= '</div>';

        $html .= '<h3>'.$L->get('transcript-title').' <small class="text-muted">('.$id.')</small></h3>';

        // Diagnostic Breakdown Meta
        $html .= '<div class="card bg-dark text-white mb-4"><div class="card-header font-weight-bold">'.$L->get('transcript-meta').'</div><div class="card-body">';
        $html .= '<div><strong>Timestamp:</strong> '.$data['timestamp'].'</div>';
        $html .= '<div><strong>Recipient:</strong> '.sanitize::html($data['email_data']['to'] ?? '').'</div>';
        $html .= '<div><strong>Result State:</strong> '.($data['success'] ? 'OK' : 'ERROR: ' . sanitize::html($data['message'])).'</div>';
        $html .= '</div></div>';

        // Deep Raw Session Transcript Box
        $html .= '<h5>'.$L->get('transcript-raw').'</h5>';
        $html .= '<pre class="p-3 bg-light border text-monospace" style="max-height: 450px; overflow-y: scroll; font-size: 12px; color: #ddd; background-color: #333 !important;">';
        foreach (($data['transcript'] ?? []) as $line) {
            $lineHtml = sanitize::html($line);
            if (str_starts_with($line, 'SERVER -> CLIENT')) {
                $html .= '<span class="text-success">' . $lineHtml . '</span>' . PHP_EOL;
            } elseif (str_starts_with($line, 'CLIENT -> SERVER')) {
                $html .= '<span class="text-primary font-weight-bold">' . $lineHtml . '</span>' . PHP_EOL;
            } else {
                $html .= $lineHtml . PHP_EOL;
            }
        }
        $html .= '</pre>';

        global $security;
        $tokenCSRF = $security->getTokenCSRF();
        $html .= '<script>
            document.getElementById("btn-resolve-log")?.addEventListener("click", function() {
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = "<span class=\'spinner-border spinner-border-sm mr-1\'></span> Saving...";
                
                const payload = "providi_action=resolve_log&log_id=" + btn.getAttribute("data-id") + "&tokenCSRF=' . $tokenCSRF . '&plugin_csrf=' . $tokenCSRF . '";
                
                fetch("' . HTML_PATH_ADMIN_ROOT . '", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: payload
                })
                .then(res => res.json())
                .then(res => {
                    if(res.success) {
                        btn.innerHTML = "<span class=\'fa fa-check mr-1\'></span> Investigated";
                        btn.className = "btn btn-sm btn-success float-right";
                    } else {
                        btn.disabled = false;
                        alert("Error: " + res.message);
                    }
                });
            });
        </script>';

        return $html;
    }

    private static function renderSettings(object $plugin): string {
        global $security, $L;
        $tokenCSRF = $security->getTokenCSRF();
        $currentEngine = $plugin->getValue('engine');

        $html = '<form method="POST">';
        $html .= '<input type="hidden" id="providi_csrf_token" name="tokenCSRF" value="'.$tokenCSRF.'">';
 
        // Engine Selector
        $html .= '<h4 class="mb-3 border-bottom pb-2">Transmission Engine</h4>';
        $html .= '<div class="form-group"><select class="form-control" id="engine-selector" name="engine">';
        $html .= '<option value="secure_smtp" '.($currentEngine==='secure_smtp'?'selected':'').'>Secure SMTP (Dreamhost, Office365, etc)</option>';
        $html .= '<option value="amazon_ses" '.($currentEngine==='amazon_ses'?'selected':'').'>Amazon SES (API)</option>';
        $html .= '<option value="native_php" '.($currentEngine==='native_php'?'selected':'').'>Native PHP Mail (Fallback)</option>';
        $html .= '</select></div>';

        // --- SMTP SETTINGS GROUP ---
        $html .= '<div class="engine-group mt-4" id="group-secure_smtp" style="display: none;">';
        $html .= '<h4 class="mb-3 border-bottom pb-2">'.$L->get('smtp-settings').'</h4>';
        $html .= '<div class="form-group"><label>'.$L->get('smtp-host').'</label><input type="text" class="form-control" name="smtp_host" value="'.\Sanitize::html($plugin->getValue('smtp_host')).'"></div>';
        $html .= '<div class="form-group"><label>'.$L->get('port').'</label><input type="text" class="form-control" name="smtp_port" value="'.\Sanitize::html($plugin->getValue('smtp_port')).'"></div>';
        $html .= '<div class="form-group"><label>'.$L->get('security-protocol').'</label><select class="form-control" name="smtp_secure">';
        $html .= '<option value="ssl" '.($plugin->getValue('smtp_secure')==='ssl'?'selected':'').'>SSL</option>';
        $html .= '<option value="tls" '.($plugin->getValue('smtp_secure')==='tls'?'selected':'').'>TLS</option>';
        $html .= '<option value="" '.($plugin->getValue('smtp_secure')===''?'selected':'').'>None</option>';
        $html .= '</select></div>';
        $html .= '<div class="form-group"><label>'.$L->get('username').'</label><input type="text" class="form-control" name="smtp_user" value="'.\Sanitize::html($plugin->getValue('smtp_user')).'"></div>';
        $html .= '<div class="form-group"><label>'.$L->get('password').'</label><input type="password" class="form-control" name="smtp_pass" value="'.\Sanitize::html($plugin->getValue('smtp_pass')).'"></div>';
        $html .= '</div>';

        // --- AMAZON SES SETTINGS GROUP ---
        $html .= '<div class="engine-group mt-4" id="group-amazon_ses" style="display: none;">';
        $html .= '<h4 class="mb-3 border-bottom pb-2">Amazon SES API Configuration</h4>';
        $html .= '<div class="form-group"><label>AWS Access Key ID</label><input type="text" class="form-control" name="ses_access_key" value="'.\Sanitize::html($plugin->getValue('ses_access_key')).'"></div>';
        $html .= '<div class="form-group"><label>AWS Secret Access Key</label><input type="password" class="form-control" name="ses_secret_key" value="'.\Sanitize::html($plugin->getValue('ses_secret_key')).'"></div>';
        $html .= '<div class="form-group"><label>AWS Region (e.g. us-east-1)</label><input type="text" class="form-control" name="ses_region" value="'.\Sanitize::html($plugin->getValue('ses_region')).'"></div>';
        $html .= '</div>';

        // --- GLOBAL SENDER (Always Visible) ---
        $html .= '<h4 class="mt-4 mb-3 border-bottom pb-2">'.$L->get('sender-settings').'</h4>';
        $html .= '<div class="form-group"><label>'.$L->get('from-email').'</label><input type="text" class="form-control" name="from_email" value="'.\Sanitize::html($plugin->getValue('from_email')).'"></div>';
        $html .= '<div class="form-group"><label>'.$L->get('from-name').'</label><input type="text" class="form-control" name="from_name" value="'.\Sanitize::html($plugin->getValue('from_name')).'"></div>';

        $html .= '<button type="submit" class="btn btn-primary mt-3" name="save_settings">'.$L->get('save-settings').'</button>';
        $html .= '</form>';

        // --- DOMAIN HEALTH AUDIT ---
        $fromEmail = $plugin->getValue('from_email');
        if (!empty($fromEmail) && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $html .= '<div class="card border-secondary mt-5">';
            $html .= '<div class="card-header bg-secondary text-white font-weight-bold"><span class="fa fa-stethoscope mr-2"></span>Sender Domain Health Audit</div>';
            $html .= '<div class="card-body"><p class="text-muted small">Live DNS inspection for <strong>' . \Sanitize::html($fromEmail) . '</strong></p>';
            $html .= '<ul class="list-group">';
            
            $auditResults = \ProvidiMailer\Admin\DiagnosticAuditor::run($plugin);
            foreach ($auditResults as $res) {
                $icon = $res['status'] === 'success' ? 'fa-check text-success' : ($res['status'] === 'danger' ? 'fa-times text-danger' : ($res['status'] === 'info' ? 'fa-info-circle text-info' : 'fa-exclamation-triangle text-warning'));
                $html .= '<li class="list-group-item"><span class="fa ' . $icon . ' mr-2"></span> ' . $res['msg'] . '</li>';
            }
            $html .= '</ul></div></div>';
        }

        // --- UI TOGGLE SCRIPT ---
        $html .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const selector = document.getElementById("engine-selector");
                const groups = document.querySelectorAll(".engine-group");

                function toggleGroups() {
                    groups.forEach(group => group.style.display = "none");
                    const activeGroup = document.getElementById("group-" + selector.value);
                    if (activeGroup) activeGroup.style.display = "block";
                }

                selector.addEventListener("change", toggleGroups);
                toggleGroups(); // Run on load
            });
        </script>';

        // --- LIVE DIAGNOSTIC MODULE ---
        $html .= '<div class="card border-info mt-5">';
        $html .= '<div class="card-header bg-info text-white font-weight-bold"><span class="fa fa-paper-plane mr-2"></span>'.$L->get('test-email-heading').'</div>';
        $html .= '<div class="card-body">';
        $html .= '<p class="text-muted small">This uses the currently saved configuration. Save your settings above before running a test.</p>';
        
        $html .= '<div class="form-group"><label>'.$L->get('test-email-label').'</label>';
        $html .= '<div class="input-group">';
        $html .= '<input type="email" class="form-control" id="test-email-address" placeholder="admin@yourdomain.com">';
        $html .= '<div class="input-group-append"><button type="button" class="btn btn-info" id="btn-send-test">'.$L->get('test-email-btn').'</button></div>';
        $html .= '</div></div>';
        
        $html .= '<div id="test-result-wrapper" style="display:none;" class="mt-4">';
        $html .= '<h5 class="font-weight-bold border-bottom pb-2" id="test-result-status"></h5>';
        $html .= '<h6 class="text-muted mt-3">'.$L->get('test-live-transcript').'</h6>';
        $html .= '<pre id="test-live-transcript-box" class="p-3 bg-dark text-white border text-monospace" style="max-height: 400px; overflow-y: auto; font-size: 12px;"></pre>';
        $html .= '</div>';
        
        $html .= '</div></div>';

        // --- AJAX FETCH SCRIPT ---
        $html .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const testBtn = document.getElementById("btn-send-test");
                if (!testBtn) return;
                
                testBtn.addEventListener("click", function(e) {
                    e.preventDefault();
                    const emailInput = document.getElementById("test-email-address").value;
                    
                    const csrfToken = "' . $tokenCSRF . '";
                    const resultWrapper = document.getElementById("test-result-wrapper");
                    const statusBox = document.getElementById("test-result-status");
                    const transcriptBox = document.getElementById("test-live-transcript-box");
                    
                    if (!emailInput) {
                        alert("Please enter an email address.");
                        return;
                    }
                    
                    testBtn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span>\';
                    testBtn.disabled = true;
                    resultWrapper.style.display = "block";
                    statusBox.className = "text-info";
                    statusBox.innerHTML = "Negotiating connection... Please wait.";
                    transcriptBox.innerHTML = "Awaiting socket data...";

                    const payload = "providi_action=send_test&test_email=" + encodeURIComponent(emailInput) + "&tokenCSRF=" + csrfToken + "&plugin_csrf=" + csrfToken;
                    const ajaxUrl = "' . HTML_PATH_ADMIN_ROOT . '?providi_ajax=send_test";
                    
                    fetch(ajaxUrl, {
                        method: "POST",
                        body: payload,
                        credentials: "same-origin",
                        headers: { 
                            "Content-Type": "application/x-www-form-urlencoded",
                            "X-Requested-With": "XMLHttpRequest" 
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        testBtn.innerHTML = "' . $L->get('test-email-btn') . '";
                        testBtn.disabled = false;
                        
                        if (data.success) {
                            statusBox.className = "text-success";
                            statusBox.innerHTML = "&#10004; ' . $L->get('test-email-success') . '";
                        } else {
                            statusBox.className = "text-danger";
                            statusBox.innerHTML = "&#10008; ' . $L->get('test-email-error') . ' - " + (data.message || "Unknown Error.");
                        }
                        
                        let formatted = "";
                        if (data.debug) {
                            formatted += "=========================================\\n";
                            formatted += " SERVER DEBUG STATE\\n";
                            formatted += "=========================================\\n";
                            formatted += JSON.stringify(data.debug, null, 2) + "\\n\\n";
                        }

                        if (data.transcript && data.transcript.length > 0) {
                            formatted += "=========================================\\n";
                            formatted += " SMTP TRANSCRIPT\\n";
                            formatted += "=========================================\\n";
                            data.transcript.forEach(line => {
                                let safeLine = line.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                                if (safeLine.startsWith("SERVER -&gt; CLIENT")) {
                                    formatted += "<span class=\'text-success\'>" + safeLine + "</span>\\n";
                                } else if (safeLine.startsWith("CLIENT -&gt; SERVER")) {
                                    formatted += "<span class=\'text-info font-weight-bold\'>" + safeLine + "</span>\\n";
                                } else {
                                    formatted += safeLine + "\\n";
                                }
                            });
                        } else if (!data.debug) {
                            formatted += "No transcript generated.";
                        }
                        
                        transcriptBox.innerHTML = formatted;
                    })
                    .catch(err => {
                        testBtn.innerHTML = "' . $L->get('test-email-btn') . '";
                        testBtn.disabled = false;
                        statusBox.className = "text-danger";
                        statusBox.innerHTML = "FATAL AJAX ERROR: " + err.message;
                        transcriptBox.innerHTML = "Check your browser console or server error log. The server returned HTML instead of JSON.";
                    });
                });
            });
        </script>';
        return $html;
    }
}
