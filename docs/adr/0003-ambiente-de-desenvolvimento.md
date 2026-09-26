# 0003: Desenvolvimento sem Docker; migrations centralizadas

- **Status:** aceito (2026-09-25).
- **Contexto:** a máquina de desenvolvimento não tem Docker, PostgreSQL nem Redis. O PHP local é 8.2, em `C:\tools\php82`. As extensões `pdo_sqlite`, `sqlite3`, `soap` e `intl` foram habilitadas no `php.ini`; há um backup em `php.ini.bak`.
- **Decisão:**
  - **Desenvolvimento:** SQLite local, testes em SQLite `:memory:`, fila `sync`.
  - **Produção:** PostgreSQL 16 + Redis 7. O `docker-compose.yml` já está pronto.
  - **PHP:** mínimo `^8.2`, e não 8.3 como dizia a spec. É o mínimo do Laravel 12 e o que o Mecânica Pro usa.
  - **Migrations:** ficam em `database/migrations/`, e não por módulo, para ter uma linha do tempo única entre módulos.
- **Consequências:**
  - Recursos específicos do PostgreSQL (índices parciais, `jsonb`, RLS) não são exercitados nos testes locais.
  - Quando uma fase precisar deles, ela deve adicionar um job de CI com um serviço PostgreSQL.
