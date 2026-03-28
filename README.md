# Ficker Backend

Backend Laravel do Ficker, com API financeira, camada analitica dedicada e integracao com Telegram.

Repositorio do frontend:

- `https://github.com/Johnviti/ficker-front`
ou
- `https://github.com/airtonssap/ficker-front`

## Stack

- PHP 8.1
- Laravel 10
- MySQL
- Docker

## Estrutura de configuracao

O projeto usa dois arquivos de ambiente com responsabilidades diferentes:

- `.env`
  - configuracao da aplicacao Laravel dentro do container
- `.env.compose`
  - configuracao usada pelo `docker compose`

Tanto o fluxo local quanto o fluxo do servidor usam `.env` como fonte principal de configuracao da aplicacao.

Para ambiente local, o projeto usa este modelo:

- `.env` como fonte principal de configuracao da aplicacao
- `.env.compose.local` como configuracao do compose local
- bootstrap automatico do backend via `docker/local/backend-entrypoint.sh`

Arquivos de referencia:

- [.env.example](./.env.example)
- [.env.compose.example](./.env.compose.example)
- [.env.compose.local.example](./.env.compose.local.example)
- [.env.testing.example](./.env.testing.example)

### Pre-requisitos locais

Voce precisa ter instalado na maquina:

- Docker Desktop ou Docker Engine com `docker compose`
- Git
- um clone local deste repositorio
- um clone local do repositorio do frontend

Hoje, o fluxo local tambem exige uma destas opcoes para a `APP_KEY`:

- PHP no host para rodar `php artisan key:generate --show` antes da primeira subida
- ou uma `APP_KEY` ja preenchida manualmente no `.env`

### Fluxo rapido local

1. copiar `.env.example` para `.env`
2. ajustar o `.env` para ambiente local
3. copiar `.env.compose.local.example` para `.env.compose.local`
4. preencher `FRONTEND_LOCAL_PATH` no `.env.compose.local` com o caminho do clone local do repositorio do frontend
5. subir:

```powershell
docker compose --env-file .env.compose.local -f docker-compose.local.yml up -d --build
```

### Preparacao local

#### 1. Criar `.env`

Copie o exemplo:

```powershell
Copy-Item .env.example .env
```

Ajuste pelo menos:

- `APP_ENV=local`
- `APP_DEBUG=true`
- `APP_URL=http://localhost:8000`
- `DB_CONNECTION=mysql`
- `DB_HOST=db`
- `DB_PORT=3306`
- `DB_DATABASE=ficker_local`
- `DB_USERNAME=ficker`
- `DB_PASSWORD=ficker123`
- `FRONTEND_URL=http://localhost:3000`
- `SANCTUM_STATEFUL_DOMAINS=`
- `TELEGRAM_ENABLED=false`

Se a `APP_KEY` estiver vazia, gere uma:

```bash
php artisan key:generate --show
```

Depois copie o valor para o `.env`.

#### 2. Criar `.env.compose.local`

Copie o exemplo:

```powershell
Copy-Item .env.compose.local.example .env.compose.local
```

Preencha pelo menos:

- `MYSQL_ROOT_PASSWORD`
- `MYSQL_DATABASE=ficker_local`
- `MYSQL_USER=ficker`
- `MYSQL_PASSWORD=ficker123`
- `BACKEND_PORT=8000`
- `FRONTEND_PORT=3000`
- `PHPMYADMIN_PORT=8081`
- `FRONTEND_LOCAL_PATH`

Exemplo no Windows:

```env
FRONTEND_LOCAL_PATH=C:/Users/seu-usuario/Documents/ficker-front
```

#### 3. Subir o ambiente local

```powershell
docker compose --env-file .env.compose.local -f docker-compose.local.yml up -d --build
```

### Validacao inicial local

Enderecos locais esperados:

- backend: `http://localhost:8000`
- frontend: `http://localhost:3000`
- phpMyAdmin: `http://localhost:8081`

## Setup do servidor com Docker

O fluxo do servidor segue o mesmo principio do local:

