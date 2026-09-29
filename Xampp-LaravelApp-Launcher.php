<?php
$xampp_root  = 'C:/xampp';
$vhost_file  = $xampp_root . '/apache/conf/extra/httpd-vhosts.conf';
$htdocs_path = $xampp_root . '/htdocs';
$mysql_path  = $xampp_root . '/mysql';


function checkServicePort($port) {
    $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    return false;
}

// Check status via background socket ping
$apache_running = checkServicePort(80); // Default port 80 or customize if needed
$mysql_running  = checkServicePort(3306);


if (isset($_GET['start_xampp_services'])) {
    ini_set('display_errors', '0');
    header('Content-Type: application/json');

    // Silently trigger apache and mysql background start if not running
    if (!checkServicePort(80)) {
        pclose(popen("start /B {$xampp_root}/apache/bin/httpd.exe", "r"));
    }
    if (!checkServicePort(3306)) {
        pclose(popen("start /B {$xampp_root}/mysql/bin/mysqld.exe --defaults-file={$xampp_root}/mysql/bin/my.ini", "r"));
    }

    // Give it 1 second to bind
    sleep(1);

    $a_status = checkServicePort(80);
    $m_status = checkServicePort(3306);

    echo json_encode([
        "success" => true,
        "apache"  => $a_status,
        "mysql"   => $m_status
    ]);
    exit;
}


$php_versions = [];
if (is_dir($xampp_root)) {
    foreach (scandir($xampp_root) as $dir) {
        if (stripos($dir, 'php') === 0
            && is_dir($xampp_root . '/' . $dir)
            && file_exists($xampp_root . '/' . $dir . '/php-cgi.exe')) {
            $php_versions[] = $dir;
        }
    }
}
rsort($php_versions);

if (isset($_GET['inspect_php'])) {
    ini_set('display_errors', '0');
    header('Content-Type: application/json');

    $target_php = $_GET['inspect_php'];
    $php_exe = "{$xampp_root}/{$target_php}/php.exe";

    if (!file_exists($php_exe)) {
        echo json_encode(["success" => false, "message" => "php.exe not found"]);
        exit;
    }

    $info = [];
    exec("\"{$php_exe}\" -i 2>&1", $info);
    $info_str = implode("\n", $info);

    $thread_safe = preg_match('/Thread Safety\s*=>\s*enabled/i', $info_str) ? 'Thread Safe (TS)' : 'Non-Thread Safe (NTS)';
    $bits = preg_match('/Architecture\s*=>\s*x64/i', $info_str) ? '64-bit (x64)' : '32-bit (x86)';

    $php_version = preg_match('/^PHP Version\s*=>\s*(.+)$/mi', $info_str, $vm) ? trim($vm[1]) : 'unknown';

    $ext_output = [];
    exec("\"{$php_exe}\" -m 2>&1", $ext_output);
    $extensions = array_values(array_unique(array_filter(array_map('trim', $ext_output), function ($l) {
        return $l !== '' && $l[0] !== '[';
    })));
    natcasesort($extensions);
    $extensions = array_values($extensions);

    // PDO drivers = every pdo_* extension loaded on this PHP build
    $ext_lower   = array_map('strtolower', $extensions);
    $pdo_drivers = array_values(array_map(function ($e) {
        return substr($e, 4);
    }, array_filter($ext_lower, function ($e) {
        return strpos($e, 'pdo_') === 0;
    })));

    // Quick check for the drivers that matter (MSSQL + MySQL)
    $db_checks = [];
    foreach (['pdo', 'pdo_sqlsrv', 'sqlsrv', 'pdo_mysql', 'mysqli', 'pdo_odbc', 'odbc'] as $need) {
        $db_checks[$need] = in_array($need, $ext_lower, true);
    }

    echo json_encode([
        "success"     => true,
        "php_version" => $php_version,
        "thread_safe" => $thread_safe,
        "bits"        => $bits,
        "extensions"  => $extensions,
        "pdo_drivers" => $pdo_drivers,
        "db_checks"   => $db_checks
    ]);
    exit;
}

$server_ip = gethostbyname(gethostname());
$response  = ["status" => "", "message" => ""];
$excluded_folders = ['.', '..', 'dashboard', 'img', 'xampp', 'webalizer', 'bitnami', 'launcher'];

