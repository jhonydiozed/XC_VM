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
    xdsLog('error', $e->getMessage(), [
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/health')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'internal_error']);
        return;
    }
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>XDS Control</title><body style="font-family:system-ui;padding:40px;background:#111827;color:#fff"><h1>XDS Control</h1><p>Erro interno registrado.</p><code>' . htmlspecialchars(XDS_LOG) . '</code></body></html>';
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

function icon(string $name): string
{
    return '<i class="icon cil-' . e($name) . '"></i>';
}

function navItem(string $href, string $label, string $iconName, string $activePath): string
{
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $active = $currentPath === $activePath || ($activePath === '/browse' && $currentPath === '/browse' && str_contains($_SERVER['REQUEST_URI'] ?? '', $href));
    return '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '" href="' . e($href) . '"><span class="nav-icon">' . icon($iconName) . '</span>' . e($label) . '</a></li>';
}

function renderLogin(string $error = ''): void
{
    echo '<!doctype html><html lang="pt-BR" data-coreui-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar · XDS Control</title><link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.4.1/dist/css/coreui.min.css" rel="stylesheet"><link rel="stylesheet" href="https://unpkg.com/@coreui/icons/css/free.min.css"><link rel="stylesheet" href="/assets/xds-coreui.css"></head><body><div class="xds-login-shell d-flex align-items-center justify-content-center p-3"><div class="xds-login-card p-4 p-md-5 text-white"><div class="d-flex align-items-center gap-3 mb-4"><span class="xds-mark">XDS</span><div><h1 class="h3 mb-0 fw-bold">XDS Control</h1><div class="text-white-50">Administração do engine XC_VM</div></div></div>' . $error . '<form method="post" class="mt-4"><input type="hidden" name="csrf" value="' . e(csrf()) . '"><label class="form-label">Usuário</label><div class="input-group mb-3"><span class="input-group-text"><i class="icon cil-user"></i></span><input class="form-control" name="username" required autofocus autocomplete="username"></div><label class="form-label">Senha</label><div class="input-group mb-4"><span class="input-group-text"><i class="icon cil-lock-locked"></i></span><input class="form-control" type="password" name="password" required autocomplete="current-password"></div><button class="btn btn-primary w-100 py-2 fw-semibold">Entrar</button></form><div class="d-flex align-items-center gap-2 mt-4 small text-white-50"><span class="xds-status-dot"></span> Painel isolado · engine em modo somente leitura</div></div></div><script src="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.4.1/dist/js/coreui.bundle.min.js"></script></body></html>';
}

function render(string $title, string $body, string $subtitle = ''): void
{
    $user = currentUser();
    $name = $user['display_name'] ?: $user['username'];
    $nav = '';
    $nav .= '<div class="xds-section-label">Visão geral</div>';
    $nav .= navItem('/', 'Dashboard', 'speedometer', '/');
    $nav .= navItem('/audit', 'Atividade e auditoria', 'history', '/audit');
    $nav .= '<div class="xds-section-label">Clientes</div>';
    $nav .= navItem('/browse?table=users', 'Usuários', 'people', '/browse');
    $nav .= navItem('/browse?table=lines', 'Linhas', 'link', '/browse');
    $nav .= navItem('/browse?table=lines_live', 'Conexões ativas', 'rss', '/browse');
    $nav .= '<div class="xds-section-label">Conteúdo</div>';
    $nav .= navItem('/browse?table=streams', 'Streams', 'media-play', '/browse');
    $nav .= navItem('/browse?table=stream_categories', 'Categorias', 'folder', '/browse');
    $nav .= navItem('/browse?table=bouquets', 'Bouquets', 'layers', '/browse');
    $nav .= '<div class="xds-section-label">Infraestrutura</div>';
    $nav .= navItem('/browse?table=servers', 'Servidores', 'server', '/browse');
    $nav .= navItem('/browse?table=providers', 'Provedores', 'cloud', '/browse');
    $nav .= navItem('/diagnostics', 'Diagnóstico', 'pulse', '/diagnostics');
    $nav .= '<div class="xds-section-label">Sistema</div>';
    $nav .= navItem('/browse?table=settings', 'Configurações', 'settings', '/browse');

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' · XDS Control</title><link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.4.1/dist/css/coreui.min.css" rel="stylesheet"><link rel="stylesheet" href="https://unpkg.com/@coreui/icons/css/free.min.css"><link rel="stylesheet" href="/assets/xds-coreui.css"></head><body><div class="sidebar sidebar-dark sidebar-fixed border-end xds-sidebar" id="sidebar"><div class="sidebar-header border-bottom"><div class="sidebar-brand xds-brand"><span class="xds-mark">XDS</span><span>CONTROL</span></div><button class="btn-close d-lg-none" type="button" data-coreui-dismiss="offcanvas" data-coreui-theme="dark"></button></div><ul class="sidebar-nav" data-coreui="navigation" data-simplebar>' . $nav . '</ul><div class="sidebar-footer border-top d-none d-lg-flex"><button class="sidebar-toggler" type="button" data-coreui-toggle="unfoldable"></button></div></div><div class="wrapper d-flex flex-column min-vh-100 xds-wrapper"><header class="header header-sticky p-0 mb-0 xds-header"><div class="container-fluid px-4"><button class="header-toggler" type="button" onclick="coreui.Sidebar.getOrCreateInstance(document.querySelector(\'#sidebar\')).toggle()"><i class="icon icon-lg cil-menu"></i></button><ul class="header-nav ms-auto"><li class="nav-item d-none d-md-flex align-items-center px-2"><span class="xds-status-dot me-2"></span><span class="small text-body-secondary">XC_VM conectado</span></li><li class="nav-item dropdown"><a class="nav-link py-0 px-2" data-coreui-toggle="dropdown" href="#" role="button"><div class="avatar avatar-md bg-primary text-white d-grid" style="place-items:center">' . e(strtoupper(substr($name, 0, 1))) . '</div></a><div class="dropdown-menu dropdown-menu-end pt-0"><div class="dropdown-header bg-body-tertiary fw-semibold py-2">' . e($name) . '</div><a class="dropdown-item" href="/diagnostics">' . icon('pulse') . ' Diagnóstico</a><a class="dropdown-item" href="/logout">' . icon('account-logout') . ' Sair</a></div></li></ul></div><div class="header-divider"></div><div class="container-fluid px-4 py-2"><nav aria-label="breadcrumb"><ol class="breadcrumb my-0"><li class="breadcrumb-item"><a href="/">XDS Control</a></li><li class="breadcrumb-item active">' . e($title) . '</li></ol></nav></div></header><main class="body flex-grow-1"><div class="container-fluid xds-content"><div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4"><div><h1 class="xds-page-title h3 mb-1">' . e($title) . '</h1>' . ($subtitle !== '' ? '<div class="xds-subtitle">' . e($subtitle) . '</div>' : '') . '</div><button class="btn btn-outline-secondary btn-sm" id="themeToggle"><i class="icon cil-contrast"></i> Tema</button></div>' . $body . '</div></main><footer class="footer px-4"><div>XDS Control <span class="text-body-secondary">· engine XC_VM preservado</span></div><div class="ms-auto">Modo leitura</div></footer></div><script src="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.4.1/dist/js/coreui.bundle.min.js"></script><script>(function(){const root=document.documentElement;const saved=localStorage.getItem("xds-theme");if(saved)root.setAttribute("data-coreui-theme",saved);document.getElementById("themeToggle")?.addEventListener("click",()=>{const next=root.getAttribute("data-coreui-theme")==="dark"?"light":"dark";root.setAttribute("data-coreui-theme",next);localStorage.setItem("xds-theme",next);});})();</script></body></html>';
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
        $error = '<div class="alert alert-danger">Usuário ou senha inválidos.</div>';
    }
    renderLogin($error ?? '');
    exit;
}

