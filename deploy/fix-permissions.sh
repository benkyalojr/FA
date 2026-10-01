#!/bin/sh
# Make the folders FrontAccounting writes to belong to the PHP-FPM user.
# Run on the server as root:   sudo sh deploy/fix-permissions.sh [/var/www/bentito/snaperp] [www-data]
#
# Symptoms this fixes: "fopen(../company/0/js_cache/1/JsHttpRequest.js): Permission denied",
# reports that produce no PDF, failed logo/attachment uploads, backups that cannot be written.
# It happens when a folder was created by another user (for example by `git pull` or a
# migration run as your own login) and PHP-FPM (www-data) cannot write into it.
set -e
ROOT="${1:-/var/www/bentito/snaperp}"
WEB="${2:-www-data}"

cd "$ROOT"
for d in company/0 company/0/images company/0/pdf_files company/0/backup company/0/js_cache company/0/attachments company/0/reporting tmp backups; do
	mkdir -p "$d"
done

# Folders PHP writes to: owned by the PHP user, group-writable.
chown -R "$WEB":"$WEB" company tmp backups
find company tmp backups -type d -exec chmod 775 {} \;
find company tmp backups -type f -exec chmod 664 {} \;

# Everything else stays owned by the deploy user and read-only to PHP.
# Only during installation does the project root need to be writable (config_db.php).
echo "Done. PHP user '$WEB' can now write to company/, tmp/ and backups/ under $ROOT"