- `.env` e a fonte principal do runtime da aplicacao
- `.env.compose` fica focado na infraestrutura do Compose
- o backend sobe com bootstrap automatico:
  - espera o banco
  - gera `APP_KEY` se ela ainda estiver vazia no `.env`
  - limpa cache
  - roda migrations
  - aplica `BaseCatalogSeeder`

### Pre-requisitos do servidor

Voce precisa ter disponivel no servidor:

- Docker Engine com `docker compose`
- acesso ao repositorio do backend
- DNS apontando para o servidor
- portas `80`, `443` e `9090` liberadas

Para gerar `PMA_BASIC_AUTH`, voce precisa de uma destas opcoes:

- `htpasswd` instalado no host
- ou um hash bcrypt gerado por outra ferramenta confiavel, com os caracteres `$` escapados como `$$` no `.env.compose`

## Fluxo rapido do servidor

1. copiar `.env.compose.example` para `.env.compose`
2. copiar `.env.example` para `.env`
3. preencher `PMA_BASIC_AUTH`
4. validar:

```bash
docker compose --env-file .env.compose config
```

5. subir:

```bash
docker compose --env-file .env.compose up -d --build
```

6. validar a subida:

```bash
docker compose --env-file .env.compose ps
docker compose --env-file .env.compose logs --tail=100 backend
```

## Preparacao do servidor

### 1. Criar `.env.compose`

Copie o exemplo:

```powershell
Copy-Item .env.compose.example .env.compose
```

Campos principais:

- `LE_EMAIL`
- `API_HOST`
- `PMA_BASIC_AUTH`
- `MYSQL_ROOT_PASSWORD`
- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`

Observacoes:

- `API_HOST` deve ser o dominio principal da API, por exemplo `ficker-api.cloud`
- `PMA_BASIC_AUTH` deve usar hash bcrypt com `$$` escapado

### 2. Criar `.env`

Copie o exemplo:

```powershell
Copy-Item .env.example .env
```

Ajuste os campos conforme o ambiente:

- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `DB_*`
- `MAIL_*`
- `FRONTEND_URL`
- `TELEGRAM_*`

Observacao:

- a `APP_KEY` fica no `.env`
- se ela estiver vazia, o bootstrap do backend tenta gerá-la automaticamente na primeira subida

### 3. Gerar Basic Auth do phpMyAdmin

```bash
htpasswd -nB admin
```

O retorno sera algo como:

```text
admin:$2y$05$abc123...
```

Ao colocar no `.env.compose`, substitua cada `$` por `$$`.

Exemplo:

```env
PMA_BASIC_AUTH=admin:$$2y$$05$$abc123...
```

## Subir o servidor

### 4. Conferir a configuracao inicial do Compose

Antes de subir os containers, valide a configuracao:

```bash
docker compose --env-file .env.compose config
```

Se aparecer variavel vazia, faltou preencher algo no `.env.compose`.

### 5. Subir os containers

```bash
docker compose --env-file .env.compose up -d --build
```

## Validacao inicial do servidor

### 6. Verificar se os containers estao ativos

```bash
docker compose --env-file .env.compose ps
```

Para ver logs:

```bash
docker compose --env-file .env.compose logs --tail=100
```

Ou por servico:

```bash
docker compose --env-file .env.compose logs --tail=100 traefik
docker compose --env-file .env.compose logs --tail=100 backend
docker compose --env-file .env.compose logs --tail=100 phpmyadmin
docker compose --env-file .env.compose logs --tail=100 db
```

Ao acessar o phpMyAdmin:

- o navegador pedira o Basic Auth configurado no Traefik
- dentro do phpMyAdmin, use as credenciais do MySQL


## Checklist final

- `.env.compose` criado e preenchido
- `.env` criado e preenchido
- `APP_KEY` presente no `.env` ou gerada automaticamente no bootstrap
- DNS apontando para o servidor
- portas `80`, `443` e `9090` liberadas
- `docker compose --env-file .env.compose config` sem variaveis vazias
- containers ativos
- backend buildado
- migrations executadas pelo bootstrap
- seed base executado pelo bootstrap
- API respondendo em HTTPS
- phpMyAdmin acessivel em HTTPS na porta `9090`