if ($path === '/logout') {
    if (currentUser()) {
        audit($panelDb, 'logout');
    }
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
        render('Não encontrado', '<div class="alert alert-danger">Tabela não autorizada.</div>');
        exit;
    }
    $limit = min(250, max(1, (int)($_GET['limit'] ?? 100)));
    $rows = $engineDb->query('SELECT * FROM `' . $table . '` LIMIT ' . $limit)->fetchAll();
    $columns = $rows ? array_keys($rows[0]) : [];
    $html = '<div class="card xds-card"><div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><div><strong>' . e($table) . '</strong><div class="small text-body-secondary">Consulta somente leitura</div></div><form class="d-flex gap-2" method="get"><input type="hidden" name="table" value="' . e($table) . '"><select class="form-select form-select-sm" name="limit"><option>50</option><option selected>100</option><option>250</option></select><button class="btn btn-primary btn-sm">Aplicar</button></form></div><div class="card-body p-0"><div class="xds-table-wrap"><table class="table table-hover align-middle mb-0 xds-table"><thead><tr>';
    foreach ($columns as $column) {
        $html .= '<th>' . e($column) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column];
            if (is_string($value) && strlen($value) > 240) {
                $value = substr($value, 0, 240) . '…';
            }
            $html .= '<td title="' . e($value) . '">' . e($value) . '</td>';
        }
        $html .= '</tr>';
    }
    if (!$rows) {
        $html .= '<tr><td colspan="99" class="text-center py-5 text-body-secondary">Nenhum registro encontrado.</td></tr>';
    }
    $html .= '</tbody></table></div></div></div>';
    audit($panelDb, 'browse_engine_table', 'table', $table, ['limit' => $limit]);
    render(ucwords(str_replace('_', ' ', $table)), $html, 'Tabela do engine XC_VM · limite ' . $limit . ' registros');
    exit;
}

