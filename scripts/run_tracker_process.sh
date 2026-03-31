#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${TRACKER_ENV_FILE:-/etc/tracker/tracker.env}"
PHP_BIN="${TRACKER_PHP_BIN:-/usr/bin/php}"
PROCESS_PHP="${TRACKER_PROCESS_PHP:-/var/www/tracker.rantojenmies.com/process.php}"
START_TS=$(date +%s)
APP_ROOT="$(dirname "$PROCESS_PHP")"
REMAIN_BEFORE=""
REMAIN_AFTER=""

log() {
  echo "[$(date '+%Y-%m-%dT%H:%M:%S%z')] [tracker-process] $*"
}

get_remaining_staging() {
  local value

  value=$("$PHP_BIN" -r 'chdir($argv[1]); require_once("class/Mysql.php"); require_once("class/Data.php"); $d = new Data(); echo (int)$d->GetUnprocessedStagingCount();' "$APP_ROOT" 2>/dev/null || true)

  if [[ "$value" =~ ^[0-9]+$ ]]; then
    echo "$value"
  else
    echo ""
  fi
}

on_exit() {
  local exit_code=$?
  local end_ts
  local duration
  local processed
  local rpm
  end_ts=$(date +%s)
  duration=$((end_ts - START_TS))

  if [[ "$REMAIN_BEFORE" =~ ^[0-9]+$ ]] && [[ "$REMAIN_AFTER" =~ ^[0-9]+$ ]]; then
    processed=$((REMAIN_BEFORE - REMAIN_AFTER))
    if (( processed < 0 )); then
      processed=0
    fi

    if (( duration > 0 )); then
      rpm=$((processed * 60 / duration))
      log "SUMMARY remaining_before=${REMAIN_BEFORE} remaining_after=${REMAIN_AFTER} processed=${processed} rows_per_min=${rpm}"
    else
      log "SUMMARY remaining_before=${REMAIN_BEFORE} remaining_after=${REMAIN_AFTER} processed=${processed}"
    fi
  fi

  log "END exit=${exit_code} duration=${duration}s"
}

trap on_exit EXIT

if [[ ! -f "$ENV_FILE" ]]; then
  log "ERROR missing env file: ${ENV_FILE}"
  exit 1
fi

set -a
source "$ENV_FILE"
set +a

if [[ -z "${TRACKER_DB_USER:-}" || -z "${TRACKER_DB_PASSWORD:-}" ]]; then
  log "ERROR missing required vars in env file (TRACKER_DB_USER/TRACKER_DB_PASSWORD)"
  exit 1
fi

if [[ ! -x "$PHP_BIN" ]]; then
  log "ERROR php binary not executable: ${PHP_BIN}"
  exit 1
fi

if [[ ! -f "$PROCESS_PHP" ]]; then
  log "ERROR process file not found: ${PROCESS_PHP}"
  exit 1
fi

REMAIN_BEFORE="$(get_remaining_staging)"

if "$PHP_BIN" -f "$PROCESS_PHP"; then
  PROCESS_EXIT=0
else
  PROCESS_EXIT=$?
fi

REMAIN_AFTER="$(get_remaining_staging)"

exit "$PROCESS_EXIT"
