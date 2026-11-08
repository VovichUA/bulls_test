#!/usr/bin/env bash
# Native bootstrap for a dedicated Ubuntu VM; not an online deployment updater.
set +x
set -Eeuo pipefail
umask 0077

usage() {
    cat <<'HELP'
Usage: sudo ./setup.sh [--server-name HOST] [--admin-file /root/bulls-admin.json]
Run from a trusted checkout at /var/www/bulls on Ubuntu 22.04/24.04 with systemd.
Without --admin-file, administrator details are prompted (password hidden).
The JSON file must be root-owned0600 and contain email, name, password strings.
A blank password is allowed on reruns only when the seeded administrator exists.
No existing DB/user or unmanaged .env is adopted. Never run on your laptop OS.
HELP
}
fail() { printf '%s\n' "ERROR: $*" >&2; exit 1; }
log() { printf '%s\n' "==> $*"; }
server_name=localhost
admin_file=''
while (($#)); do
    case "$1" in
        --help|-h) usage; exit 0 ;;
        --server-name|--admin-file)
            (($# >= 2)) || fail "Missing value for $1"
            case "$1" in --server-name) server_name=$2 ;; --admin-file) admin_file=$2 ;; esac
            shift 2 ;;
        *) fail "Unknown option: $1" ;;
    esac
