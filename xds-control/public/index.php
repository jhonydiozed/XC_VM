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

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function xdsLog(string $level, string $message, array $context = []): void {
    @mkdir(dirname(XDS_LOG), 0750, true);
    @file_put_contents(XDS_LOG, json_encode([
        'time'=>gmdate('c'),'level'=>$level,'message'=>$message,'context'=>$context,
        'request_id'=>$_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(6)),
        'ip'=>$_SERVER['REMOTE_ADDR'] ?? null,
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND|LOCK_EX);
}
set_exception_handler(function(Throwable $e): void {
    xdsLog('error',$e->getMessage(),['exception'=>get_class($e),'file'=>$e->getFile(),'line'=>$e->getLine()]);
    http_response_code(500);
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/health')) {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'internal_error']); return;
    }
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>XDS Control</title><body style="font-family:system-ui;padding:40px;background:#111827;color:#fff"><h1>XDS Control</h1><p>Erro interno registrado.</p><code>'.e(XDS_LOG).'</code></body></html>';
});

$configFile = XDS_ROOT.'/config/config.php';
if (!is_file($configFile)) throw new RuntimeException('Configuração ausente: '.$configFile);
$config = require $configFile;
$db = $config['database'] ?? [];
$modules = require XDS_ROOT.'/config/modules.php';

function pdoFor(array $db, string $database): PDO {
    $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$db['host'],$db['port'],$database,$db['charset']??'utf8mb4');
    return new PDO($dsn,$db['username'],$db['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
    ]);
}
$panelDb=pdoFor($db,$db['panel_database']);
$engineDb=pdoFor($db,$db['engine_database']);

