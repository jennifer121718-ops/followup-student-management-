#!/bin/bash
set -euo pipefail
cd /workspace/followup-student-management-
check_php() {
  "$1" -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1
}
if command -v php >/dev/null && check_php "$(command -v php)"; then
  exit 0
fi
if [ -x /workspace/.followup-php/php ] && check_php /workspace/.followup-php/php; then
  exit 0
fi
[ "$(uname -m)" = x86_64 ] || { echo 'Install PHP 8.1+ with PDO SQLite for this architecture.' >&2; exit 1; }
command -v docker >/dev/null
runtime_image='php@sha256:aafe21201943a8a6e497ddfb471e2ce68ada76adede38c7d61fede8918b24319'
runtime_container="followup-php-setup-$$"
docker --config /tmp/followup-docker pull --platform linux/amd64 "$runtime_image"
docker create --platform linux/amd64 --name "$runtime_container" "$runtime_image" >/dev/null
trap 'docker rm "$runtime_container" >/dev/null' EXIT
mkdir -p /workspace/.followup-php/bin /workspace/.followup-php/lib
docker cp "$runtime_container":/usr/local/bin/php /workspace/.followup-php/bin/php
docker cp -L "$runtime_container":/lib/x86_64-linux-gnu/libargon2.so.1 /workspace/.followup-php/lib/libargon2.so.1
cat > /workspace/.followup-php/php <<'PHPWRAPPER'
#!/bin/sh
exec /lib64/ld-linux-x86-64.so.2 --library-path /workspace/.followup-php/lib /workspace/.followup-php/bin/php -n "$@"
PHPWRAPPER
chmod +x /workspace/.followup-php/php
check_php /workspace/.followup-php/php
