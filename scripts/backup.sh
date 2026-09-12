#!/bin/sh
# FNCRB nightly database backup (DR evidence for COBAC audits).
# Usage: sh scripts/backup.sh /path/to/backup/dir [mysql_user] [mysql_pass]
# Schedule daily via cron:  0 2 * * * sh /path/to/FNCRB/scripts/backup.sh /backups
set -e
DIR="${1:-./backups}"
USER="${2:-root}"
PASS="${3:-}"
mkdir -p "$DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
FILE="$DIR/fncrb-$STAMP.sql.gz"

mysqldump --single-transaction --routines --triggers \
  ${PASS:+--password="$PASS"} -u "$USER" fncrb | gzip > "$FILE"

# Retention: keep the last 30 backups
ls -1t "$DIR"/fncrb-*.sql.gz | tail -n +31 | xargs -r rm -f
echo "backup written: $FILE"
