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
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents(XDS_LOG, json_encode([
        'time' => gmdate('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(6)),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
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
if (!is_file($configFile)) throw new RuntimeException('Configuração ausente: ' . $configFile);
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
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function requireCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) throw new RuntimeException('CSRF inválido');
}
function currentUser(): ?array { return $_SESSION['user'] ?? null; }
function requireLogin(): void { if (!currentUser()) { header('Location: /login'); exit; } }
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
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function navItem(string $href, string $label, string $icon): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $target = parse_url($href, PHP_URL_PATH) ?: '/';
    $active = $path === $target && ($target !== '/browse' || str_contains($uri, parse_url($href, PHP_URL_QUERY) ?: ''));
    return '<li class="nav-item"><a href="' . e($href) . '" class="nav-link' . ($active ? ' active' : '') . '"><i class="nav-icon bi bi-' . e($icon) . '"></i><p>' . e($label) . '</p></a></li>';
}

function navHeader(string $label): string
{
    return '<li class="nav-header">' . e($label) . '</li>';
}

function renderLogin(string $error = ''): void
{
    echo '<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar · XDS Control</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.1.0/dist/css/adminlte.min.css"><link rel="stylesheet" href="/assets/xds-adminlte.css"></head><body class="login-page bg-body-secondary"><div class="login-box"><div class="card card-outline card-primary shadow"><div class="card-header text-center py-4"><div class="xds-login-brand"><span class="xds-logo">XDS</span><div><div class="fs-4 fw-bold">XDS Control</div><div class="text-body-secondary small">Administração do engine XC_VM</div></div></div></div><div class="card-body p-4">' . $error . '<form method="post"><input type="hidden" name="csrf" value="' . e(csrf()) . '"><div class="input-group mb-3"><input class="form-control" name="username" placeholder="Usuário" required autofocus autocomplete="username"><div class="input-group-text"><span class="bi bi-person"></span></div></div><div class="input-group mb-3"><input class="form-control" type="password" name="password" placeholder="Senha" required autocomplete="current-password"><div class="input-group-text"><span class="bi bi-lock-fill"></span></div></div><button class="btn btn-primary w-100">Entrar</button></form><div class="small text-body-secondary mt-4"><span class="xds-online-dot me-2"></span>Engine isolado em modo somente leitura</div></div></div></div><script src="https://cdn.jsdelivr.net/npm/admin-lte@4.1.0/dist/js/adminlte.min.js"></script></body></html>';
}

function render(string $title, string $body, string $subtitle = ''): void
{
    $user = currentUser();
    $name = $user['display_name'] ?: $user['username'];

    $nav = navHeader('VISÃO GERAL');
    $nav .= navItem('/', 'Dashboard', 'speedometer2');
    $nav .= navItem('/audit', 'Atividade e auditoria', 'clock-history');
    $nav .= navHeader('CLIENTES');
    $nav .= navItem('/browse?table=users', 'Usuários', 'people');
    $nav .= navItem('/browse?table=lines', 'Linhas', 'link-45deg');
    $nav .= navItem('/browse?table=lines_live', 'Conexões ativas', 'broadcast');
    $nav .= navHeader('CONTEÚDO');
    $nav .= navItem('/browse?table=streams', 'Streams', 'play-btn');
    $nav .= navItem('/browse?table=streams_categories', 'Categorias', 'folder2-open');
    $nav .= navItem('/browse?table=bouquets', 'Bouquets', 'collection');
    $nav .= navHeader('INFRAESTRUTURA');
    $nav .= navItem('/browse?table=servers', 'Servidores', 'server');
    $nav .= navItem('/browse?table=providers', 'Provedores', 'cloud');
    $nav .= navItem('/diagnostics', 'Diagnóstico', 'activity');
    $nav .= navHeader('SISTEMA');
    $nav .= navItem('/browse?table=settings', 'Configurações', 'gear');

    echo '<!doctype html><html lang="pt-BR" data-bs-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' · XDS Control</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.1.0/dist/css/adminlte.min.css"><link rel="stylesheet" href="/assets/xds-adminlte.css"></head><body class="layout-fixed sidebar-expand-lg sidebar-mini bg-body-tertiary"><div class="app-wrapper">';

    echo '<nav class="app-header navbar navbar-expand bg-body shadow-sm"><div class="container-fluid"><ul class="navbar-nav"><li class="nav-item"><a class="nav-link" data-lte-toggle="sidebar" href="#" role="button"><i class="bi bi-list fs-5"></i></a></li><li class="nav-item d-none d-md-block"><span class="nav-link fw-semibold">' . e($title) . '</span></li></ul><ul class="navbar-nav ms-auto"><li class="nav-item d-none d-md-flex align-items-center px-2"><span class="xds-online-dot me-2"></span><span class="small text-body-secondary">XC_VM conectado</span></li><li class="nav-item"><button class="nav-link border-0 bg-transparent" id="themeToggle" type="button" title="Alternar tema"><i class="bi bi-circle-half"></i></button></li><li class="nav-item dropdown"><a class="nav-link" data-bs-toggle="dropdown" href="#"><span class="xds-avatar">' . e(strtoupper(substr($name, 0, 1))) . '</span></a><div class="dropdown-menu dropdown-menu-end"><span class="dropdown-item-text fw-semibold">' . e($name) . '</span><div class="dropdown-divider"></div><a class="dropdown-item" href="/diagnostics"><i class="bi bi-activity me-2"></i>Diagnóstico</a><a class="dropdown-item" href="/logout"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></div></li></ul></div></nav>';

    echo '<aside class="app-sidebar bg-dark shadow" data-bs-theme="dark"><div class="sidebar-brand"><a href="/" class="brand-link text-decoration-none"><span class="xds-logo">XDS</span><span class="brand-text fw-semibold ms-2">CONTROL</span></a></div><div class="sidebar-wrapper"><nav class="mt-2"><ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">' . $nav . '</ul></nav></div></aside>';

    echo '<main class="app-main"><div class="app-content-header"><div class="container-fluid"><div class="row"><div class="col-sm-8"><h3 class="mb-1 fw-bold">' . e($title) . '</h3>' . ($subtitle !== '' ? '<div class="text-body-secondary">' . e($subtitle) . '</div>' : '') . '</div><div class="col-sm-4"><ol class="breadcrumb float-sm-end mb-0"><li class="breadcrumb-item"><a href="/">XDS Control</a></li><li class="breadcrumb-item active">' . e($title) . '</li></ol></div></div></div></div><div class="app-content"><div class="container-fluid">' . $body . '</div></div></main>';

    echo '<footer class="app-footer"><div class="float-end d-none d-sm-inline">Modo leitura</div><strong>XDS Control</strong> <span class="text-body-secondary">· engine XC_VM preservado</span></footer></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/admin-lte@4.1.0/dist/js/adminlte.min.js"></script><script>(function(){const root=document.documentElement;const saved=localStorage.getItem("xds-theme")||"light";root.setAttribute("data-bs-theme",saved);document.getElementById("themeToggle")?.addEventListener("click",()=>{const next=root.getAttribute("data-bs-theme")==="dark"?"light":"dark";root.setAttribute("data-bs-theme",next);localStorage.setItem("xds-theme",next);});})();</script></body></html>';
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    header('Content-Type: application/json');
    $result = ['ok' => true, 'time' => gmdate('c'), 'checks' => []];
    foreach (['panel' => $panelDb, 'engine' => $engineDb] as $name => $pdo) {
        try { $result['checks'][$name] = ['ok' => (bool)$pdo->query('SELECT 1')->fetchColumn()]; }
        catch (Throwable $e) { $result['ok'] = false; $result['checks'][$name] = ['ok' => false, 'error' => $e->getMessage()]; }
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
            header('Location: /'); exit;
        }
        xdsLog('warning', 'Falha de login', ['username' => $_POST['username'] ?? '']);
        $error = '<div class="alert alert-danger py-2">Usuário ou senha inválidos.</div>';
    }
    renderLogin($error ?? ''); exit;
}

