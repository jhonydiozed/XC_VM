<?php

declare(strict_types=1);

/**
 * Catálogo de módulos do XDS Control.
 *
 * A interface pública usa apenas `slug` e `label`.
 * Nomes físicos de tabelas e filtros do XC_VM ficam encapsulados aqui.
 */
return [
    // Clientes e acessos
    'lines' => ['label' => 'Linhas', 'group' => 'Clientes', 'icon' => 'person-vcard', 'table' => 'lines', 'subtitle' => 'Assinaturas e credenciais de clientes'],
    'active_connections' => ['label' => 'Conexões Ativas', 'group' => 'Clientes', 'icon' => 'broadcast', 'table' => 'lines_live', 'subtitle' => 'Clientes conectados neste momento'],
    'connection_history' => ['label' => 'Histórico de Conexões', 'group' => 'Clientes', 'icon' => 'clock-history', 'table' => 'lines_logs', 'subtitle' => 'Histórico de acessos e sessões'],
    'line_activity' => ['label' => 'Atividade das Linhas', 'group' => 'Clientes', 'icon' => 'activity', 'table' => 'lines_activity', 'subtitle' => 'Atividade recente das linhas'],
    'mag_devices' => ['label' => 'Dispositivos MAG', 'group' => 'Clientes', 'icon' => 'tv', 'table' => 'mag_devices', 'subtitle' => 'Dispositivos MAG vinculados'],
    'enigma2_devices' => ['label' => 'Dispositivos Enigma2', 'group' => 'Clientes', 'icon' => 'display', 'table' => 'enigma2_devices', 'subtitle' => 'Dispositivos Enigma2 vinculados'],
    'users' => ['label' => 'Administradores e Revendedores', 'group' => 'Clientes', 'icon' => 'people', 'table' => 'users', 'subtitle' => 'Contas administrativas e de revenda'],
    'packages' => ['label' => 'Pacotes', 'group' => 'Clientes', 'icon' => 'box-seam', 'table' => 'users_packages', 'subtitle' => 'Pacotes disponíveis para usuários e revendedores'],

    // Conteúdo
    'live_channels' => ['label' => 'Canais ao Vivo', 'group' => 'Conteúdo', 'icon' => 'play-circle', 'table' => 'streams', 'where' => '`type` = 1', 'subtitle' => 'Canais de televisão e transmissões ao vivo'],
    'movies' => ['label' => 'Filmes', 'group' => 'Conteúdo', 'icon' => 'film', 'table' => 'streams', 'where' => '`type` = 2', 'subtitle' => 'Biblioteca de filmes VOD'],
    'created_channels' => ['label' => 'Canais Criados', 'group' => 'Conteúdo', 'icon' => 'collection-play', 'table' => 'streams', 'where' => '`type` = 3', 'subtitle' => 'Canais gerados a partir de conteúdo'],
    'radio_stations' => ['label' => 'Rádios', 'group' => 'Conteúdo', 'icon' => 'radio', 'table' => 'streams', 'where' => '`type` = 4', 'subtitle' => 'Estações de rádio'],
    'series' => ['label' => 'Séries', 'group' => 'Conteúdo', 'icon' => 'camera-reels', 'table' => 'streams_series', 'subtitle' => 'Catálogo de séries'],
    'episodes' => ['label' => 'Episódios', 'group' => 'Conteúdo', 'icon' => 'list-ol', 'table' => 'streams_episodes', 'subtitle' => 'Episódios vinculados às séries'],
    'categories' => ['label' => 'Categorias', 'group' => 'Conteúdo', 'icon' => 'folder2-open', 'table' => 'streams_categories', 'subtitle' => 'Categorias de canais, filmes, séries e rádios'],
    'bouquets' => ['label' => 'Bouquets', 'group' => 'Conteúdo', 'icon' => 'collection', 'table' => 'bouquets', 'subtitle' => 'Grupos de conteúdo disponibilizados aos clientes'],
    'epg' => ['label' => 'EPG / Guia de Programação', 'group' => 'Conteúdo', 'icon' => 'calendar3', 'table' => 'epg', 'subtitle' => 'Fontes e atualização do guia de programação'],
    'recordings' => ['label' => 'Gravações', 'group' => 'Conteúdo', 'icon' => 'record-circle', 'table' => 'recordings', 'subtitle' => 'Programações e gravações'],
    'providers' => ['label' => 'Provedores', 'group' => 'Conteúdo', 'icon' => 'cloud-arrow-down', 'table' => 'providers', 'subtitle' => 'Fontes e provedores de conteúdo'],

    // Infraestrutura
    'servers' => ['label' => 'Servidores', 'group' => 'Servidores', 'icon' => 'server', 'table' => 'servers', 'subtitle' => 'Main server e load balancers'],
    'server_stats' => ['label' => 'Desempenho dos Servidores', 'group' => 'Servidores', 'icon' => 'graph-up', 'table' => 'servers_stats', 'subtitle' => 'Estatísticas e utilização dos servidores'],
    'stream_errors' => ['label' => 'Streams com Erro', 'group' => 'Servidores', 'icon' => 'exclamation-triangle', 'table' => 'streams_errors', 'subtitle' => 'Falhas registradas nos streams'],
    'stream_logs' => ['label' => 'Logs de Streams', 'group' => 'Servidores', 'icon' => 'journal-text', 'table' => 'streams_logs', 'subtitle' => 'Eventos e logs de streaming'],
    'queue' => ['label' => 'Fila de Processamento', 'group' => 'Servidores', 'icon' => 'list-task', 'table' => 'queue', 'subtitle' => 'Tarefas aguardando processamento'],

    // Segurança
    'blocked_ips' => ['label' => 'IPs Bloqueados', 'group' => 'Segurança', 'icon' => 'shield-x', 'table' => 'blocked_ips', 'subtitle' => 'Endereços IP bloqueados'],
    'blocked_isps' => ['label' => 'Provedores de Internet Bloqueados', 'group' => 'Segurança', 'icon' => 'router', 'table' => 'blocked_isps', 'subtitle' => 'ISPs impedidos de acessar o serviço'],
    'blocked_asns' => ['label' => 'ASNs Bloqueados', 'group' => 'Segurança', 'icon' => 'diagram-3', 'table' => 'blocked_asns', 'subtitle' => 'Redes ASN bloqueadas'],
    'blocked_user_agents' => ['label' => 'Aplicativos Bloqueados', 'group' => 'Segurança', 'icon' => 'phone', 'table' => 'blocked_uas', 'subtitle' => 'User-Agents e aplicativos bloqueados'],
    'restream_detection' => ['label' => 'Detecção de Restream', 'group' => 'Segurança', 'icon' => 'shield-check', 'table' => 'detect_restream', 'subtitle' => 'Regras e detecção de retransmissão indevida'],

    // Logs e suporte
    'panel_logs' => ['label' => 'Logs do Painel', 'group' => 'Logs e Monitoramento', 'icon' => 'journal-code', 'table' => 'panel_logs', 'subtitle' => 'Eventos registrados pelo painel do engine'],
    'login_logs' => ['label' => 'Logs de Login', 'group' => 'Logs e Monitoramento', 'icon' => 'box-arrow-in-right', 'table' => 'login_logs', 'subtitle' => 'Tentativas e acessos administrativos'],
    'user_logs' => ['label' => 'Logs de Usuários', 'group' => 'Logs e Monitoramento', 'icon' => 'person-lines-fill', 'table' => 'users_logs', 'subtitle' => 'Ações realizadas por usuários e revendedores'],
    'tickets' => ['label' => 'Suporte / Tickets', 'group' => 'Logs e Monitoramento', 'icon' => 'life-preserver', 'table' => 'tickets', 'subtitle' => 'Chamados e solicitações de suporte'],

    // Sistema
    'settings' => ['label' => 'Configurações Gerais', 'group' => 'Sistema', 'icon' => 'gear', 'table' => 'settings', 'subtitle' => 'Configurações gerais do engine'],
    'profiles' => ['label' => 'Perfis de Transcodificação', 'group' => 'Sistema', 'icon' => 'sliders', 'table' => 'profiles', 'subtitle' => 'Perfis utilizados na transcodificação'],
    'output_formats' => ['label' => 'Formatos de Saída', 'group' => 'Sistema', 'icon' => 'file-earmark-play', 'table' => 'output_formats', 'subtitle' => 'Formatos de streaming entregues aos clientes'],
    'access_codes' => ['label' => 'Códigos de Acesso', 'group' => 'Sistema', 'icon' => 'key', 'table' => 'access_codes', 'subtitle' => 'Códigos de acesso aos painéis e APIs'],
    'cronjobs' => ['label' => 'Tarefas Agendadas', 'group' => 'Sistema', 'icon' => 'clock', 'table' => 'crontab', 'subtitle' => 'Rotinas automáticas do sistema'],
    'signals' => ['label' => 'Comandos do Engine', 'group' => 'Sistema', 'icon' => 'send', 'table' => 'signals', 'subtitle' => 'Sinais e comandos internos do engine'],
];
