# XDS Control — Runbook operacional

## Instalação/atualização

```bash
cd /opt/XC_VM
git fetch origin
git checkout agent/xds-control-foundation
git reset --hard origin/agent/xds-control-foundation

chmod +x xds-control/bin/xds-control-*
xds-control/bin/xds-control-install
xds-control/bin/xds-control-proxy
```

O serviço da aplicação escuta somente em `127.0.0.1:9080`. O proxy publica inicialmente em `9081`, evitando conflito com o XC_VM nas portas 80/443.

## Endpoints

- Painel: `http://IP:9081/`
- Health: `http://127.0.0.1:9080/health`
- Diagnóstico: `http://IP:9081/diagnostics`

## Logs

```bash
journalctl -u xds-control -f

tail -f /opt/XC_VM/xds-control/storage/logs/xds-control.log

tail -f /opt/XC_VM/xds-control/storage/logs/php-error.log

tail -f /var/log/nginx/xds-control-error.log
```

O log da aplicação usa uma linha JSON por evento, permitindo pesquisa com `jq`.

```bash
tail -1000 storage/logs/xds-control.log | jq -c 'select(.level == "error")'
```

## Verificações rápidas

```bash
systemctl is-active mariadb xc_vm xds-control nginx
curl -fsS http://127.0.0.1:9080/health | jq .
/home/xc_vm/bin/php/bin/php /opt/XC_VM/xds-control/bin/xds-control-check
```

## Diagnóstico em um comando

```bash
/opt/XC_VM/xds-control/bin/xds-control-doctor
```

O comando gera `/root/xds-control-diagnostic-AAAAMMDD-HHMMSS.tar.gz` com status, portas, processos, journal, módulos PHP, health check, esquema e logs. Senhas conhecidas do arquivo de credenciais são removidas antes da compactação, mas revise o pacote antes de compartilhá-lo.

## Recuperação automática do serviço

O systemd reinicia o painel quando o processo cai. Para reiniciar manualmente:

```bash
systemctl restart xds-control
journalctl -u xds-control -n 100 --no-pager
```

## Restaurar configuração

```bash
cp -a xds-control/config/config.php xds-control/config/config.php.bak.$(date +%F-%H%M%S)
chmod 600 xds-control/config/config.php
systemctl restart xds-control
```

## Segurança

- `xds_app` possui somente `SELECT` em `xc_vm.*`.
- O painel não oferece operações de escrita no engine nesta etapa.
- Todas as ações de login e navegação são registradas em `xds.audit_logs`.
- O acesso externo deve ser protegido por TLS e firewall antes de produção.
- Troque a senha compartilhada entre `root` e `xds_app` por senhas distintas.

## Estado da versão inicial

Implementado:

- autenticação própria em `xds.admin_users`;
- sessão segura e CSRF no login;
- dashboard com contadores do engine;
- leitura genérica de tabelas permitidas do XC_VM;
- auditoria;
- diagnóstico web e CLI;
- health check dos dois bancos;
- serviço systemd;
- proxy Nginx isolado;
- logs estruturados.

Próximas etapas:

- repositories tipados para cada domínio;
- paginação e pesquisa server-side;
- RBAC completo;
- gráficos históricos;
- ações controladas de escrita no engine;
- ferramentas exclusivas XDS.
