# tracker.rantojenmies.com

GPS tracker backend and web UI built with PHP and MariaDB.

This project has two ingestion paths:
- TCP daemon for raw tracker frames (`server_v3.php`)
- HTTP API for app/UI actions (`api/index.php` -> `class/TrackerAPI.php`)

Processing is handled in two stages by `class/Data.php`:
1. `ProcessData()` parses raw frames from `Data` to `DataStaging`
2. `ProcessStaging()` calculates movement metrics, updates `DataArchive`, and links rows to `Path`

## Tech stack

- PHP
- MariaDB / MySQL
- PDO (no ORM)
- Legacy frontend under `loki/` (PHP + jQuery + OpenLayers/Navionics)

## Repository layout

- `server_v3.php`: TCP ingest daemon (tracker socket protocol)
- `process.php`: batch processing and KML regeneration orchestration
- `api/index.php`: API entrypoint
- `class/`: domain classes (`Data`, `Path`, `Place`, `Events`, `TrackerAPI`, etc.)
- `db/rm_tracker_DB.sql`: schema, keys, and triggers
- `scripts/run_tracker_process.sh`: production-friendly wrapper for `process.php`
- `loki/`: frontend app and map assets

## Prerequisites

- PHP 8.x with PDO MySQL extension
- MariaDB/MySQL
- Access to create database `rm_tracker`

## Configuration

The project reads runtime variables from environment variables.

1. Copy `.env.example` and define at least:
   - `TRACKER_DB_USER`
   - `TRACKER_DB_PASSWORD`
2. Optional useful variables:
   - `TRACKER_DEBUG=true|false`
   - `TRACKER_PROCESS_CHUNK_SIZE=10000`
   - `TRACKER_SOCKET_MAX_CLIENTS=100`
   - `TRACKER_SOCKET_MAX_FRAME_BYTES=500`
   - `TRACKER_SOCKET_MAX_RECORDS_PER_FRAME=25`
   - `TRACKER_ALLOWED_ORIGINS=https://tracker.rantojenmies.com,https://www.rantojenmies.com`
   - `TRACKER_ENABLE_JSONP=true|false`
   - `TRACKER_SHARED_SECRET=<required for API token validation>`

Notes:
- DB connection values are used by both `conf/config.php` and `api/conf/config.php`.
- Missing `TRACKER_DB_USER` or `TRACKER_DB_PASSWORD` will throw an exception at runtime.

## Database setup

Import schema and business-logic triggers:

```bash
mysql -u root -p < db/rm_tracker_DB.sql
```

The SQL file creates database `rm_tracker` if missing.

## Run locally

Serve API and UI:

```bash
php -S localhost:8000 -t .
```

Start TCP ingest daemon:

```bash
php server_v3.php
```

Run processing pipeline manually:

```bash
php process.php
```

## Scheduled processing (cron/systemd)

Use the wrapper script for better logging and env handling:

```bash
scripts/run_tracker_process.sh
```

Wrapper defaults:
- env file: `/etc/tracker/tracker.env`
- php binary: `/usr/bin/php`
- process script: `/var/www/tracker.rantojenmies.com/process.php`

Override with environment variables:
- `TRACKER_ENV_FILE`
- `TRACKER_PHP_BIN`
- `TRACKER_PROCESS_PHP`

## API quick start

Entry point:
- `GET/POST /api/index.php?request=<endpoint[/verb/...]>`

Auth headers (required for non-cache endpoints except `guid`):
- `X-Api-Token`
- `X-Api-Imei`

Optional headers:
- `X-Api-Pathid`
- `X-Api-Placeid`
- `X-Api-Testmode`

Common endpoints:
- `GET request=init`
- `GET request=path/list`
- `POST request=path/<id>` (JSON body)
- `POST request=place/<name-url>` (JSON body)
- `GET request=cache/live/<guid>`
- `GET request=cache/kml/<guid>`

Example local call:

```bash
curl "http://localhost:8000/api/index.php?request=init" \
  -H "X-Api-Token: <token>" \
  -H "X-Api-Imei: <imei>"
```

## TCP tracker protocol summary

Handled by `server_v3.php`:
- Frame starts with `##` -> server replies `LOAD`
- Frame starts with `imei` -> server stores records and replies `ON`

## Important behavior

- KML is stored in database fields (`Path.Kml`, `Places.Kml`, `Events.Kml`) and served via cache endpoints.
- Trigger logic in `db/rm_tracker_DB.sql` is part of business behavior; avoid bypassing it.
- Keep include style as `require_once(...)` (no Composer autoloader in this project).

## Security

See `SECURITY.md` for vulnerability reporting.