// Is mod_fcgid installed AND loaded? (Stock XAMPP does not ship it.)
function fcgidEnabled($xampp_root) {
    if (!file_exists($xampp_root . '/apache/modules/mod_fcgid.so')) return false;
    $files = array_merge(
        [$xampp_root . '/apache/conf/httpd.conf'],
        glob($xampp_root . '/apache/conf/extra/*.conf') ?: []
    );
    foreach ($files as $f) {
        if (is_file($f) && preg_match('/^\s*LoadModule\s+fcgid_module\s/mi', file_get_contents($f))) return true;
    }
    return false;
}
$fcgid_enabled = fcgidEnabled($xampp_root);

function autoHealMySQL($mysql_path) {
    $data_dir   = $mysql_path . '/data';
    $backup_dir = $mysql_path . '/backup';
    if (!is_dir($backup_dir)) return "MySQL Auto-Heal failed: 'backup' folder missing.";

    $counter = "";
    while (is_dir($mysql_path . '/data_old' . $counter)) {
        $counter = ($counter === "") ? 2 : $counter + 1;
    }
    $new_data_old = $mysql_path . '/data_old' . $counter;
    if (is_dir($data_dir)) @rename($data_dir, $new_data_old);
    @mkdir($data_dir, 0777, true);

    foreach (scandir($backup_dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $src = $backup_dir . '/' . $file;
        $dst = $data_dir . '/' . $file;
        if (is_dir($src)) {
            shell_exec('xcopy /E /I /Q "' . str_replace('/', '\\', $src) . '" "' . str_replace('/', '\\', $dst) . '" > NUL');
        } else { @copy($src, $dst); }
    }
    return "MySQL successfully healed and recovered!";
}

$projects = [];
if (is_dir($htdocs_path)) {
    $projects = array_values(array_filter(scandir($htdocs_path), function ($item) use ($htdocs_path, $excluded_folders) {
        return !in_array($item, $excluded_folders, true) && is_dir($htdocs_path . '/' . $item);
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'deploy') {
        $selected_folder = $_POST['project_folder'] ?? '';
        $port            = intval($_POST['server_port'] ?? 8000);
        $php_ver         = $_POST['php_version'] ?? '';
        $target_path     = $htdocs_path . '/' . $selected_folder . '/public';

        if ($selected_folder === '' || !in_array($selected_folder, $projects, true)) {
            $response = ["status" => "error", "message" => "Please select a valid app folder."];
        } elseif ($port < 1024 || $port > 65535) {
            $response = ["status" => "error", "message" => "Port must be between 1024 and 65535."];
        } elseif (!is_dir($target_path)) {
            $response = ["status" => "error", "message" => "No <strong>public</strong> folder found inside <strong>" . htmlspecialchars($selected_folder) . "</strong>."];
        } else {
            $existing_vhosts = file_exists($vhost_file) ? file_get_contents($vhost_file) : "";

            if (preg_match('/^\s*Listen\s+' . $port . '\s*$/mi', $existing_vhosts) || checkServicePort($port)) {
                $response = ["status" => "error", "message" => "Port <strong>{$port}</strong> is already in use. Pick another port or stop the app using it."];
            } else {
                // Per-version PHP only works when mod_fcgid is loaded; otherwise use XAMPP's default PHP.
                $use_fcgid = $fcgid_enabled && in_array($php_ver, $php_versions, true);
                $tp  = str_replace('\\', '/', $target_path);
                $php = str_replace('\\', '/', "{$xampp_root}/{$php_ver}");

                $tag = $use_fcgid ? "# LARAVEL:{$selected_folder}:{$port}:{$php_ver}" : "# LARAVEL:{$selected_folder}:{$port}";

                $vhost_config  = "\n\n{$tag}\n";
                $vhost_config .= "Listen {$port}\n";
                $vhost_config .= "<VirtualHost {$server_ip}:{$port}>\n";
                $vhost_config .= "    DocumentRoot \"{$tp}\"\n";
                $vhost_config .= "    ServerName {$server_ip}:{$port}\n";
                if ($use_fcgid) {
                    $vhost_config .= "    FcgidInitialEnv PHPRC \"{$php}\"\n";
                }
                $vhost_config .= "    <Directory \"{$tp}\">\n";
                $vhost_config .= $use_fcgid
                    ? "        Options Indexes FollowSymLinks ExecCGI\n"
                    : "        Options Indexes FollowSymLinks\n";
                $vhost_config .= "        AllowOverride All\n";
                $vhost_config .= "        Require all granted\n";
                if ($use_fcgid) {
                    $vhost_config .= "        AddHandler fcgid-script .php\n";
                    $vhost_config .= "        FcgidWrapper \"{$php}/php-cgi.exe\" .php\n";
                }
                $vhost_config .= "    </Directory>\n";
                $vhost_config .= "</VirtualHost>\n";

                // Backup, write, validate, rollback on failure
                $had_file = file_exists($vhost_file);
                if ($had_file) @copy($vhost_file, $vhost_file . '.bak');

                if (file_put_contents($vhost_file, $vhost_config, FILE_APPEND) === false) {
                    $response = ["status" => "error", "message" => "Failed to write virtual host configuration."];
                } else {
                    $url  = "http://{$server_ip}:{$port}";
                    $note = ($php_ver !== '' && !$use_fcgid) ? "<br><br><small>mod_fcgid is not loaded, so this app runs on XAMPP's default PHP.</small>" : "";
                    $response = ["status" => "success", "message" => "App <strong>" . htmlspecialchars($selected_folder) . "</strong> configured on port <strong>{$port}</strong>.<br><br><strong>Restart Apache</strong> in the XAMPP Control Panel to apply it, then open:<br><a href='{$url}' target='_blank' style='color:var(--text); font-weight:700;'>{$url}</a>{$note}"];
                }
            }
        }
    }

    if ($action === 'delete') {
        $del_folder = $_POST['del_folder'] ?? '';
        $del_port   = intval($_POST['del_port'] ?? 0);
        if ($del_folder !== '' && file_exists($vhost_file)) {
            $content = file_get_contents($vhost_file);
            $pattern = '/\s*# LARAVEL:' . preg_quote($del_folder, '/') . ':' . $del_port . '(?::[^\r\n]*)?\r?\n.*?<\/VirtualHost>\s*/s';
            $new_content = preg_replace($pattern, "\n", $content, 1);
            if ($new_content !== $content) {
                @copy($vhost_file, $vhost_file . '.bak');
                file_put_contents($vhost_file, $new_content);
                $response = ["status" => "success", "message" => "App configuration removed. <strong>Restart Apache</strong> in the XAMPP Control Panel to apply."];
            } else {
                $response = ["status" => "error", "message" => "Could not find the configuration for this app."];
            }
        }
    }

    if ($action === 'heal_mysql') {
        $response = ["status" => "success", "message" => autoHealMySQL($mysql_path)];
    }
}

$deployed_list = [];
if (file_exists($vhost_file)) {
    $content = file_get_contents($vhost_file);
    preg_match_all('/# LARAVEL:(.*?):(\d+):?(.*?)\r?\n/', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $deployed_list[] = ['folder' => $m[1], 'port' => $m[2], 'php' => !empty($m[3]) ? $m[3] : 'default', 'url' => "http://{$server_ip}:{$m[2]}"];
    }
}

$phpmyadmin_url = "http://{$server_ip}/phpmyadmin";
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XAMPP App Launcher & Control Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0d1117; --surface: #161b22; --surface-hover: #1f242c;
            --border: #30363d; --text: #f0f6fc; --text-sub: #8b949e;
            --success: #3fb950; --danger: #f85149; --radius: 10px;
        }
        [data-theme="light"] {
            --bg: #f8f9fa; --surface: #ffffff; --surface-hover: #f1f3f5;
            --border: #dee2e6; --text: #212529; --text-sub: #6c757d;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }

        /* LAUNCHER LOADER SCREEN */
        #launcherLoader {
            position: fixed; inset: 0; background: var(--bg); z-index: 9999;
            display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 16px;
            transition: opacity 0.4s ease;
        }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--border); border-top-color: var(--text); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .workspace { width: 100%; max-width: 1200px; display: flex; flex-direction: column; gap: 20px; opacity: 0; transition: opacity 0.5s ease; }
        .workspace.visible { opacity: 1; }

        .topbar { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 16px; gap: 12px; flex-wrap: wrap; }
        .brand-title { font-size: 18px; font-weight: 700; }
        .brand-sub { font-size: 12px; color: var(--text-sub); }

        .status-indicators { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .indicator-pill { display: flex; align-items: center; gap: 6px; font-size: 12px; background: var(--surface); border: 1px solid var(--border); padding: 5px 12px; border-radius: 20px; }
        .host-pill { font-family: 'JetBrains Mono', monospace; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--danger); }
        .dot.active { background: var(--success); }

        .btn-emergency {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600; font-family: inherit;
            padding: 5px 14px; border-radius: 20px; cursor: pointer;
            background: rgba(248,81,73,0.1); color: var(--danger);
            border: 1px solid var(--danger); transition: 0.15s;
        }
        .btn-emergency:hover { background: var(--danger); color: #fff; }

        .content-grid { display: grid; grid-template-columns: 380px 1fr; gap: 20px; }
        @media(max-width:900px) { .content-grid { grid-template-columns: 1fr; } }

        .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; display: flex; flex-direction: column; justify-content: space-between; }
        .panel-header { font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--text-sub); margin-bottom: 16px; letter-spacing: 0.05em; }

        .field { margin-bottom: 14px; }
        label { display: block; font-size: 11px; font-weight: 500; color: var(--text-sub); margin-bottom: 6px; }
        select, input { width: 100%; padding: 10px 12px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--bg); color: var(--text); font-size: 13px; outline: none; }

        .btn { width: 100%; padding: 11px; border-radius: var(--radius); font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid var(--border); transition: 0.15s; text-align: center; text-decoration: none; display: inline-flex; justify-content: center; align-items: center; font-family: inherit; }
        .btn-solid { background: var(--text); color: var(--bg); border-color: var(--text); }
        .btn-subtle { background: var(--bg); color: var(--text); }
        .btn-subtle:hover { background: var(--surface-hover); }
        .btn-danger { background: rgba(248,81,73,0.1); color: var(--danger); border-color: var(--danger); }

        .apps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; max-height: 400px; overflow-y: auto; }
        .app-card { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 12px; }
        .app-card:hover { border-color: var(--text-sub); }
        .app-name { font-weight: 600; font-size: 14px; word-break: break-all; }
        .app-meta { font-size: 11px; color: var(--text-sub); font-family: 'JetBrains Mono', monospace; }

        .overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(2px); display: flex; justify-content: center; align-items: center; opacity: 0; pointer-events: none; transition: 0.2s; z-index: 1000; }
        .overlay.active { opacity: 1; pointer-events: auto; }
        .modal { background: var(--surface); border: 1px solid var(--border); width: 90%; max-width: 420px; padding: 24px; border-radius: var(--radius); }
        .modal-title { font-size: 14px; font-weight: 700; margin-bottom: 8px; }
        .modal-text { font-size: 13px; color: var(--text-sub); margin-bottom: 20px; line-height: 1.5; max-height: 40vh; overflow-y: auto; }
        .modal-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        /* SEARCHABLE APP SELECT */
        .combo { position: relative; }
        .combo-list { position: absolute; left: 0; right: 0; top: calc(100% + 4px); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); max-height: 220px; overflow-y: auto; z-index: 50; display: none; box-shadow: 0 8px 24px rgba(0,0,0,0.35); }
        .combo-list.open { display: block; }
        .combo-item { padding: 10px 12px; font-size: 13px; cursor: pointer; word-break: break-all; }
        .combo-item:hover { background: var(--surface-hover); }
        .combo-empty { display: none; padding: 12px; font-size: 12px; color: var(--text-sub); text-align: center; }
        #projectSearch.picked { font-weight: 600; }

        /* PHP SPECS MODAL */
        .modal-wide { max-width: 640px; }
        .modal-wide .modal-text { max-height: 60vh; }
        .spec-row { margin-bottom: 4px; }
        .spec-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text); margin: 14px 0 8px; }
        .chk-wrap, .ext-list { display: flex; flex-wrap: wrap; gap: 6px; }
        .chk, .ext-chip { font-family: 'JetBrains Mono', monospace; font-size: 11px; padding: 3px 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface); }
        .chk.ok { color: var(--success); border-color: var(--success); }
        .chk.no { color: var(--danger); border-color: var(--danger); }
        .ext-chip.hl { color: var(--success); border-color: var(--success); }
        .ext-list { max-height: 200px; overflow-y: auto; padding: 8px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); }
        .ext-search { margin-bottom: 8px; }
        .warn { margin-top: 10px; padding: 10px; border-radius: 8px; font-size: 12px; border: 1px solid var(--danger); color: var(--danger); background: rgba(248,81,73,0.08); }

        .hint { font-size: 11px; color: var(--text-sub); margin-top: 6px; line-height: 1.4; }

        /* RESPONSIVE */
        .content-grid > * { min-width: 0; }
        @media(max-width:900px) { body { align-items: flex-start; } }
        @media(max-width:600px) {
            body { padding: 12px; }
            .panel { padding: 16px; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .apps-grid { grid-template-columns: 1fr; max-height: none; }
            .modal { padding: 18px; }
            .modal-actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- LAUNCHER SPLASH SCREEN -->
<div id="launcherLoader">
    <div class="spinner"></div>
    <div style="font-weight: 600; font-size: 14px;" id="loaderText">Initializing XAMPP Services...</div>
</div>

<div class="workspace" id="appWorkspace">
    <header class="topbar">
        <div>
            <div class="brand-title">XAMPP App Launcher Hub</div>
            <div class="brand-sub">Local Environment Controller</div>
        </div>
        <div class="status-indicators">
            <div class="indicator-pill host-pill">HOST: <?php echo htmlspecialchars($server_ip); ?></div>
            <button type="button" class="btn-emergency" onclick="confirmEmergency()">&#9888; Emergency</button>
            <div class="indicator-pill"><div class="dot" id="apacheDot"></div>Apache</div>
            <div class="indicator-pill"><div class="dot" id="mysqlDot"></div>MySQL</div>
        </div>
    </header>

    <div class="content-grid">
        <!-- Launcher Controls -->
        <div class="panel">
            <div>
                <div class="panel-header">App Deployer Control</div>
                <form method="POST" id="deployForm">
                    <input type="hidden" name="action" value="deploy">
                    <div class="field">
                        <label>Select Project Folder</label>
                        <div class="combo" id="projectCombo">
                            <input type="text" id="projectSearch" placeholder="-- Choose / search local app --" autocomplete="off">
                            <input type="hidden" name="project_folder" id="projectSelect">
                            <div class="combo-list" id="projectList">
                                <?php foreach ($projects as $p): ?>
                                    <div class="combo-item" data-value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></div>
                                <?php endforeach; ?>
                                <div class="combo-empty" id="projectEmpty">No matching app found</div>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label>PHP Engine Version</label>
                        <select name="php_version">
                            <?php foreach ($php_versions as $ver): ?>
                                <option value="<?php echo htmlspecialchars($ver); ?>"><?php echo strtoupper($ver); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint"><?php echo $fcgid_enabled
                            ? 'mod_fcgid active: each app runs on the selected PHP version.'
                            : 'mod_fcgid not loaded: apps run on XAMPP\'s default PHP (version choice is ignored). PHP Specs still works.'; ?></div>
                    </div>
                    <div class="field">
                        <label>Local Port</label>
                        <input type="number" name="server_port" value="8000">
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:16px;">
                        <button type="button" class="btn btn-subtle" onclick="inspectPHP()">PHP Specs</button>
                        <button type="button" class="btn btn-solid" onclick="confirmDeploy()">Launch App</button>
                    </div>
                </form>
            </div>
            <a href="<?php echo htmlspecialchars($phpmyadmin_url); ?>" target="_blank" rel="noopener" class="btn btn-subtle" style="margin-top:15px;">Launch phpMyAdmin</a>
        </div>

        <!-- Apps Grid Launcher Window -->
        <div class="panel" style="justify-content: flex-start;">
            <div class="panel-header" style="display:flex; justify-content:space-between;">
                <span>Active Launched Apps</span>
                <span><?php echo count($deployed_list); ?> Online</span>
            </div>

            <?php if (empty($deployed_list)): ?>
                <div style="text-align: center; padding: 50px 0; color: var(--text-sub); font-size: 13px;">
                    No apps active. Use the left panel to launch a project app.
                </div>
            <?php else: ?>
                <div class="apps-grid">
                    <?php foreach ($deployed_list as $app): ?>
                        <div class="app-card">
                            <div>
                                <div class="app-name"><?php echo htmlspecialchars($app['folder']); ?></div>
                                <div class="app-meta">Port: <?php echo $app['port']; ?> | PHP: <?php echo $app['php']; ?></div>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <a href="<?php echo $app['url']; ?>" target="_blank" class="btn btn-solid" style="padding: 6px;">Open</a>
                                <button class="btn btn-danger" style="padding: 6px;" onclick="confirmDelete('<?php echo $app['folder']; ?>', '<?php echo $app['port']; ?>')">Stop</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="del_folder" id="delFolder">
    <input type="hidden" name="del_port" id="delPort">
</form>

<form id="healForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="heal_mysql">
</form>

<!-- Modal Overlay -->
<div class="overlay <?php echo !empty($response['status']) ? 'active' : ''; ?>" id="modalOverlay">
    <div class="modal" id="modalBox">
        <div class="modal-title" id="modalTitle">System Message</div>
        <div class="modal-text" id="modalText"><?php echo $response['message'] ?? ''; ?></div>
        <div id="modalButtons">
            <button class="btn btn-solid" style="width:100%;" onclick="closeModal()">Dismiss</button>
        </div>
    </div>
</div>

<script>
    // LAUNCHER BOOT SEQUENCE CHECK
    window.addEventListener('DOMContentLoaded', () => {
        const loaderText = document.getElementById('loaderText');
        loaderText.textContent = "Checking XAMPP Apache & MySQL...";

        fetch(window.location.pathname + '?start_xampp_services=1')
            .then(res => res.json())
            .then(data => {
                // Update indicator dots
                if(data.apache) document.getElementById('apacheDot').classList.add('active');
                if(data.mysql) document.getElementById('mysqlDot').classList.add('active');

                loaderText.textContent = "XAMPP Online. Starting Launcher UI...";
                setTimeout(() => {
                    document.getElementById('launcherLoader').style.opacity = '0';
                    setTimeout(() => {
                        document.getElementById('launcherLoader').style.display = 'none';
                        document.getElementById('appWorkspace').classList.add('visible');
                    }, 400);
                }, 600);
            })
            .catch(() => {
                loaderText.textContent = "Proceeding to UI Launcher...";
                setTimeout(() => {
                    document.getElementById('launcherLoader').style.display = 'none';
                    document.getElementById('appWorkspace').classList.add('visible');
                }, 500);
            });
    });

    function closeModal() {
        document.getElementById('modalOverlay').classList.remove('active');
        if (window.history.replaceState) window.history.replaceState(null, null, window.location.pathname);
    }

    function showModal(title, text, buttonsHtml, wide) {
        document.getElementById('modalBox').classList.toggle('modal-wide', !!wide);
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalText').innerHTML = text;
        document.getElementById('modalButtons').innerHTML = buttonsHtml;
        document.getElementById('modalOverlay').classList.add('active');
    }

    function confirmDeploy() {
        const folder = document.getElementById('projectSelect').value;
        if (!folder) {
            showModal("Error", "Please select an app folder first.", '<button class="btn btn-solid" style="width:100%;" onclick="closeModal()">Dismiss</button>');
            return;
        }
        showModal("Launch App", `Deploy and run <strong>${folder}</strong>?`, `
            <div class="modal-actions">
                <button class="btn btn-subtle" onclick="closeModal()">Cancel</button>
                <button class="btn btn-solid" onclick="document.getElementById('deployForm').submit()">Proceed</button>
            </div>
        `);
    }

    function confirmDelete(folder, port) {
        document.getElementById('delFolder').value = folder;
        document.getElementById('delPort').value = port;
        showModal("Stop App", `Remove configuration for <strong>${folder}</strong>?`, `
            <div class="modal-actions">
                <button class="btn btn-subtle" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" onclick="document.getElementById('deleteForm').submit()">Stop</button>
            </div>
        `);
    }

    // EMERGENCY MODAL -> contains the MySQL Auto-Heal Recovery action
    function confirmEmergency() {
        showModal("Emergency Tools", `
            <strong style="color:var(--danger);">Run MySQL Auto-Heal Recovery</strong><br><br>
            Gagamitin lang ito kapag hindi na gumagana ang MySQL. Ire-rename nito ang current <code>mysql/data</code> folder papuntang <code>data_old</code>, tapos ibabalik ang laman ng <code>mysql/backup</code>.
        `, `
            <div class="modal-actions">
                <button class="btn btn-subtle" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" onclick="document.getElementById('healForm').submit()">Run MySQL Auto-Heal Recovery</button>
            </div>
        `);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function filterExts(q) {
        q = q.trim().toLowerCase();
        let shown = 0;
        document.querySelectorAll('#extList .ext-chip').forEach(el => {
            const match = el.dataset.n.includes(q);
            el.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        document.getElementById('extCount').textContent = shown;
    }

    function inspectPHP() {
        const ver = document.querySelector('select[name="php_version"]').value;
        const dismissBtn = '<button class="btn btn-solid" style="width:100%;" onclick="closeModal()">Dismiss</button>';
        showModal("Inspecting", "Analyzing PHP runtime specs...", dismissBtn);

        fetch(window.location.pathname + '?inspect_php=' + encodeURIComponent(ver))
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    showModal("Error", escapeHtml(data.message), dismissBtn);
                    return;
                }

                const c = data.db_checks || {};
                const badges = Object.keys(c).map(k =>
                    `<span class="chk ${c[k] ? 'ok' : 'no'}">${c[k] ? '&#10003;' : '&#10007;'} ${escapeHtml(k)}</span>`
                ).join('');

                const chips = data.extensions.map(e => {
                    const n = e.toLowerCase();
                    const hl = (n.includes('sqlsrv') || n === 'pdo' || n.startsWith('pdo_')) ? ' hl' : '';
                    return `<span class="ext-chip${hl}" data-n="${escapeHtml(n)}">${escapeHtml(e)}</span>`;
                }).join('');

                const mssqlMissing = !(c.sqlsrv && c.pdo_sqlsrv);
                const warn = mssqlMissing
                    ? `<div class="warn"><strong>sqlsrv / pdo_sqlsrv</strong> is not loaded on this PHP. Put <code>php_sqlsrv_XX_ts.dll</code> and <code>php_pdo_sqlsrv_XX_ts.dll</code> in this PHP's <code>ext</code> folder, enable both in its <code>php.ini</code>, and install the Microsoft ODBC Driver for SQL Server.</div>`
                    : '';

                const total = data.extensions.length;
                const html = `
                    <div class="spec-row"><strong>PHP Version:</strong> ${escapeHtml(data.php_version)}</div>
                    <div class="spec-row"><strong>Architecture:</strong> ${escapeHtml(data.bits)}</div>
                    <div class="spec-row"><strong>Thread Safety:</strong> ${escapeHtml(data.thread_safe)}</div>

                    <div class="spec-title">SQL Server / Database Drivers</div>
                    <div class="chk-wrap">${badges}</div>
                    <div class="spec-row" style="margin-top:8px;"><strong>PDO Drivers:</strong> ${data.pdo_drivers.length ? data.pdo_drivers.map(escapeHtml).join(', ') : 'none'}</div>
                    ${warn}

                    <div class="spec-title">Extensions (<span id="extCount">${total}</span>/${total})</div>
                    <input type="text" class="ext-search" placeholder="Filter extensions (e.g. sqlsrv, pdo)..." oninput="filterExts(this.value)">
                    <div class="ext-list" id="extList">${chips}</div>
                `;
                showModal("PHP: " + ver.toUpperCase(), html, dismissBtn, true);
            })
            .catch(() => showModal("Error", "Failed to inspect PHP.", dismissBtn));
    }

    // SEARCHABLE PROJECT SELECT
    (function () {
        const input  = document.getElementById('projectSearch');
        const hidden = document.getElementById('projectSelect');
        const list   = document.getElementById('projectList');
        const empty  = document.getElementById('projectEmpty');
        const items  = Array.from(list.querySelectorAll('.combo-item'));

        function filter() {
            const q = input.value.trim().toLowerCase();
            let n = 0;
            items.forEach(li => {
                const show = li.dataset.value.toLowerCase().includes(q);
                li.style.display = show ? '' : 'none';
                if (show) n++;
            });
            empty.style.display = n ? 'none' : 'block';
        }
        function openList()  { list.classList.add('open'); }
        function closeList() { list.classList.remove('open'); }
        function pick(v) {
            hidden.value = v;
            input.value = v;
            input.classList.add('picked');
            closeList();
        }

        input.addEventListener('focus', () => { input.select(); filter(); openList(); });
        input.addEventListener('input', () => {
            hidden.value = '';
            input.classList.remove('picked');
            filter();
            openList();
        });
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = items.find(li => li.style.display !== 'none');
                if (first) pick(first.dataset.value);
            }
            if (e.key === 'Escape') closeList();
        });
        input.addEventListener('blur', () => {
            const exact = items.find(li => li.dataset.value.toLowerCase() === input.value.trim().toLowerCase());
            if (exact) pick(exact.dataset.value);
            closeList();
        });
        // mousedown so the pick happens before the input loses focus
        items.forEach(li => li.addEventListener('mousedown', e => {
            e.preventDefault();
            pick(li.dataset.value);
        }));
    })();
</script>

</body>
</html>