<?php

declare(strict_types=1);

function xdsServerDetails(PDO $engineDb, PDO $panelDb, int $serverId): void
{
    if ($serverId <= 0) {
        http_response_code(400);
        render('Servidor', '<div class="alert alert-warning">Servidor inválido.</div>');
        return;
    }

    $serverStmt = $engineDb->prepare(
        'SELECT s.*,
                ss.cpu,
                ss.total_mem_used_percent,
                ss.bytes_sent,
                ss.bytes_received,
                ss.connections,
                ss.users,
                ss.total_running_streams,
                ss.uptime,
                ss.time AS stats_time
           FROM servers s
      LEFT JOIN servers_stats ss
             ON ss.id = (SELECT MAX(x.id) FROM servers_stats x WHERE x.server_id = s.id)
          WHERE s.id = ?
          LIMIT 1'
    );
    $serverStmt->execute([$serverId]);
    $server = $serverStmt->fetch();

    if (!$server) {
        http_response_code(404);
        render('Servidor', '<div class="alert alert-danger">Servidor não encontrado.</div>');
        return;
    }

    $period = (string)($_GET['period'] ?? '24h');
    $periods = [
        '1h' => 3600,
        '6h' => 21600,
        '24h' => 86400,
        '7d' => 604800,
    ];
    if (!isset($periods[$period])) {
        $period = '24h';
    }

    $since = time() - $periods[$period];
    $statsStmt = $engineDb->prepare(
        'SELECT `time`, cpu, total_mem_used_percent, connections, users,
                total_running_streams, bytes_received, bytes_sent, uptime
           FROM servers_stats
          WHERE server_id = ? AND `time` >= ?
       ORDER BY `time` ASC'
    );
    $statsStmt->execute([$serverId, $since]);
    $stats = $statsStmt->fetchAll();

    if (!$stats) {
        $fallback = $engineDb->prepare(
            'SELECT `time`, cpu, total_mem_used_percent, connections, users,
                    total_running_streams, bytes_received, bytes_sent, uptime
               FROM servers_stats
              WHERE server_id = ?
           ORDER BY id DESC LIMIT 120'
        );
        $fallback->execute([$serverId]);
        $stats = array_reverse($fallback->fetchAll());
    }

    $maxCpu = 0.0;
    $avgCpu = 0.0;
    $maxMem = 0.0;
    $avgMem = 0.0;
    $peakConnections = 0;
    $peakUsers = 0;
    $peakIn = 0.0;
    $peakOut = 0.0;
    $peakCpuAt = null;
    $peakMemAt = null;
    $peakConnectionsAt = null;

    $labels = [];
    $cpu = [];
    $mem = [];
    $connections = [];
    $users = [];
    $networkIn = [];
    $networkOut = [];

    foreach ($stats as $row) {
        $ts = (int)($row['time'] ?? 0);
        $cpuValue = (float)($row['cpu'] ?? 0);
        $memValue = (float)($row['total_mem_used_percent'] ?? 0);
        $connValue = (int)($row['connections'] ?? 0);
        $userValue = (int)($row['users'] ?? 0);
        $inValue = (float)($row['bytes_received'] ?? 0) / 125000;
        $outValue = (float)($row['bytes_sent'] ?? 0) / 125000;

        $labels[] = $ts > 0 ? date($period === '7d' ? 'd/m H:i' : 'H:i', $ts) : '—';
        $cpu[] = round($cpuValue, 2);
        $mem[] = round($memValue, 2);
        $connections[] = $connValue;
        $users[] = $userValue;
        $networkIn[] = round($inValue, 2);
        $networkOut[] = round($outValue, 2);

        if ($cpuValue > $maxCpu) {
            $maxCpu = $cpuValue;
            $peakCpuAt = $ts;
        }
        if ($memValue > $maxMem) {
            $maxMem = $memValue;
            $peakMemAt = $ts;
        }
        if ($connValue > $peakConnections) {
            $peakConnections = $connValue;
            $peakConnectionsAt = $ts;
        }
        $peakUsers = max($peakUsers, $userValue);
        $peakIn = max($peakIn, $inValue);
        $peakOut = max($peakOut, $outValue);
        $avgCpu += $cpuValue;
        $avgMem += $memValue;
    }

    $sampleCount = count($stats);
    if ($sampleCount > 0) {
        $avgCpu /= $sampleCount;
        $avgMem /= $sampleCount;
    }

    $currentCpu = (float)($server['cpu'] ?? 0);
    $currentMem = (float)($server['total_mem_used_percent'] ?? 0);
    $currentConnections = (int)($server['connections'] ?? 0);
    $currentUsers = (int)($server['users'] ?? 0);
    $currentStreams = (int)($server['total_running_streams'] ?? 0);
    $online = (int)($server['enabled'] ?? 0) === 1 && (int)($server['status'] ?? 0) >= 0;

    $periodButtons = '';
    foreach (array_keys($periods) as $key) {
        $periodButtons .= '<a class="btn btn-sm ' . ($period === $key ? 'btn-primary' : 'btn-outline-secondary') . '" href="/server?id=' . (int)$serverId . '&period=' . e($key) . '">' . e($key) . '</a> ';
    }

    $html = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">'
          . '<div><a class="btn btn-sm btn-outline-secondary mb-2" href="' . e(moduleUrl('servers')) . '"><i class="bi bi-arrow-left me-1"></i>Servidores</a>'
          . '<div class="d-flex align-items-center gap-2"><h4 class="mb-0">' . e($server['server_name']) . '</h4>'
          . statusDot($online) . '</div>'
          . '<div class="text-body-secondary">' . e($server['server_ip'] ?? '—') . ' · ' . ((int)($server['is_main'] ?? 0) === 1 ? 'Main Server' : 'Load Balancer') . '</div></div>'
          . '<div class="btn-group" role="group">' . $periodButtons . '</div></div>';

    $cards = [
        ['CPU agora', number_format($currentCpu, 1) . '%', 'cpu', 'primary'],
        ['Pico CPU', number_format($maxCpu, 1) . '%', 'graph-up-arrow', 'danger'],
        ['RAM agora', number_format($currentMem, 1) . '%', 'memory', 'info'],
        ['Pico RAM', number_format($maxMem, 1) . '%', 'bar-chart-line', 'warning'],
        ['Conexões agora', $currentConnections, 'broadcast', 'success'],
        ['Pico conexões', $peakConnections, 'activity', 'secondary'],
    ];

    $html .= '<div class="row g-3">';
    foreach ($cards as [$label, $value, $icon, $tone]) {
        $html .= '<div class="col-6 col-lg-4 col-xxl-2"><div class="card xds-panel h-100"><div class="card-body">'
              . '<div class="d-flex justify-content-between align-items-start"><div><div class="text-body-secondary small">' . e($label) . '</div><div class="fs-4 fw-bold mt-1">' . e($value) . '</div></div>'
              . '<span class="badge text-bg-' . e($tone) . '"><i class="bi bi-' . e($icon) . '"></i></span></div></div></div></div>';
    }
    $html .= '</div>';

    $html .= '<div class="card xds-panel mt-3"><div class="card-header"><ul class="nav nav-tabs card-header-tabs" role="tablist">'
          . '<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview" type="button">Visão Geral</button></li>'
          . '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#metrics" type="button">Métricas</button></li>'
          . '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#network" type="button">Rede</button></li>'
          . '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#history" type="button">Histórico</button></li>'
          . '</ul></div><div class="card-body"><div class="tab-content">';

    $html .= '<div class="tab-pane fade show active" id="overview"><div class="row g-3">'
          . '<div class="col-12 col-xl-8"><div class="card border h-100"><div class="card-header"><strong>CPU e Memória</strong><div class="text-body-secondary small">Período: ' . e($period) . '</div></div><div class="card-body"><div id="serverResourceChart" style="min-height:320px"></div></div></div></div>'
          . '<div class="col-12 col-xl-4"><div class="card border h-100"><div class="card-header"><strong>Resumo</strong></div><div class="card-body">'
          . '<div class="d-flex justify-content-between py-2 border-bottom"><span>CPU média</span><strong>' . number_format($avgCpu, 1) . '%</strong></div>'
          . '<div class="d-flex justify-content-between py-2 border-bottom"><span>RAM média</span><strong>' . number_format($avgMem, 1) . '%</strong></div>'
          . '<div class="d-flex justify-content-between py-2 border-bottom"><span>Usuários agora</span><strong>' . $currentUsers . '</strong></div>'
          . '<div class="d-flex justify-content-between py-2 border-bottom"><span>Pico usuários</span><strong>' . $peakUsers . '</strong></div>'
          . '<div class="d-flex justify-content-between py-2 border-bottom"><span>Streams agora</span><strong>' . $currentStreams . '</strong></div>'
          . '<div class="d-flex justify-content-between py-2"><span>Versão</span><strong>' . e($server['xc_vm_version'] ?? '—') . '</strong></div>'
          . '</div></div></div></div></div>';

    $html .= '<div class="tab-pane fade" id="metrics"><div class="row g-3">'
          . '<div class="col-12 col-xl-8"><div class="card border"><div class="card-header"><strong>Conexões e Usuários</strong></div><div class="card-body"><div id="serverConnectionsChart" style="min-height:320px"></div></div></div></div>'
          . '<div class="col-12 col-xl-4"><div class="card border"><div class="card-header"><strong>Picos registrados</strong></div><div class="card-body">'
          . '<div class="mb-3"><div class="text-body-secondary small">Pico CPU</div><div class="fw-bold">' . number_format($maxCpu, 1) . '%</div><div class="small text-body-secondary">' . ($peakCpuAt ? date('d/m/Y H:i:s', $peakCpuAt) : '—') . '</div></div>'
          . '<div class="mb-3"><div class="text-body-secondary small">Pico RAM</div><div class="fw-bold">' . number_format($maxMem, 1) . '%</div><div class="small text-body-secondary">' . ($peakMemAt ? date('d/m/Y H:i:s', $peakMemAt) : '—') . '</div></div>'
          . '<div><div class="text-body-secondary small">Pico conexões</div><div class="fw-bold">' . $peakConnections . '</div><div class="small text-body-secondary">' . ($peakConnectionsAt ? date('d/m/Y H:i:s', $peakConnectionsAt) : '—') . '</div></div>'
          . '</div></div></div></div></div>';

    $html .= '<div class="tab-pane fade" id="network"><div class="row g-3">'
          . '<div class="col-12 col-xl-9"><div class="card border"><div class="card-header"><strong>Tráfego de Rede</strong></div><div class="card-body"><div id="serverNetworkChart" style="min-height:340px"></div></div></div></div>'
          . '<div class="col-12 col-xl-3"><div class="card border"><div class="card-header"><strong>Picos de banda</strong></div><div class="card-body">'
          . '<div class="mb-3"><div class="text-body-secondary small">Entrada</div><div class="fs-5 fw-bold">' . number_format($peakIn, 2) . ' Mbps</div></div>'
          . '<div><div class="text-body-secondary small">Saída</div><div class="fs-5 fw-bold">' . number_format($peakOut, 2) . ' Mbps</div></div>'
          . '</div></div></div></div></div>';

    $historyRows = [];
    foreach (array_slice(array_reverse($stats), 0, 100) as $row) {
        $historyRows[] = [
            e(((int)($row['time'] ?? 0) > 0) ? date('d/m/Y H:i:s', (int)$row['time']) : '—'),
            e(number_format((float)($row['cpu'] ?? 0), 1) . '%'),
            e(number_format((float)($row['total_mem_used_percent'] ?? 0), 1) . '%'),
            e((string)($row['connections'] ?? 0)),
            e((string)($row['users'] ?? 0)),
            e(number_format((float)($row['bytes_received'] ?? 0) / 125000, 2) . ' Mbps'),
            e(number_format((float)($row['bytes_sent'] ?? 0) / 125000, 2) . ' Mbps'),
        ];
    }
    $html .= '<div class="tab-pane fade" id="history">' . tableShell(['Data', 'CPU', 'RAM', 'Conexões', 'Usuários', 'Entrada', 'Saída'], $historyRows, 'Sem amostras para este servidor.') . '</div>';
    $html .= '</div></div></div>';

    $js = '<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script><script>(function(){'
        . 'const labels=' . json_encode($labels) . ',cpu=' . json_encode($cpu) . ',mem=' . json_encode($mem) . ',connections=' . json_encode($connections) . ',users=' . json_encode($users) . ',netIn=' . json_encode($networkIn) . ',netOut=' . json_encode($networkOut) . ';'
        . 'const base={chart:{toolbar:{show:false},animations:{enabled:false}},stroke:{curve:"smooth",width:2},xaxis:{categories:labels},legend:{position:"top"}};'
        . 'new ApexCharts(document.querySelector("#serverResourceChart"),{...base,series:[{name:"CPU %",data:cpu},{name:"RAM %",data:mem}],yaxis:{min:0,max:100}}).render();'
        . 'new ApexCharts(document.querySelector("#serverConnectionsChart"),{...base,series:[{name:"Conexões",data:connections},{name:"Usuários",data:users}],chart:{...base.chart,type:"area"}}).render();'
        . 'new ApexCharts(document.querySelector("#serverNetworkChart"),{...base,series:[{name:"Entrada Mbps",data:netIn},{name:"Saída Mbps",data:netOut}]}).render();'
        . '})();</script>';

    audit($panelDb, 'view_server_details', 'server', (string)$serverId, ['period' => $period]);
    render('Servidor · ' . $server['server_name'], $html, 'Métricas, picos, rede e histórico do servidor', $js);
}
