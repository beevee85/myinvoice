#!/usr/bin/env bash
set -euo pipefail

# cron-fio-bank.sh — stahovani bankovnich pohybu z Fio API
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$PROJECT_ROOT/log"
mkdir -p "$LOG_DIR"

exec php "$PROJECT_ROOT/api/bin/cron-fio-bank.php" "$@" \
    >> "$LOG_DIR/fio-bank-$(date +%Y-%m-%d).log" 2>&1