function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function requireCsrf(): void { if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')) throw new RuntimeException('CSRF inválido'); }
function currentUser(): ?array { return $_SESSION['user']??null; }
function requireLogin(): void { if(!currentUser()){ header('Location: /login'); exit; } }
function audit(PDO $db,string $action,?string $entityType=null,?string $entityId=null,array $metadata=[]): void {
    $s=$db->prepare('INSERT INTO audit_logs (admin_user_id,action,entity_type,entity_id,ip_address,metadata_json) VALUES (?,?,?,?,?,?)');
    $s->execute([currentUser()['id']??null,$action,$entityType,$entityId,$_SERVER['REMOTE_ADDR']??null,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
}
function moduleUrl(string $slug): string { return '/module?name='.rawurlencode($slug); }
function fmtTime(?int $ts): string { return $ts && $ts>1000000000 ? date('d/m/Y H:i',$ts) : '—'; }
function fmtDuration(int $seconds): string { $seconds=max(0,$seconds); $d=intdiv($seconds,86400); $h=intdiv($seconds%86400,3600); $m=intdiv($seconds%3600,60); return ($d?$d.'d ':'').sprintf('%02d:%02d',$h,$m); }
function badgeBool(mixed $v,string $yes='Sim',string $no='Não'): string { return (int)$v===1?'<span class="badge text-bg-success">'.e($yes).'</span>':'<span class="badge text-bg-secondary">'.e($no).'</span>'; }
function statusDot(bool $ok,string $yes='Online',string $no='Offline'): string { return '<span class="xds-status '.($ok?'text-success':'text-danger').'">'.e($ok?$yes:$no).'</span>'; }

function navItem(string $href,string $label,string $icon): string {
    $uri=$_SERVER['REQUEST_URI']??'/'; $active=$uri===$href||str_starts_with($uri,$href.'&');
    return '<li class="nav-item"><a href="'.e($href).'" class="nav-link'.($active?' active':'').'"><i class="nav-icon bi bi-'.e($icon).'"></i><p>'.e($label).'</p></a></li>';
}
function navTree(string $label,string $icon,array $slugs,array $modules): string {
    $uri=$_SERVER['REQUEST_URI']??'/'; $open=false;
    foreach($slugs as $slug){ if(str_contains($uri,'name='.rawurlencode($slug))){$open=true;break;} }
    $h='<li class="nav-item'.($open?' menu-open':'').'"><a href="#" class="nav-link'.($open?' active':'').'"><i class="nav-icon bi bi-'.e($icon).'"></i><p>'.e($label).'<i class="nav-arrow bi bi-chevron-right"></i></p></a><ul class="nav nav-treeview">';
    foreach($slugs as $slug){ if(isset($modules[$slug])) $h.=navItem(moduleUrl($slug),$modules[$slug]['label'],$modules[$slug]['icon']); }
    return $h.'</ul></li>';
}

function renderLogin(string $error=''): void {
    echo '<!doctype html><html lang="pt-BR" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar · XDS Control</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.1.0/dist/css/adminlte.min.css"><link rel="stylesheet" href="/assets/xds-adminlte.css"></head><body class="login-page bg-body-secondary"><div class="login-box"><div class="card card-outline card-primary shadow"><div class="card-header text-center py-4"><div class="xds-login-brand"><span class="xds-logo">XDS</span><div><div class="fs-4 fw-bold">XDS Control</div><div class="text-body-secondary small">Administração do engine XC_VM</div></div></div></div><div class="card-body p-4">'.$error.'<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><div class="input-group mb-3"><input class="form-control" name="username" placeholder="Usuário" required autofocus><div class="input-group-text"><span class="bi bi-person"></span></div></div><div class="input-group mb-3"><input class="form-control" type="password" name="password" placeholder="Senha" required><div class="input-group-text"><span class="bi bi-lock-fill"></span></div></div><button class="btn btn-primary w-100">Entrar</button></form></div></div></div></body></html>';
}

function render(string $title,string $body,string $subtitle='',string $extraJs=''): void {
    global $modules; $u=currentUser(); $name=$u['display_name']?:$u['username'];
    $nav=navItem('/','Dashboard','speedometer2');
    $nav.=navTree('Gerenciamento','people',['lines','users','mag_devices','enigma2_devices','packages'],$modules);
    $nav.=navTree('Conteúdo','collection-play',['live_channels','movies','series','episodes','created_channels','radio_stations','categories','bouquets','epg','providers'],$modules);
    $nav.=navTree('Servidores','server',['servers','server_stats','queue'],$modules);
    $nav.=navTree('Monitoramento','activity',['active_connections','connection_history','line_activity','stream_errors','stream_logs','panel_logs'],$modules);
    $nav.=navTree('Segurança','shield-lock',['blocked_ips','blocked_isps','blocked_asns','blocked_user_agents','restream_detection'],$modules);
    $nav.=navTree('Sistema','gear',['settings','profiles','output_formats','access_codes','cronjobs','signals'],$modules);
    $nav.=navItem('/audit','Auditoria XDS','clipboard-check').navItem('/diagnostics','Diagnóstico','wrench-adjustable-circle');

    echo '<!doctype html><html lang="pt-BR" data-bs-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' · XDS Control</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.1.0/dist/css/adminlte.min.css"><link rel="stylesheet" href="/assets/xds-adminlte.css"><link rel="stylesheet" href="/assets/xds-operations.css"></head><body class="layout-fixed sidebar-expand-lg sidebar-mini bg-body-tertiary"><div class="app-wrapper">';
    echo '<nav class="app-header navbar navbar-expand bg-body shadow-sm"><div class="container-fluid"><ul class="navbar-nav"><li class="nav-item"><a class="nav-link" data-lte-toggle="sidebar" href="#"><i class="bi bi-list fs-5"></i></a></li><li class="nav-item d-none d-md-block"><span class="nav-link fw-semibold">'.e($title).'</span></li></ul><ul class="navbar-nav ms-auto"><li class="nav-item d-none d-md-flex align-items-center px-2"><span class="xds-online-dot me-2"></span><span class="small text-body-secondary">XC_VM conectado</span></li><li class="nav-item"><button class="nav-link border-0 bg-transparent" id="themeToggle"><i class="bi bi-circle-half"></i></button></li><li class="nav-item dropdown"><a class="nav-link" data-bs-toggle="dropdown" href="#"><span class="xds-avatar">'.e(strtoupper(substr($name,0,1))).'</span></a><div class="dropdown-menu dropdown-menu-end"><span class="dropdown-item-text fw-semibold">'.e($name).'</span><div class="dropdown-divider"></div><a class="dropdown-item" href="/diagnostics">Diagnóstico</a><a class="dropdown-item" href="/logout">Sair</a></div></li></ul></div></nav>';
    echo '<aside class="app-sidebar bg-dark shadow" data-bs-theme="dark"><div class="sidebar-brand"><a href="/" class="brand-link text-decoration-none"><span class="xds-logo">XDS</span><span class="brand-text fw-semibold ms-2">CONTROL</span></a></div><div class="sidebar-wrapper"><nav class="mt-2"><ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false">'.$nav.'</ul></nav></div></aside>';
    echo '<main class="app-main"><div class="app-content-header"><div class="container-fluid"><h3 class="mb-1 fw-bold">'.e($title).'</h3>'.($subtitle?'<div class="text-body-secondary">'.e($subtitle).'</div>':'').'</div></div><div class="app-content"><div class="container-fluid">'.$body.'</div></div></main>';
    echo '<footer class="app-footer"><div class="float-end d-none d-sm-inline">Engine em leitura</div><strong>XDS Control</strong> <span class="text-body-secondary">· XC_VM preservado</span></footer></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/admin-lte@4.1.0/dist/js/adminlte.min.js"></script><script>(function(){const r=document.documentElement,s=localStorage.getItem("xds-theme")||"light";r.setAttribute("data-bs-theme",s);document.getElementById("themeToggle")?.addEventListener("click",()=>{const n=r.getAttribute("data-bs-theme")==="dark"?"light":"dark";r.setAttribute("data-bs-theme",n);localStorage.setItem("xds-theme",n);});})();</script>'.$extraJs.'</body></html>';
}

function tableShell(array $headers,array $rows,string $empty='Nenhum registro encontrado.'): string {
    $h='<div class="card xds-panel"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover xds-op-table mb-0"><thead><tr>';
    foreach($headers as $x)$h.='<th>'.e($x).'</th>'; $h.='</tr></thead><tbody>';
    foreach($rows as $r){$h.='<tr>';foreach($r as $c)$h.='<td>'.$c.'</td>';$h.='</tr>';}
    if(!$rows)$h.='<tr><td colspan="'.count($headers).'" class="text-center py-5 text-body-secondary">'.e($empty).'</td></tr>';
    return $h.'</tbody></table></div></div></div>';
}

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if($path==='/health'){
    header('Content-Type: application/json'); $result=['ok'=>true,'time'=>gmdate('c'),'checks'=>[]];
    foreach(['panel'=>$panelDb,'engine'=>$engineDb] as $n=>$pdo){try{$result['checks'][$n]=['ok'=>(bool)$pdo->query('SELECT 1')->fetchColumn()];}catch(Throwable $e){$result['ok']=false;$result['checks'][$n]=['ok'=>false,'error'=>$e->getMessage()];}}
    http_response_code($result['ok']?200:503); echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit;
}
if($path==='/login'){
    if($_SERVER['REQUEST_METHOD']==='POST'){
        requireCsrf(); $s=$panelDb->prepare('SELECT id,username,password_hash,display_name FROM admin_users WHERE username=? AND enabled=1 LIMIT 1'); $s->execute([trim($_POST['username']??'')]); $u=$s->fetch();
        if($u&&password_verify($_POST['password']??'',$u['password_hash'])){session_regenerate_id(true);$_SESSION['user']=['id'=>(int)$u['id'],'username'=>$u['username'],'display_name'=>$u['display_name']];$panelDb->prepare('UPDATE admin_users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);audit($panelDb,'login_success','admin_user',(string)$u['id']);header('Location: /');exit;}
        $error='<div class="alert alert-danger py-2">Usuário ou senha inválidos.</div>';
    } renderLogin($error??'');exit;
}
if($path==='/logout'){if(currentUser())audit($panelDb,'logout');$_SESSION=[];session_destroy();header('Location: /login');exit;}
requireLogin();

if($path==='/module'){
    $slug=(string)($_GET['name']??''); if(!isset($modules[$slug])){http_response_code(404);render('Não encontrado','<div class="alert alert-danger">Módulo não autorizado.</div>');exit;}
    $m=$modules[$slug]; $limit=min(250,max(25,(int)($_GET['limit']??100))); $q=trim((string)($_GET['q']??''));

    if($slug==='active_connections'){
        $serverId=max(0,(int)($_GET['server_id']??0)); $where=[];$params=[];
        if($serverId){$where[]='ll.server_id=?';$params[]=$serverId;}
        if($q!==''){$where[]='(l.username LIKE ? OR s.stream_display_name LIKE ? OR ll.user_ip LIKE ? OR ll.isp LIKE ? OR ll.user_agent LIKE ?)';for($i=0;$i<5;$i++)$params[]='%'.$q.'%';}
        $sql='SELECT ll.activity_id,ll.user_id,ll.stream_id,ll.server_id,ll.user_agent,ll.user_ip,ll.container,ll.date_start,ll.geoip_country_code,ll.isp,l.username,l.is_restreamer,s.stream_display_name,s.target_container,sv.server_name FROM lines_live ll LEFT JOIN lines l ON l.id=ll.user_id LEFT JOIN streams s ON s.id=ll.stream_id LEFT JOIN servers sv ON sv.id=ll.server_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY ll.activity_id DESC LIMIT '.$limit;
        $st=$engineDb->prepare($sql);$st->execute($params);$data=$st->fetchAll();
        $servers=$engineDb->query('SELECT id,server_name FROM servers ORDER BY `order`,id')->fetchAll();
        $filters='<form class="xds-filterbar mb-3"><input type="hidden" name="name" value="active_connections"><div class="row g-2 align-items-end"><div class="col-12 col-md-4"><label class="form-label">Pesquisar</label><input class="form-control" name="q" value="'.e($q).'" placeholder="Cliente, stream, IP, ISP ou player"></div><div class="col-6 col-md-3"><label class="form-label">Servidor</label><select class="form-select" name="server_id"><option value="0">Todos</option>';
        foreach($servers as $sv)$filters.='<option value="'.e($sv['id']).'"'.($serverId==(int)$sv['id']?' selected':'').'>'.e($sv['server_name']).'</option>';
        $filters.='</select></div><div class="col-6 col-md-2"><label class="form-label">Mostrar</label><select class="form-select" name="limit">';foreach([50,100,250] as $n)$filters.='<option'.($limit===$n?' selected':'').'>'.$n.'</option>';$filters.='</select></div><div class="col-12 col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Aplicar filtros</button></div></div></form>';
        $rows=[];$now=time();foreach($data as $r){$rows[]=[
            '<span class="fw-bold">#'.e($r['activity_id']).'</span>',
            '<span class="badge text-bg-info">'.e(strtoupper((string)($r['target_container']?:$r['container']?:'AUTO'))).'</span>',
            '<span class="main-cell">'.e($r['username']?:'Linha #'.$r['user_id']).'</span><span class="sub-cell">ID '.e($r['user_id']).'</span>',
            '<span class="main-cell">'.e($r['stream_display_name']?:'Stream #'.$r['stream_id']).'</span>',
            e($r['server_name']?:'Servidor #'.$r['server_id']),
            '<span title="'.e($r['user_agent']).'">'.e(mb_strimwidth((string)$r['user_agent'],0,28,'…')).'</span>',
            e($r['isp']?:'—'), e($r['user_ip']?:'—'),
            '<span class="xds-duration">'.e(fmtDuration($now-(int)$r['date_start'])).'</span>',
            e($r['container']?:'—'), badgeBool($r['is_restreamer'],'Sim','Não'),
            '<button class="btn btn-sm btn-outline-secondary" title="Detalhes"><i class="bi bi-eye"></i></button>'
        ];}
        audit($panelDb,'view_module','module',$slug,['limit'=>$limit]);render('Conexões Ativas',$filters.tableShell(['ID','Qualidade','Linha','Stream','Servidor','Player','ISP','IP','Duração','Saída','Restreamer','Ações'],$rows),'Clientes conectados neste momento');exit;
    }

    if($slug==='lines'){
        $params=[];$where='';if($q!==''){$where=' WHERE l.username LIKE ? OR l.last_ip LIKE ? OR l.contact LIKE ?';$params=['%'.$q.'%','%'.$q.'%','%'.$q.'%'];}
        $sql='SELECT l.id,l.username,l.password,l.member_id,l.admin_enabled,l.enabled,l.is_trial,l.is_restreamer,l.max_connections,l.exp_date,l.last_activity,(SELECT COUNT(*) FROM lines_live ll WHERE ll.user_id=l.id) online FROM lines l'.$where.' ORDER BY l.id DESC LIMIT '.$limit;
        $st=$engineDb->prepare($sql);$st->execute($params);$data=$st->fetchAll();
        $filters='<form class="xds-filterbar mb-3"><input type="hidden" name="name" value="lines"><div class="row g-2 align-items-end"><div class="col-12 col-md-7"><label class="form-label">Pesquisar</label><input class="form-control" name="q" value="'.e($q).'" placeholder="Usuário, IP ou contato"></div><div class="col-5 col-md-2"><label class="form-label">Mostrar</label><select class="form-select" name="limit">';foreach([50,100,250] as $n)$filters.='<option'.($limit===$n?' selected':'').'>'.$n.'</option>';$filters.='</select></div><div class="col-7 col-md-3"><button class="btn btn-primary w-100">Pesquisar linhas</button></div></div></form>';
        $rows=[];foreach($data as $r){$active=(int)$r['enabled']===1&&(int)$r['admin_enabled']===1; $rows[]=[
            '<strong>#'.e($r['id']).'</strong>', '<span class="main-cell">'.e($r['username']).'</span>', '<code>'.e($r['password']).'</code>', e($r['member_id']?:'—'), statusDot($active,'Ativa','Bloqueada'),
            '<span class="badge text-bg-'.((int)$r['online']>0?'success':'secondary').'">'.e($r['online']).'</span>', badgeBool($r['is_trial']), badgeBool($r['is_restreamer']), badgeBool($r['enabled']),
            e($r['max_connections']), fmtTime((int)$r['exp_date']), fmtTime((int)$r['last_activity']), '<button class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></button>'
        ];}
        audit($panelDb,'view_module','module',$slug,['limit'=>$limit]);render('Linhas',$filters.tableShell(['ID','Usuário','Senha','Proprietário','Status','Online','Trial','Restreamer','Ativo','Conexões','Expiração','Última Conexão','Ações'],$rows),'Assinaturas e credenciais de clientes');exit;
    }

    if($slug==='servers'){
        $data=$engineDb->query('SELECT s.*,ss.cpu,ss.total_mem_used_percent,ss.bytes_sent,ss.bytes_received,ss.uptime,ss.total_running_streams FROM servers s LEFT JOIN servers_stats ss ON ss.id=(SELECT MAX(x.id) FROM servers_stats x WHERE x.server_id=s.id) ORDER BY s.`order`,s.id')->fetchAll();
        $rows=[];foreach($data as $r){$ok=(int)$r['enabled']===1&&(int)$r['status']>=0;$net=((float)$r['bytes_received']+(float)$r['bytes_sent'])/125000;$rows[]=[e($r['order']?:$r['id']),statusDot($ok),badgeBool($r['enable_proxy'],'Proxy','Direto'),'<span class="main-cell">'.e($r['server_name']).'</span><span class="sub-cell">'.((int)$r['is_main']===1?'Main Server':'Load Balancer').'</span>',e($r['server_ip']),e($r['connections']),number_format($net,1).' Mbps',number_format((float)$r['cpu'],1).'%',number_format((float)$r['total_mem_used_percent'],1).'%',e($r['ping']).' ms',e($r['xc_vm_version']?:'—'),'<button class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-up"></i></button>'];}
        audit($panelDb,'view_module','module',$slug);render('Servidores',tableShell(['Ordem','Status','Proxy','Nome','IP','Conexões','Rede','CPU','Memória','Ping','Versão','Ações'],$rows),'Main server, load balancers e estado operacional');exit;
    }

    // Fallback temporário para módulos ainda não especializados.
    $table=$m['table']; $where=$m['where']??''; $sql='SELECT * FROM `'.$table.'`'.($where?' WHERE '.$where:'').' LIMIT '.$limit; $data=$engineDb->query($sql)->fetchAll();
    $cols=$data?array_keys($data[0]):array_column($engineDb->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(),'Field');
    $hidden=['password','api_key','access_token','play_token','stream_source','data','movie_properties','transcode_attributes','custom_ffmpeg','last_activity_array'];$cols=array_values(array_filter($cols,fn($c)=>!in_array($c,$hidden,true)));
    $rows=[];foreach($data as $r){$row=[];foreach($cols as $c){$v=$r[$c]??'';$txt=is_scalar($v)?(string)$v:json_encode($v);$row[]=e(mb_strimwidth($txt,0,80,'…'));}$rows[]=$row;}
    audit($panelDb,'view_module','module',$slug,['fallback'=>true]);render($m['label'],tableShell(array_map(fn($c)=>ucwords(str_replace('_',' ',$c)),$cols),$rows),$m['subtitle']);exit;
}

if($path==='/audit'){$data=$panelDb->query('SELECT id,admin_user_id,action,entity_type,entity_id,ip_address,created_at FROM audit_logs ORDER BY id DESC LIMIT 300')->fetchAll();$rows=[];foreach($data as $r)$rows[]=[e($r['id']),e($r['admin_user_id']),'<span class="badge text-bg-info">'.e($r['action']).'</span>',e($r['entity_type']),e($r['entity_id']),e($r['ip_address']),e($r['created_at'])];render('Auditoria XDS',tableShell(['ID','Usuário','Ação','Entidade','ID','IP','Data'],$rows),'Ações administrativas registradas pelo XDS');exit;}
if($path==='/diagnostics'){$checks=['PHP'=>PHP_VERSION,'Engine DB'=>$engineDb->query('SELECT VERSION()')->fetchColumn(),'Tabelas'=>$engineDb->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn(),'Painel DB'=>$panelDb->query('SELECT DATABASE()')->fetchColumn(),'Log gravável'=>is_writable(dirname(XDS_LOG))?'Sim':'Não','Disco livre'=>round(disk_free_space('/')/1073741824,2).' GiB'];$h='<div class="row g-3">';foreach($checks as $n=>$v)$h.='<div class="col-12 col-md-6 col-xl-4"><div class="card xds-panel h-100"><div class="card-body"><div class="xds-muted">'.e($n).'</div><div class="fs-5 fw-bold">'.e($v).'</div></div></div></div>';$h.='</div>';render('Diagnóstico',$h,'Estado do painel, bancos, PHP e armazenamento');exit;}

// Dashboard operacional inspirado na ergonomia do XC_VM.
$active=(int)$engineDb->query('SELECT COUNT(*) FROM lines_live')->fetchColumn();
$onlineUsers=(int)$engineDb->query('SELECT COUNT(DISTINCT user_id) FROM lines_live WHERE user_id IS NOT NULL')->fetchColumn();
$totalLive=(int)$engineDb->query('SELECT COUNT(*) FROM streams WHERE type=1')->fetchColumn();
$totalServers=(int)$engineDb->query('SELECT COUNT(*) FROM servers WHERE enabled=1')->fetchColumn();
$latestStats=$engineDb->query('SELECT ss.* FROM servers_stats ss JOIN (SELECT server_id,MAX(id) id FROM servers_stats GROUP BY server_id) x ON x.id=ss.id')->fetchAll();
$running=0;$cpu=[];$mem=[];$trafficIn=0;$trafficOut=0;foreach($latestStats as $s){$running+=(int)$s['total_running_streams'];$cpu[]=(float)$s['cpu'];$mem[]=(float)$s['total_mem_used_percent'];$trafficIn+=(float)$s['bytes_received'];$trafficOut+=(float)$s['bytes_sent'];}
$offline=max(0,$totalLive-$running);$avgCpu=$cpu?array_sum($cpu)/count($cpu):0;$avgMem=$mem?array_sum($mem)/count($mem):0;
$countries=$engineDb->query("SELECT UPPER(geoip_country_code) code,COUNT(*) total FROM lines_live WHERE geoip_country_code IS NOT NULL AND geoip_country_code<>'' GROUP BY geoip_country_code ORDER BY total DESC")->fetchAll();$countryMap=[];foreach($countries as $c)$countryMap[$c['code']]=(int)$c['total'];
$history=$engineDb->query('SELECT time,cpu,total_mem_used_percent,connections,bytes_received,bytes_sent FROM servers_stats ORDER BY id DESC LIMIT 60')->fetchAll();$history=array_reverse($history);$labels=[];$cpuSeries=[];$memSeries=[];$connSeries=[];$inSeries=[];$outSeries=[];foreach($history as $r){$labels[]=date('H:i',(int)$r['time']);$cpuSeries[]=(float)$r['cpu'];$memSeries[]=(float)$r['total_mem_used_percent'];$connSeries[]=(int)$r['connections'];$inSeries[]=round((float)$r['bytes_received']/125000,2);$outSeries[]=round((float)$r['bytes_sent']/125000,2);}
$serverRows=$engineDb->query('SELECT s.id,s.server_name,s.server_ip,s.is_main,s.enabled,s.status,ss.cpu,ss.total_mem_used_percent,ss.connections,ss.users,ss.total_running_streams,ss.uptime FROM servers s LEFT JOIN servers_stats ss ON ss.id=(SELECT MAX(x.id) FROM servers_stats x WHERE x.server_id=s.id) ORDER BY s.`order`,s.id')->fetchAll();
$kpis=[['Conexões Online',$active,'broadcast','primary'],['Usuários Online',$onlineUsers,'people','success'],['Streams Online',$running,'play-circle','info'],['Streams Offline',$offline,'exclamation-triangle','danger'],['CPU Média',number_format($avgCpu,1).' %','cpu','warning'],['Memória Média',number_format($avgMem,1).' %','memory','secondary']];
$html='<div class="row g-3">';foreach($kpis as [$label,$value,$icon,$tone])$html.='<div class="col-6 col-lg-4 col-xxl-2"><div class="card xds-kpi text-bg-'.$tone.' h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="xds-kpi-value">'.e($value).'</div><div class="xds-kpi-label mt-2">'.e($label).'</div></div><i class="bi bi-'.$icon.' xds-kpi-icon"></i></div></div></div>';$html.='</div>';
$html.='<div class="row g-3 mt-1"><div class="col-12 col-xl-6"><div class="card xds-panel"><div class="card-header"><strong>CPU e Memória</strong><div class="xds-muted">Amostras recentes dos servidores</div></div><div class="card-body"><div id="resourceChart" class="xds-chart"></div></div></div></div><div class="col-12 col-xl-6"><div class="card xds-panel"><div class="card-header"><strong>Tráfego de Rede</strong><div class="xds-muted">Entrada e saída aproximadas em Mbps</div></div><div class="card-body"><div id="networkChart" class="xds-chart"></div></div></div></div><div class="col-12"><div class="card xds-panel"><div class="card-header"><strong>Conexões</strong><div class="xds-muted">Histórico recente registrado pelo engine</div></div><div class="card-body"><div id="connectionsChart" class="xds-chart"></div></div></div></div>';
$html.='<div class="col-12 col-xl-8"><div class="card xds-panel"><div class="card-header"><strong>Mapa Mundial de Conexões</strong><div class="xds-muted">Distribuição atual por país</div></div><div class="card-body"><div id="connectionsMap" class="xds-map"></div></div></div></div><div class="col-12 col-xl-4"><div class="card xds-panel h-100"><div class="card-header"><strong>Principais Países</strong></div><div class="card-body xds-country-list">';foreach(array_slice($countries,0,15) as $c)$html.='<div class="xds-country-row"><span>'.e($c['code']).'</span><strong>'.e($c['total']).'</strong></div>';if(!$countries)$html.='<div class="text-body-secondary">Sem conexões geolocalizadas.</div>';$html.='</div></div></div></div>';
$html.='<div class="card xds-panel mt-3"><div class="card-header"><strong>Servidores</strong><div class="xds-muted">Estado operacional do main e load balancers</div></div><div class="card-body"><div class="row g-3">';foreach($serverRows as $s){$ok=(int)$s['enabled']===1&&(int)$s['status']>=0;$html.='<div class="col-12 col-md-6 col-xl-4"><div class="card xds-server-card h-100"><div class="card-body"><div class="d-flex justify-content-between mb-3"><div><div class="fw-bold">'.e($s['server_name']).'</div><div class="xds-muted">'.e($s['server_ip']).' · '.((int)$s['is_main']===1?'Main':'Load Balancer').'</div></div>'.statusDot($ok).'</div><div class="xds-server-metric"><span>CPU</span><strong>'.number_format((float)$s['cpu'],1).'%</strong></div><div class="progress mb-2"><div class="progress-bar" style="width:'.min(100,(float)$s['cpu']).'%"></div></div><div class="xds-server-metric"><span>Memória</span><strong>'.number_format((float)$s['total_mem_used_percent'],1).'%</strong></div><div class="progress mb-3"><div class="progress-bar bg-info" style="width:'.min(100,(float)$s['total_mem_used_percent']).'%"></div></div><div class="row text-center"><div class="col"><div class="fw-bold">'.e($s['connections']??0).'</div><div class="xds-muted">Conexões</div></div><div class="col"><div class="fw-bold">'.e($s['users']??0).'</div><div class="xds-muted">Usuários</div></div><div class="col"><div class="fw-bold">'.e($s['total_running_streams']??0).'</div><div class="xds-muted">Streams</div></div></div></div></div></div>';}$html.='</div></div></div>';
$extra='<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.6.0/dist/css/jsvectormap.min.css"><script src="https://cdn.jsdelivr.net/npm/apexcharts"></script><script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.6.0/dist/js/jsvectormap.min.js"></script><script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.6.0/dist/maps/world.js"></script><script>(function(){const labels='.json_encode($labels).',cpu='.json_encode($cpuSeries).',mem='.json_encode($memSeries).',con='.json_encode($connSeries).',netIn='.json_encode($inSeries).',netOut='.json_encode($outSeries).',countries='.json_encode($countryMap).';const base={chart:{toolbar:{show:false},animations:{enabled:false}},stroke:{curve:"smooth",width:2},xaxis:{categories:labels},legend:{position:"top"}};new ApexCharts(document.querySelector("#resourceChart"),{...base,series:[{name:"CPU %",data:cpu},{name:"Memória %",data:mem}],yaxis:{min:0,max:100}}).render();new ApexCharts(document.querySelector("#networkChart"),{...base,series:[{name:"Entrada Mbps",data:netIn},{name:"Saída Mbps",data:netOut}]}).render();new ApexCharts(document.querySelector("#connectionsChart"),{...base,series:[{name:"Conexões",data:con}],chart:{...base.chart,type:"area"}}).render();if(document.querySelector("#connectionsMap")){new jsVectorMap({selector:"#connectionsMap",map:"world",zoomButtons:true,regionStyle:{initial:{fill:"#cbd5e1"},hover:{fill:"#0d6efd"}},series:{regions:[{values:countries,scale:["#dbeafe","#0d6efd"],normalizeFunction:"polynomial"}]},onRegionTooltipShow:function(e,tip,code){if(countries[code])tip.text(tip.text()+" — "+countries[code]+" conexão(ões)");}});}})();</script>';
audit($panelDb,'view_dashboard');render('Dashboard',$html,'Visão operacional em tempo real do XC_VM',$extra);