if ($path === '/logout') {
    if (currentUser()) audit($panelDb, 'logout');
    $_SESSION = [];
    session_destroy();
    header('Location: /login');
    exit;
}

requireLogin();

$allowedTables = ['servers','streams','users','bouquets','streams_categories','lines','lines_activity','lines_live','panel_logs','login_logs','settings','queue','signals','providers'];

if ($path === '/browse') {
    $table = $_GET['table'] ?? 'servers';
    if (!in_array($table, $allowedTables, true)) {
        http_response_code(404);
        render('Não encontrado', '<div class="alert alert-danger">Tabela não autorizada.</div>');
        exit;
    }
    $limit = min(250, max(1, (int)($_GET['limit'] ?? 100)));
    try {
        $rows = $engineDb->query('SELECT * FROM `' . $table . '` LIMIT ' . $limit)->fetchAll();
        $columns = $rows ? array_keys($rows[0]) : array_column($engineDb->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
    } catch (Throwable $e) {
        xdsLog('error', 'Falha ao consultar tabela do engine', ['table' => $table, 'error' => $e->getMessage()]);
        render('Falha na consulta', '<div class="alert alert-danger"><strong>Não foi possível consultar este módulo.</strong><br>O erro foi registrado no log do XDS.</div>');
        exit;
    }
    $html = '<div class="card shadow-sm"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><div><strong>' . e(ucwords(str_replace('_',' ',$table))) . '</strong><div class="small text-body-secondary">Consulta somente leitura do engine</div></div><form class="d-flex gap-2" method="get"><input type="hidden" name="table" value="' . e($table) . '"><select class="form-select form-select-sm" name="limit"><option value="50"' . ($limit===50?' selected':'') . '>50</option><option value="100"' . ($limit===100?' selected':'') . '>100</option><option value="250"' . ($limit===250?' selected':'') . '>250</option></select><button class="btn btn-primary btn-sm">Aplicar</button></form></div><div class="card-body p-0"><div class="table-responsive xds-table-wrap"><table class="table table-hover table-striped align-middle mb-0"><thead><tr>';
    foreach ($columns as $column) $html .= '<th>' . e($column) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if (is_string($value) && strlen($value) > 180) $value = substr($value, 0, 180) . '…';
            $html .= '<td title="' . e($value) . '">' . e($value) . '</td>';
        }
        $html .= '</tr>';
    }
    if (!$rows) $html .= '<tr><td colspan="' . max(1,count($columns)) . '" class="text-center py-5 text-body-secondary">Nenhum registro encontrado.</td></tr>';
    $html .= '</tbody></table></div></div></div>';
    audit($panelDb, 'browse_engine_table', 'table', $table, ['limit' => $limit]);
    render(ucwords(str_replace('_', ' ', $table)), $html, 'Tabela do engine XC_VM · até ' . $limit . ' registros');
    exit;
}