if ($path === '/audit') {
    $rows = $panelDb->query('SELECT id, admin_user_id, action, entity_type, entity_id, ip_address, created_at FROM audit_logs ORDER BY id DESC LIMIT 300')->fetchAll();
    $html = '<div class="card xds-card"><div class="card-header"><strong>Eventos recentes</strong></div><div class="card-body p-0"><div class="xds-table-wrap"><table class="table table-hover align-middle mb-0 xds-table"><thead><tr><th>ID</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>ID entidade</th><th>IP</th><th>Data</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . e($row['id']) . '</td><td>' . e($row['admin_user_id']) . '</td><td><span class="badge bg-info-subtle text-info-emphasis">' . e($row['action']) . '</span></td><td>' . e($row['entity_type']) . '</td><td>' . e($row['entity_id']) . '</td><td>' . e($row['ip_address']) . '</td><td>' . e($row['created_at']) . '</td></tr>';
    }
    $html .= '</tbody></table></div></div></div>';
    render('Auditoria', $html, 'Ações administrativas registradas pelo XDS Control');
    exit;
}

if ($path === '/diagnostics') {
    $checks = [
        'PHP' => PHP_VERSION,
        'Engine DB' => $engineDb->query('SELECT VERSION()')->fetchColumn(),
        'Tabelas do engine' => $engineDb->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn(),
        'Banco do painel' => $panelDb->query('SELECT DATABASE()')->fetchColumn(),
        'Log gravável' => is_writable(dirname(XDS_LOG)) ? 'Sim' : 'Não',
        'Disco livre' => round(disk_free_space('/') / 1073741824, 2) . ' GiB',
    ];
    $html = '<div class="row g-3">';
    foreach ($checks as $name => $value) {
        $html .= '<div class="col-12 col-md-6 col-xl-4"><div class="card xds-card h-100"><div class="card-body"><div class="text-body-secondary small mb-2">' . e($name) . '</div><div class="h5 mb-0">' . e($value) . '</div></div></div></div>';
    }
    $html .= '</div><div class="card xds-card mt-4"><div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3"><div><strong>Health check JSON</strong><div class="text-body-secondary small">Validação direta das duas conexões MariaDB</div></div><a class="btn btn-primary" href="/health" target="_blank">Abrir health</a></div></div>';
    render('Diagnóstico', $html, 'Estado do painel, bancos, PHP e armazenamento');
    exit;
}

