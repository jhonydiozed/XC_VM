<?php

declare(strict_types=1);

session_name('XDSSESSID');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
]);

const XDS_ROOT = __DIR__ . '/..';
const XDS_LOG = XDS_ROOT . '/storage/logs/xds-control.log';

function xdsLog(string $level, string $message, array $context = []): void
{
    $dir = dirname(XDS_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = json_encode([
        'time' => gmdate('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(6)),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents(XDS_LOG, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

set_exception_handler(function (Throwable $e): void {
    xdsLog('error', $e->getMessage(), ['exception' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    http_response_code(500);
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/health')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'internal_error']);
        return;
    }
    echo '<h1>XDS Control</h1><p>Erro interno registrado. Consulte <code>' . htmlspecialchars(XDS_LOG) . '</code>.</p>';
});

$configFile = XDS_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Configuração ausente: ' . $configFile);
}
$config = require $configFile;
$db = $config['database'] ?? [];

function pdoFor(array $db, string $database): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $database, $db['charset'] ?? 'utf8mb4');
    return new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

$panelDb = pdoFor($db, $db['panel_database']);
$engineDb = pdoFor($db, $db['engine_database']);

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        throw new RuntimeException('CSRF inválido');
    }
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void
{
    if (!currentUser()) {
        header('Location: /login');
        exit;
    }
}

function audit(PDO $db, string $action, ?string $entityType = null, ?string $entityId = null, array $metadata = []): void
{
    $stmt = $db->prepare('INSERT INTO audit_logs (admin_user_id, action, entity_type, entity_id, ip_address, metadata_json) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        currentUser()['id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render(string $title, string $body): void
{
    $user = currentUser();
    $nav = $user ? '<nav><a href="/">Dashboard</a><a href="/browse?table=servers">Servidores</a><a href="/browse?table=streams">Streams</a><a href="/browse?table=users">Usuários</a><a href="/browse?table=bouquets">Bouquets</a><a href="/audit">Auditoria</a><a href="/diagnostics">Diagnóstico</a><a href="/logout">Sair</a></nav>' : '';
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' · XDS Control</title><style>
    :root{color-scheme:dark;--bg:#090d18;--panel:#12192b;--line:#26314d;--text:#edf3ff;--muted:#91a0bd;--accent:#66d9ff;--good:#60e39b;--bad:#ff7285}*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#090d18,#101a31);color:var(--text);font:14px system-ui,Segoe UI,sans-serif}header{display:flex;align-items:center;justify-content:space-between;padding:18px 28px;border-bottom:1px solid var(--line);background:#0c1222dd;position:sticky;top:0}header strong{font-size:20px;color:var(--accent)}nav{display:flex;gap:14px;flex-wrap:wrap}a{color:var(--accent);text-decoration:none}main{max-width:1500px;margin:auto;padding:26px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px}.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:0 8px 28px #0005}.metric{font-size:30px;font-weight:800;margin-top:8px}.muted{color:var(--muted)}table{width:100%;border-collapse:collapse;background:var(--panel);border-radius:12px;overflow:hidden}th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap;max-width:380px;overflow:hidden;text-overflow:ellipsis}th{color:var(--accent);position:sticky;top:73px;background:#12192b}input,button{width:100%;padding:12px;border-radius:9px;border:1px solid var(--line);background:#0c1222;color:var(--text);margin:6px 0}button{background:#1c91b8;font-weight:700;cursor:pointer}.alert{padding:12px;border:1px solid var(--bad);border-radius:8px;color:#ffd8de}.ok{color:var(--good)}.bad{color:var(--bad)}code{color:#b9eaff}.scroll{overflow:auto;max-height:72vh}</style></head><body><header><strong>XDS CONTROL</strong>' . $nav . '</header><main>' . $body . '</main></body></html>';
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    header('Content-Type: application/json');
    $result = ['ok' => true, 'time' => gmdate('c'), 'checks' => []];
    foreach (['panel' => $panelDb, 'engine' => $engineDb] as $name => $pdo) {
        try {
            $result['checks'][$name] = ['ok' => (bool)$pdo->query('SELECT 1')->fetchColumn()];
        } catch (Throwable $e) {
            $result['ok'] = false;
            $result['checks'][$name] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    http_response_code($result['ok'] ? 200 : 503);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $stmt = $panelDb->prepare('SELECT id, username, password_hash, display_name FROM admin_users WHERE username = ? AND enabled = 1 LIMIT 1');
        $stmt->execute([trim($_POST['username'] ?? '')]);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'display_name' => $user['display_name']];
            $panelDb->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
            audit($panelDb, 'login_success', 'admin_user', (string)$user['id']);
            xdsLog('info', 'Login realizado', ['user' => $user['username']]);
            header('Location: /');
            exit;
        }
        xdsLog('warning', 'Falha de login', ['username' => $_POST['username'] ?? '']);
        $error = '<div class="alert">Usuário ou senha inválidos.</div>';
    }
    render('Login', '<div class="card" style="max-width:420px;margin:10vh auto"><h1>Entrar no XDS Control</h1>' . ($error ?? '') . '<form method="post"><input type="hidden" name="csrf" value="' . e(csrf()) . '"><label>Usuário</label><input name="username" required autofocus><label>Senha</label><input type="password" name="password" required><button>Entrar</button></form></div>');
    exit;
}

if ($path === '/logout') {
    if (currentUser()) audit($panelDb, 'logout');
    $_SESSION = [];
    session_destroy();
    header('Location: /login');
    exit;
}

requireLogin();

$allowedTables = ['servers','streams','users','bouquets','stream_categories','lines','lines_activity','lines_live','panel_logs','login_logs','settings','queue','signals','providers'];

if ($path === '/browse') {
    $table = $_GET['table'] ?? 'servers';
    if (!in_array($table, $allowedTables, true)) {
        http_response_code(404);
        render('Não encontrado', '<div class="alert">Tabela não autorizada.</div>');
        exit;
    }
    $limit = min(250, max(1, (int)($_GET['limit'] ?? 100)));
    $rows = $engineDb->query('SELECT * FROM `' . $table . '` LIMIT ' . $limit)->fetchAll();
    $columns = $rows ? array_keys($rows[0]) : [];
    $html = '<h1>' . e($table) . '</h1><p class="muted">Modo somente leitura · limite ' . $limit . '</p><div class="scroll"><table><thead><tr>';
    foreach ($columns as $column) $html .= '<th>' . e($column) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column];
            if (is_string($value) && strlen($value) > 240) $value = substr($value, 0, 240) . '…';
            $html .= '<td title="' . e($value) . '">' . e($value) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    audit($panelDb, 'browse_engine_table', 'table', $table, ['limit' => $limit]);
    render($table, $html);
    exit;
}

if ($path === '/audit') {
    $rows = $panelDb->query('SELECT id, admin_user_id, action, entity_type, entity_id, ip_address, created_at FROM audit_logs ORDER BY id DESC LIMIT 300')->fetchAll();
    $html = '<h1>Auditoria</h1><div class="scroll"><table><tr><th>ID</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>ID entidade</th><th>IP</th><th>Data</th></tr>';
    foreach ($rows as $row) $html .= '<tr><td>'.e($row['id']).'</td><td>'.e($row['admin_user_id']).'</td><td>'.e($row['action']).'</td><td>'.e($row['entity_type']).'</td><td>'.e($row['entity_id']).'</td><td>'.e($row['ip_address']).'</td><td>'.e($row['created_at']).'</td></tr>';
    render('Auditoria', $html . '</table></div>');
    exit;
}

if ($path === '/diagnostics') {
    $checks = [];
    $checks['PHP'] = PHP_VERSION;
    $checks['Engine DB'] = $engineDb->query('SELECT VERSION()')->fetchColumn();
    $checks['Engine tables'] = $engineDb->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $checks['Panel DB'] = $panelDb->query('SELECT DATABASE()')->fetchColumn();
    $checks['Log writable'] = is_writable(dirname(XDS_LOG)) ? 'yes' : 'no';
    $checks['Disk free'] = round(disk_free_space('/') / 1073741824, 2) . ' GiB';
    $html = '<h1>Diagnóstico</h1><div class="grid">';
    foreach ($checks as $name => $value) $html .= '<div class="card"><div class="muted">'.e($name).'</div><div class="metric" style="font-size:18px">'.e($value).'</div></div>';
    $html .= '</div><p><a href="/health">Abrir health JSON</a></p><p><code>' . e(XDS_LOG) . '</code></p>';
    render('Diagnóstico', $html);
    exit;
}

$metrics = [];
foreach (['users','streams','servers','bouquets','lines_activity','lines_live'] as $table) {
    try { $metrics[$table] = (int)$engineDb->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(); }
    catch (Throwable $e) { $metrics[$table] = null; }
}
$html = '<h1>Dashboard</h1><p class="muted">XC_VM como engine · XDS Control em modo somente leitura</p><div class="grid">';
foreach ($metrics as $name => $value) $html .= '<a class="card" href="/browse?table=' . e($name) . '"><div class="muted">'.e($name).'</div><div class="metric">'.e($value ?? 'N/A').'</div></a>';
$html .= '</div><div class="card" style="margin-top:18px"><h2>Acesso rápido</h2><p>';
foreach ($allowedTables as $table) $html .= '<a style="margin-right:14px" href="/browse?table=' . e($table) . '">' . e($table) . '</a>';
$html .= '</p></div>';
render('Dashboard', $html);