if ($path === '/audit') {
    $rows = $panelDb->query('SELECT id, admin_user_id, action, entity_type, entity_id, ip_address, created_at FROM audit_logs ORDER BY id DESC LIMIT 300')->fetchAll();
    $html = '<div class="card shadow-sm"><div class="card-header"><strong>Eventos recentes</strong></div><div class="card-body p-0"><div class="table-responsive xds-table-wrap"><table class="table table-hover align-middle mb-0"><thead><tr><th>ID</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>ID entidade</th><th>IP</th><th>Data</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>'.e($row['id']).'</td><td>'.e($row['admin_user_id']).'</td><td><span class="badge text-bg-info">'.e($row['action']).'</span></td><td>'.e($row['entity_type']).'</td><td>'.e($row['entity_id']).'</td><td>'.e($row['ip_address']).'</td><td>'.e($row['created_at']).'</td></tr>';
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
        $html .= '<div class="col-12 col-md-6 col-xl-4"><div class="card h-100 shadow-sm"><div class="card-body"><div class="text-body-secondary small mb-2">'.e($name).'</div><div class="fs-5 fw-semibold">'.e($value).'</div></div></div></div>';
    }
    $html .= '</div><div class="card mt-4 shadow-sm"><div class="card-body d-flex justify-content-between align-items-center"><div><strong>Health check JSON</strong><div class="small text-body-secondary">Validação das duas conexões MariaDB</div></div><a class="btn btn-primary" href="/health" target="_blank">Abrir health</a></div></div>';
    render('Diagnóstico', $html, 'Estado do painel, bancos, PHP e armazenamento');
    exit;
}

