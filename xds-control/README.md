# XDS Control

Painel administrativo independente para o engine XC_VM.

## Regra de dados

- `xc_vm`: banco original do engine. O XDS não altera seu schema.
- `xds`: banco próprio do painel, usado para autenticação, permissões, auditoria, preferências e tarefas.

O primeiro estágio é estritamente somente leitura no banco `xc_vm`.

## Estrutura

```text
xds-control/
├── config/config.example.php
├── database/001_initial_schema.sql
├── public/index.php
├── src/Database/ConnectionManager.php
├── src/Engine/EngineHealthRepository.php
└── bin/xds-control-check
```

## Instalação de laboratório

```bash
cd /opt/XC_VM/xds-control
cp config/config.example.php config/config.php
nano config/config.php

mariadb < database/001_initial_schema.sql
chmod +x bin/xds-control-check
./bin/xds-control-check
```

O diagnóstico deve confirmar:

- conexão ao MariaDB;
- acesso somente leitura ao banco `xc_vm`;
- acesso completo ao banco `xds`;
- presença das tabelas iniciais do XDS;
- versão e estrutura básica do engine.

## Segurança

Nunca use o usuário `root` do MariaDB no painel. Crie um usuário dedicado:

```sql
CREATE USER 'xds_app'@'127.0.0.1' IDENTIFIED BY 'ALTERE_ESTA_SENHA';
GRANT SELECT ON xc_vm.* TO 'xds_app'@'127.0.0.1';
GRANT ALL PRIVILEGES ON xds.* TO 'xds_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Permissões de escrita no `xc_vm` serão concedidas por tabela somente quando cada módulo estiver implementado e testado.