$metrics = [];
foreach (['users','streams','servers','bouquets','lines_activity','lines_live'] as $table) {
    try {
        $metrics[$table] = (int)$engineDb->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    } catch (Throwable $e) {
        $metrics[$table] = null;
    }
}

$metricMeta = [
    'users' => ['Usuários', 'people', 'primary'],
    'streams' => ['Streams', 'media-play', 'info'],
    'servers' => ['Servidores', 'server', 'success'],
    'bouquets' => ['Bouquets', 'layers', 'warning'],
    'lines_activity' => ['Atividades', 'graph', 'danger'],
    'lines_live' => ['Conexões ativas', 'rss', 'primary'],
];

$html = '<div class="row g-3 mb-4">';
foreach ($metrics as $name => $value) {
    [$label, $ico, $tone] = $metricMeta[$name];
    $html .= '<div class="col-12 col-sm-6 col-xl-4 col-xxl-2"><a class="card xds-card xds-metric h-100 text-decoration-none text-reset" href="/browse?table=' . e($name) . '"><div class="card-body"><div class="d-flex align-items-start justify-content-between"><div><div class="text-body-secondary small mb-2">' . e($label) . '</div><div class="xds-metric-value">' . e($value ?? 'N/A') . '</div></div><span class="xds-metric-icon text-' . e($tone) . '">' . icon($ico) . '</span></div><div class="small text-body-secondary mt-3">Abrir detalhes <i class="icon cil-arrow-right"></i></div></div></a></div>';
}
$html .= '</div>';

$html .= '<div class="row g-4"><div class="col-12 col-xl-8"><div class="card xds-card h-100"><div class="card-header d-flex align-items-center justify-content-between"><div><strong>Visão operacional</strong><div class="small text-body-secondary">Recursos principais do engine</div></div><span class="badge bg-success-subtle text-success-emphasis"><span class="xds-status-dot me-2"></span>Online</span></div><div class="card-body"><div class="row g-3">';
$quick = [
    ['Linhas', '/browse?table=lines', 'link'],
    ['Conexões', '/browse?table=lines_live', 'rss'],
    ['Categorias', '/browse?table=stream_categories', 'folder'],
    ['Provedores', '/browse?table=providers', 'cloud'],
    ['Filas', '/browse?table=queue', 'list-rich'],
    ['Sinais', '/browse?table=signals', 'bell'],
];
foreach ($quick as [$label, $href, $ico]) {
    $html .= '<div class="col-12 col-md-6"><a class="xds-quick-link" href="' . e($href) . '"><span><span class="me-2 text-primary">' . icon($ico) . '</span>' . e($label) . '</span><i class="icon cil-chevron-right"></i></a></div>';
}
$html .= '</div></div></div></div><div class="col-12 col-xl-4"><div class="card xds-card h-100"><div class="card-header"><strong>Estado do sistema</strong></div><div class="card-body"><div class="d-flex justify-content-between py-2 border-bottom"><span class="text-body-secondary">Engine</span><span class="text-success fw-semibold">Conectado</span></div><div class="d-flex justify-content-between py-2 border-bottom"><span class="text-body-secondary">Banco operacional</span><span>xc_vm</span></div><div class="d-flex justify-content-between py-2 border-bottom"><span class="text-body-secondary">Banco do painel</span><span>xds</span></div><div class="d-flex justify-content-between py-2 border-bottom"><span class="text-body-secondary">Permissão do engine</span><span class="badge bg-warning-subtle text-warning-emphasis">Somente leitura</span></div><div class="d-grid mt-4"><a class="btn btn-outline-primary" href="/diagnostics">Executar diagnóstico</a></div></div></div></div></div>';

render('Dashboard', $html, 'XC_VM como engine · XDS Control como camada administrativa independente');