$metrics = [];
foreach (['users','streams','servers','bouquets','lines_activity','lines_live'] as $table) {
    try { $metrics[$table] = (int)$engineDb->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(); }
    catch (Throwable $e) { $metrics[$table] = null; }
}
$meta = [
    'users'=>['Usuários','people','primary'],
    'streams'=>['Streams','play-btn','info'],
    'servers'=>['Servidores','server','success'],
    'bouquets'=>['Bouquets','collection','warning'],
    'lines_activity'=>['Atividades','activity','danger'],
    'lines_live'=>['Conexões ativas','broadcast','primary'],
];
$html = '<div class="row g-3">';
foreach ($metrics as $key => $value) {
    [$label,$ico,$tone] = $meta[$key];
    $html .= '<div class="col-12 col-sm-6 col-xl-4 col-xxl-2"><a href="/browse?table='.e($key).'" class="text-decoration-none"><div class="small-box text-bg-'.e($tone).' h-100"><div class="inner"><h3>'.e($value ?? 'N/A').'</h3><p>'.e($label).'</p></div><i class="small-box-icon bi bi-'.e($ico).'"></i><span class="small-box-footer">Abrir detalhes <i class="bi bi-arrow-right-circle"></i></span></div></a></div>';
}
$html .= '</div>';

$html .= '<div class="row g-4 mt-1"><div class="col-12 col-xl-8"><div class="card shadow-sm h-100"><div class="card-header"><div class="d-flex justify-content-between align-items-center"><div><strong>Visão operacional</strong><div class="small text-body-secondary">Atalhos para os principais módulos do engine</div></div><span class="badge text-bg-success">Online</span></div></div><div class="card-body"><div class="row g-3">';
$quick = [
    ['Linhas','/browse?table=lines','link-45deg'],
    ['Conexões','/browse?table=lines_live','broadcast'],
    ['Categorias','/browse?table=streams_categories','folder2-open'],
    ['Provedores','/browse?table=providers','cloud'],
    ['Filas','/browse?table=queue','list-task'],
    ['Sinais','/browse?table=signals','bell'],
];
foreach ($quick as [$label,$href,$ico]) {
    $html .= '<div class="col-12 col-md-6"><a class="xds-quick-link" href="'.e($href).'"><span><i class="bi bi-'.e($ico).' me-2 text-primary"></i>'.e($label).'</span><i class="bi bi-chevron-right"></i></a></div>';
}
$html .= '</div></div></div></div><div class="col-12 col-xl-4"><div class="card shadow-sm h-100"><div class="card-header"><strong>Estado do sistema</strong></div><div class="card-body"><div class="xds-state-row"><span>Engine</span><strong class="text-success">Conectado</strong></div><div class="xds-state-row"><span>Banco operacional</span><strong>xc_vm</strong></div><div class="xds-state-row"><span>Banco do painel</span><strong>xds</strong></div><div class="xds-state-row"><span>Permissão do engine</span><span class="badge text-bg-warning">Somente leitura</span></div><a class="btn btn-outline-primary w-100 mt-4" href="/diagnostics">Executar diagnóstico</a></div></div></div></div>';

render('Dashboard', $html, 'XC_VM como engine · XDS Control como camada administrativa independente');
