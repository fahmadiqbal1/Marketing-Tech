# Autonomous Business Operations Platform — Deployment Guide

## What is included in this repository

This repository now includes a runnable Docker Compose stack for:

- `app` — PHP-FPM Laravel application container
- `nginx` — HTTP entrypoint on port `8080`
- `horizon` — Laravel Horizon queue workers
- `scheduler` — Laravel scheduler loop
- `postgres` — PostgreSQL with pgvector image
- `redis` — cache / queue backend
- `minio` — S3-compatible object storage
- `clamav` — malware scanning daemon

## Prerequisites

- Docker Engine 24+
- Docker Compose 2.20+
- OpenAI / Anthropic / Telegram credentials if you want live integrations

## Quick start

### 1. Configure the environment

```bash
cp .env.example .env
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
# paste the generated key into APP_KEY in .env
```

### 2. Start the platform

```bash
docker compose up -d --build
```

The application UI is then available at:

- Dashboard: `http://localhost:8080/dashboard`
- Health endpoint: `http://localhost:8080/health`
- Horizon: `http://localhost:8080/horizon`
- MinIO console: `http://localhost:9001`

### 3. Install dependencies and prepare the database

```bash
docker compose exec app composer install
docker compose exec app php artisan migrate --seed
```

### 4. Optional Telegram webhook registration

```bash
docker compose exec app php artisan telegram:webhook
```

## Local non-Docker development

If your host PHP runtime does not include `pdo_pgsql`, the app now falls back to `DB_FALLBACK_CONNECTION` for booting dashboard routes without throwing HTTP 500 errors. That fallback is intended for local diagnostics only; full workflow, queue, and reporting functionality still requires the primary database schema to be migrated.

## Operational notes

- `QUEUE_CONNECTION=redis` is the default local/runtime path and matches Horizon.
- If you intentionally use `QUEUE_CONNECTION=database`, the queue connection is now defined in `config/queue.php`.
- Dashboard APIs return degraded but non-fatal payloads when the database is unavailable, so the UI remains usable during bring-up.
- The `/health` route returns HTTP `503` with a degraded payload instead of crashing when the database is unavailable.
