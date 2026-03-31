# Copilot instructions for tracker.rantojenmies.com

## Big picture architecture
- This is a PHP + MariaDB GPS tracker stack with **two ingestion paths**:
  - TCP daemon: `server_v3.php` receives raw tracker frames and writes rows into `Data` via `Data::InsertData()`.
  - HTTP API: `api/index.php` routes to `class/TrackerAPI.php` endpoints for UI data and updates.
- Processing is a 2-stage pipeline in `class/Data.php`:
  1) `ProcessData()` parses raw `Data.Input` into normalized `DataStaging` rows.
  2) `ProcessStaging()` calculates movement metrics, writes `DataArchive`, and attaches points to `Path`.
- `process.php` is the batch/orchestration script: process queued data, process staging, then close unfinished paths and regenerate KML.
- KML is **stored in DB** (`Path.Kml`, `Places.Kml`, `Events.Kml`) and served via `/cache/*` API endpoints; files are not written to disk.

## Core service boundaries
- Routing + HTTP method parsing + CORS is centralized in `class/API.php`.
- Domain classes:
  - `class/Path.php`: trip lifecycle, summary/group paths, KML generation.
  - `class/Place.php`: place metadata + KML + summary KML.
  - `class/Events.php`: event CRUD-like actions + event KML.
  - `class/Data.php`: ingest parse/normalize/archive math flow.
- DB access pattern is direct PDO per method via `new Mysql()` (`class/Mysql.php`), no ORM and no autoloader.

## API and frontend contracts
- Non-cache API endpoints require headers in `TrackerAPI::__construct()`:
  - `X-Api-Key` (must match configured key)
  - `X-Api-Imei` (device scope)
- Optional headers used by backend logic: `X-Api-Pathid`, `X-Api-Placeid`, `X-Api-Testmode`.
- Frontend (`loki/index.php` + `loki/js/*.js`) calls API with `corsRequest()` and these headers; keep this contract unchanged.
- Typical frontend write patterns:
  - `POST /place/<nameUrl>` with JSON body `{ "name": "...", "description": "..." }`.
  - `POST /path/<id>` with JSON body `{ "EngineHourMeter": <number> }`.
- Cache endpoints return non-JSON payloads (KML/image) by setting `$this->noJson = true` in `TrackerAPI::cache()`.

## Database behaviors you must preserve
- Schema/triggers in `db/rm_tracker_DB.sql` are part of business logic.
- `DataStaging_Update_LastUpdated` trigger can delete newer archive/path rows when out-of-order data arrives (`Devices.DeleteNewer` flow).
- `DataArchive_Update_LastUpdated` trigger updates `Devices.LastUpdated` and `LastPosition_Id`.
- `Path.Visible` is derived/maintained by KML generation logic (distance/duration thresholds); don’t bypass this in UI/API changes.

## Developer workflows (no build system)
- Run TCP ingest daemon: `php server_v3.php`
- Run batch processing manually: `php process.php`
- Run local web server for API/UI smoke checks: `php -S localhost:8000 -t .`
- Quick endpoint smoke test (PowerShell):
  - `curl "http://localhost:8000/api/index.php?request=init" -H "X-Api-Key: ..." -H "X-Api-Imei: ..."`

## Project-specific conventions
- Keep manual `require_once(...)` includes; this project does not use Composer autoload.
- Keep current SQL-first style (prepared statements + bound params) used in classes under `class/`.
- When changing place/path naming logic, preserve Finnish character normalization in `Place::GenerateKml()` / `UpdateInfo()` (`å/ä/ö` mapping).
- When changing KML fields, update both generator and list/read endpoints so menu dropdown URLs (`NameUrl`, `Url`) stay stable.
- Many paths are deployment-hardcoded (`/var/www/...`, absolute hostnames). If you introduce env-based config, do it consistently across `process.php`, `server_v3.php`, and `conf/config.php`.

## External integrations
- Navionics map JS API is used in `loki/js/map.js` and `loki/js/initMap.js`.
- Reverse geocoding uses Google Maps HTTP API in `Data::reverse_geocode()`.
- Tracker socket protocol expectations in `server_v3.php`:
  - Device handshake starting with `##` => respond `LOAD`
  - Position frames starting with `imei` => parse and respond `ON`
