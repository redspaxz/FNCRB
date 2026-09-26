#!/bin/sh
# FNCRB nightly database backup (DR evidence for COBAC audits).
# Usage: sh scripts/backup.sh /path/to/backup/dir [mysql_user] [database]
# The password is read from $MYSQL_PWD or ~/.my.cnf — never passed on the
# command line (it would be visible to other users in the process list).
# Optional: set FNCRB_BACKUP_GPG_RECIPIENT to encrypt each dump with GnuPG.
# Schedule daily via cron:  0 2 * * * MYSQL_PWD=... sh /path/to/FNCRB/scripts/backup.sh /backups
set -eu
DIR="${1:-./backups}"
USER="${2:-root}"
DB="${3:-fncrb}"
umask 077
mkdir -p "$DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
FILE="$DIR/fncrb-$STAMP.sql.gz"

mysqldump --single-transaction --routines --triggers -u "$USER" "$DB" | gzip > "$FILE"
if [ -n "${FNCRB_BACKUP_GPG_RECIPIENT:-}" ]; then
  gpg --batch --yes --recipient "$FNCRB_BACKUP_GPG_RECIPIENT" --encrypt "$FILE" && rm -f "$FILE"
  FILE="$FILE.gpg"
fi
sha256sum "$FILE" > "$FILE.sha256"

# Retention: keep the last 30 backups
ls -1t "$DIR"/fncrb-*.sql.gz* 2>/dev/null | grep -v '\.sha256$' | tail -n +31 | while read -r old; do rm -f "$old" "$old.sha256"; done
echo "backup written: $FILE"