done
[[ $server_name =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ && ${#server_name} -le 253 ]] || fail 'Invalid server name (no scheme, port or shell characters).'
repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
[[ $repo == /var/www/bulls ]] || fail 'Clone this project at /var/www/bulls on a dedicated VM first.'
[[ -f /etc/os-release ]] || fail 'Ubuntu is required.'
# /etc/os-release is OS-owned data, never an administrator secrets file.
# shellcheck disable=SC1091
. /etc/os-release
[[ $ID == ubuntu && ( $VERSION_ID == 22.04 || $VERSION_ID == 24.04 ) ]] || fail 'Only Ubuntu22.04/24.04 LTS are supported.'
[[ -d /run/systemd/system ]] || fail 'A VM with running systemd is required; plain Docker/WSL is not supported.'
if ((EUID != 0)); then
    command -v sudo >/dev/null || fail 'Run as root or install sudo.'
    args=(--server-name "$server_name")
    [[ -z $admin_file ]] || args+=(--admin-file "$admin_file")
    exec sudo -- "$repo/setup.sh" "${args[@]}"
fi
[[ -f $repo/src/composer.lock && -f $repo/src/package-lock.json ]] || fail 'Both dependency lock files are required.'
[[ ! -L $repo/src/.env ]] || fail '.env must not be a symlink.'
# shellcheck source=deploy/setup-filesystem.sh
. "$repo/deploy/setup-filesystem.sh"
bulls_check_enabled_site /etc/nginx/sites-enabled/bulls /etc/nginx/sites-available/bulls || fail 'An unrelated enabled nginx site named bulls already exists.'
first_run=1
if [[ -e $repo/src/.env ]]; then
    [[ $(head -n 1 "$repo/src/.env") == '# bulls-ubuntu-setup:v1' ]] || fail 'Unmanaged .env found; use a clean VM checkout.'
    first_run=0
fi
exec 9>/run/lock/bulls-setup.lock
flock -n 9 || fail 'Another setup is running.'
# Refuse foreign config before any managed-file removal.
for file in /etc/cron.d/bulls-sheets /etc/supervisor/conf.d/bulls-sheets.conf /etc/nginx/sites-available/bulls /etc/php/8.4/fpm/pool.d/bulls.conf /etc/systemd/system/php8.4-fpm.service.d/bulls-umask.conf; do
    if [[ -e $file ]]; then
        grep -q 'Managed by bulls setup v1' "$file" || fail "Unmanaged configuration exists: $file"
    fi
done
rm -f /etc/cron.d/bulls-sheets
if supervisorctl status bulls-sheets-worker >/dev/null 2>&1; then
    supervisorctl stop bulls-sheets-worker
fi
if [[ -f /etc/supervisor/conf.d/bulls-sheets.conf ]]; then
    sed -i 's/^autostart=true$/autostart=false/' /etc/supervisor/conf.d/bulls-sheets.conf
fi

tmp=$(mktemp -d /tmp/bulls-setup.XXXXXXXX)
success=0
cleanup() {
    local result=$?
    if ((success == 0)) && [[ -x /usr/bin/php8.4 && -f $repo/src/vendor/autoload.php && -f $repo/src/.env ]]; then
        /usr/bin/php8.4 "$repo/deploy/setup-config.php" clean-secret >/dev/null 2>&1 || result=1
    fi
    if ((success == 0)); then
        rm -f /etc/cron.d/bulls-sheets
        if command -v supervisorctl >/dev/null; then supervisorctl stop bulls-sheets-worker >/dev/null 2>&1 || true; fi
        # Do not leave a cached transient password after interrupted initialization.
        rm -f "$repo/src/bootstrap/cache/config.php"
        printf '%s\n' 'Setup did not finish. Managed automation is stopped; correct the error and rerun.' >&2
    fi
    rm -rf -- "$tmp"
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

log 'Installing system packages (no full system upgrade).'
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common ca-certificates curl git unzip xz-utils openssl nginx mysql-server supervisor cron
if ! LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php || ! apt-get update; then
    fail 'Cannot reach the PHP PPA. Check DNS, HTTPS/Launchpad access and apt output; no package signature checks were disabled.'
fi
apt-get install -y php8.4-cli php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
/usr/bin/php8.4 -r 'exit(PHP_VERSION_ID >= 80401 && extension_loaded("pcntl") ? 0 : 1);' || fail 'PHP>=8.4.1 and pcntl are required.'
systemctl enable --now mysql supervisor cron
mysql --protocol=socket -uroot --batch --skip-column-names -e 'SELECT 1' >/dev/null || fail 'MySQL root socket authentication is required on this clean VM.'
if ((first_run)); then
    existing=$(mysql --protocol=socket -uroot --batch --skip-column-names -e "SELECT (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='bulls') + (SELECT COUNT(*) FROM mysql.user WHERE user='bulls_app');")
    [[ $existing == 0 ]] || fail 'The bulls database or bulls_app account already exists; refusing to adopt or reset it.'
fi
id bulls >/dev/null 2>&1 || useradd --system --create-home --home-dir /var/lib/bulls --shell /usr/sbin/nologin bulls
usermod -a -G www-data bulls
app=$repo/src
# This explicit VM checkout becomes managed; do not apply this to an existing installation.
chown -R bulls:www-data "$app"
for runtime in storage/app/private storage/app/public storage/framework/cache/data storage/framework/cache/locks storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    install -d -o bulls -g www-data -m 2770 "$app/$runtime"
done
install -d -o root -g root -m 0755 /opt/bulls
fetch() { curl --proto '=https' --proto-redir '=https' --tlsv1.2 --fail --show-error --silent --location --retry 3 "$@"; }
if [[ ! -f /opt/bulls/composer.phar ]]; then
    log 'Installing verified Composer2.'
    fetch https://composer.github.io/installer.sig -o "$tmp/installer.sig"
    fetch https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    [[ $(sha384sum "$tmp/composer-setup.php" | cut -d ' ' -f 1) == "$(cat "$tmp/installer.sig")" ]] || fail 'Composer installer checksum mismatch.'
    /usr/bin/php8.4 "$tmp/composer-setup.php" --2 --quiet --install-dir=/opt/bulls --filename=composer.phar
fi
chmod 0755 /opt/bulls/composer.phar
log 'Installing production PHP dependencies as bulls.'
# Shell variables below belong to the child shell, not this installer.
# shellcheck disable=SC2016
run_app() { bulls_clean_exec /usr/sbin/runuser -u bulls -- /bin/sh -c 'umask 0002; cd "$1" || exit; shift; exec "$@"' sh "$app" "$@"; }
run_app /usr/bin/php8.4 /opt/bulls/composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction
run_app /usr/bin/php8.4 /opt/bulls/composer.phar check-platform-reqs --no-dev

if [[ ! -x /opt/bulls/node/bin/node ]]; then
    log 'Installing official Node22 (minimum22.12), with SHA256 verification.'
    case $(dpkg --print-architecture) in amd64) node_arch=x64 ;; arm64) node_arch=arm64 ;; *) fail 'Node binaries support amd64/arm64 in this installer.' ;; esac
    fetch https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt -o "$tmp/SHASUMS256.txt"
    match=$(awk -v arch="$node_arch" '$2 ~ ("^node-v22\\.[0-9]+\\.[0-9]+-linux-" arch "\\.tar\\.xz$") {print $1 " " $2}' "$tmp/SHASUMS256.txt")
    [[ $(printf '%s\n' "$match" | wc -l) == 1 && -n $match ]] || fail 'Cannot select an official Node22 archive.'
    read -r checksum archive <<< "$match"
    [[ $checksum =~ ^[a-f0-9]{64}$ && $archive =~ ^node-v22\.[0-9]+\.[0-9]+-linux-(x64|arm64)\.tar\.xz$ ]] || fail 'Invalid Node checksum manifest.'
    fetch "https://nodejs.org/dist/latest-v22.x/$archive" -o "$tmp/$archive"
    (cd "$tmp" && printf '%s  %s\n' "$checksum" "$archive" | sha256sum --check --status) || fail 'Node checksum mismatch.'
    install -d -m 0755 /opt/bulls/node
    tar -xJf "$tmp/$archive" --strip-components=1 -C /opt/bulls/node
fi
/opt/bulls/node/bin/node -e 'const [a,b]=process.versions.node.split(".").map(Number); process.exit(a===22&&b>=12?0:1)' || fail 'Node22>=22.12 is required; replace /opt/bulls/node with a compatible installation.'
log 'Building frontend as bulls.'
run_app /usr/bin/env PATH=/opt/bulls/node/bin:/usr/local/bin:/usr/bin:/bin npm ci --ignore-scripts --no-audit
run_app /usr/bin/env PATH=/opt/bulls/node/bin:/usr/local/bin:/usr/bin:/bin npm run build

if [[ -z $admin_file ]]; then
    [[ -t 0 ]] || fail 'Noninteractive setup requires --admin-file pointing to root-owned0600 JSON.'
    read -r -p 'Administrator email (same on reruns): ' admin_email
    read -r -p 'Administrator name: ' admin_name
    read -r -s -p 'Administrator password (12+ chars; blank only for an existing admin): ' admin_password
    printf '\n'
    admin_file=$tmp/admin.json
    printf '%s\0%s\0%s' "$admin_email" "$admin_name" "$admin_password" | /usr/bin/php8.4 "$repo/deploy/setup-config.php" credentials "$admin_file"
    unset admin_password
fi
openssl rand -hex 32 | /usr/bin/php8.4 "$repo/deploy/setup-config.php" env "$server_name" "$admin_file"
chown bulls:www-data "$app/.env"
chmod 0640 "$app/.env"
# SQL goes directly to MySQL stdin; passwords never appear in command arguments/logs.
/usr/bin/php8.4 "$repo/deploy/setup-config.php" sql | mysql --protocol=socket -uroot
run_app /usr/bin/php8.4 artisan config:clear --no-interaction
if /usr/bin/php8.4 "$repo/deploy/setup-config.php" key-needed; then
    run_app /usr/bin/php8.4 artisan key:generate --force --no-interaction
fi
run_app /usr/bin/php8.4 artisan migrate --force --no-interaction
run_app /usr/bin/php8.4 "$repo/deploy/setup-config.php" bootstrap
if [[ ! -e $app/public/storage && ! -L $app/public/storage ]]; then
    run_app /usr/bin/php8.4 artisan storage:link --no-interaction
fi
# Group inheritance plus umask and log handler permissions support both writers.
find "$app/storage" "$app/bootstrap/cache" -type d -exec chmod 2770 {} +
find "$app/storage" "$app/bootstrap/cache" -type f -exec chmod 0660 {} +
chown -R bulls:www-data "$app/storage" "$app/bootstrap/cache"
chown bulls:www-data "$app/.env"
chmod 0640 "$app/.env"
if [[ -f $app/storage/app/private/google-service-account.json ]]; then chmod 0640 "$app/storage/app/private/google-service-account.json"; fi

log 'Validating and enabling web services.'
for kind in nginx fpm fpm-umask supervisor cron; do
    /usr/bin/php8.4 "$repo/deploy/setup-config.php" template "$server_name" "$kind" > "$tmp/$kind"
done
install -d -m 0755 /etc/systemd/system/php8.4-fpm.service.d
install -o root -g root -m 0644 "$tmp/fpm" /etc/php/8.4/fpm/pool.d/bulls.conf
install -o root -g root -m 0644 "$tmp/fpm-umask" /etc/systemd/system/php8.4-fpm.service.d/bulls-umask.conf
/usr/sbin/php-fpm8.4 --test
install -o root -g root -m 0644 "$tmp/nginx" /etc/nginx/sites-available/bulls
ln -sfn /etc/nginx/sites-available/bulls /etc/nginx/sites-enabled/bulls
nginx -t
systemctl daemon-reload
systemctl enable --now php8.4-fpm nginx
systemctl restart php8.4-fpm
systemctl reload nginx
bulls_wait_http "$server_name" http://127.0.0.1/up || fail 'The /up HTTP smoke check failed.'
# Root owns code after Composer/build; only runtime directories remain writable.
bulls_harden_code "$app" root:www-data
install -o root -g root -m 0644 "$tmp/supervisor" /etc/supervisor/conf.d/bulls-sheets.conf
supervisorctl reread
supervisorctl update
supervisorctl start bulls-sheets-worker >/dev/null 2>&1 || supervisorctl status bulls-sheets-worker | grep -q RUNNING
install -o root -g root -m 0644 "$tmp/cron" /etc/cron.d/bulls-sheets
credentials_path=$(/usr/bin/php8.4 "$repo/deploy/setup-config.php" google-path)
success=1
log "Ready: http://$server_name/login"
printf 'Place your JSON key at %s\n' "$credentials_path"
printf '%s\n' 'Set owner bulls:www-data and mode0640. Share the Google spreadsheet with its Service Account email.' 'Status: sudo supervisorctl status bulls-sheets-worker' 'This bootstrap is not an online upgrade tool. Stop imports before rerunning.'
