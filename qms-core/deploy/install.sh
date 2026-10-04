#!/usr/bin/env bash
# =============================================================================
#  QMS - Paperless Quality Inspection System (plain PHP edition)
#  One-command installer / updater for a fresh Ubuntu 24.04 LTS (or 22.04) VM
# =============================================================================
#
#  Fresh install (public DNS name pointing to this server, Let's Encrypt TLS):
#    sudo bash deploy/install.sh --domain qms.example.com --email it@example.com
#
#  Fresh install without a DNS name yet (self-signed certificate on the IP):
#    sudo bash deploy/install.sh --domain 203.0.113.10 --self-signed
#
#  Update to a newer package (run from the NEW extracted package):
#    sudo bash deploy/install.sh --update
#
#  Options:
#    --domain NAME       Host name (or IP with --self-signed) users open in the browser
#    --email ADDRESS     Contact address for Let's Encrypt expiry notices
#    --self-signed       Self-signed certificate instead of Let's Encrypt
#    --demo              Load DEMO master data and templates (never on a real plant DB)
#    --app-dir DIR       Installation directory (default /var/www/qms)
#    --db-name NAME      MySQL database name (default qms)
#    --admin USERNAME    First Super Admin login name (default admin)
#    --no-firewall       Do not configure UFW (e.g. when a cloud firewall is used)
#    --skip-packages     Do not run apt (packages already installed)
#    --update            Update an existing installation (backup, code, migrations)
#
#  The installer is safe to run again: it keeps the database, uploaded files,
#  generated passwords and certificates.
#
#  What it sets up: nginx (HTTPS only, HSTS), PHP-FPM pool running as the
#  unprivileged user "qms", MySQL 8 with three least-privilege accounts
#  (qms_app / qms_migrator / qms_backup), cron jobs (Google Sheets sync,
#  housekeeping, nightly backup), log rotation and the UFW firewall.
# =============================================================================
set -Eeuo pipefail
umask 027

APP_DIR=/var/www/qms
APP_USER=qms
DB_NAME=qms
DOMAIN=""
EMAIL=""
ADMIN_USER=admin
SELF_SIGNED=0
DEMO=0
FIREWALL=1
SKIP_PACKAGES=0
UPDATE=0
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS_DIR=/etc/qms/secrets
DB_SECRETS="$SECRETS_DIR/database.env"
SUMMARY=()

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }
trap 'die "Installation stopped at line $LINENO. Fix the problem above and run the installer again (it is safe to re-run)."' ERR

usage() { sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)        DOMAIN="${2:-}"; shift 2 ;;
        --email)         EMAIL="${2:-}"; shift 2 ;;
        --self-signed)   SELF_SIGNED=1; shift ;;
        --demo)          DEMO=1; shift ;;
        --app-dir)       APP_DIR="${2%/}"; shift 2 ;;
        --db-name)       DB_NAME="${2:-}"; shift 2 ;;
        --admin)         ADMIN_USER="${2:-}"; shift 2 ;;
        --no-firewall)   FIREWALL=0; shift ;;
        --skip-packages) SKIP_PACKAGES=1; shift ;;
        --update)        UPDATE=1; shift ;;
        -h|--help)       usage ;;
        *) die "Unknown option: $1 (see --help)" ;;
    esac
done

# ----------------------------------------------------------------------------- checks
[[ $EUID -eq 0 ]] || die "Run the installer as root: sudo bash deploy/install.sh ..."
[[ -f "$SRC_DIR/bin/qms.php" && -d "$SRC_DIR/app" && -d "$SRC_DIR/vendor" && -f "$SRC_DIR/public/assets/vendor/bootstrap/css/bootstrap.min.css" ]] \
    || die "Run the installer from the extracted QMS release package (bin/qms.php, app/, vendor/ and public/assets/vendor/ must be next to deploy/)."
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,48}$ ]] || die "--db-name may contain letters, digits and underscores only."
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{3,50}$ ]] || die "--admin must be 3-50 characters: letters, digits, dot, dash, underscore."

if [[ $UPDATE -eq 1 ]]; then
    [[ -f "$APP_DIR/.env" ]] || die "No installation found in $APP_DIR (missing .env). Run a fresh install first."
    [[ -f "$DB_SECRETS" ]] || die "$DB_SECRETS is missing; cannot update without the database credentials."
    # shellcheck source=/dev/null
    source "$DB_SECRETS"
    DB_NAME="${QMS_DB_NAME:-$DB_NAME}"
    DOMAIN="${QMS_DOMAIN:-$DOMAIN}"
    SKIP_PACKAGES=1
    FIREWALL=0
else
    [[ -n "$DOMAIN" ]] || die "--domain is required (host name, or the server IP together with --self-signed)."
    [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || die "--domain must be a host name or IPv4 address."
    if [[ $SELF_SIGNED -eq 0 && -z "$EMAIL" ]]; then
        die "--email is required for Let's Encrypt (or use --self-signed)."
    fi
fi

if [[ -r /etc/os-release ]]; then
    # shellcheck source=/dev/null
    . /etc/os-release
    [[ "${ID:-}" == "ubuntu" ]] || warn "Tested on Ubuntu 22.04 / 24.04 LTS; this is ${PRETTY_NAME:-unknown}."
fi

has_systemd() { [[ -d /run/systemd/system ]]; }
svc() {   # svc <action> <service>
    if has_systemd; then systemctl "$1" "$2"; else service "$2" "$1"; fi
}
svc_enable() {
    if has_systemd; then systemctl enable --now "$1" >/dev/null; else service "$1" start >/dev/null 2>&1 || service "$1" restart; fi
}
mysql_root() { mysql --protocol=socket -uroot "$@"; }
qms() { runuser -u "$APP_USER" -- php "$APP_DIR/bin/qms.php" "$@"; }
gen_password() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32; }
render() {  # render <template> <destination>
    sed -e "s#__DOMAIN__#${DOMAIN}#g" -e "s#__APP_DIR__#${APP_DIR}#g" -e "s#__APP_USER__#${APP_USER}#g" \
        -e "s#__SSL_CERT__#${SSL_CERT:-}#g" -e "s#__SSL_KEY__#${SSL_KEY:-}#g" "$1" > "$2"
    # Servers without IPv6 cannot open [::] listeners.
    [[ -f /proc/net/if_inet6 ]] || sed -i '/listen \[::\]/d' "$2"
}

# ----------------------------------------------------------------------------- 1. packages
if [[ $SKIP_PACKAGES -eq 0 ]]; then
    log "Installing system packages (nginx, PHP-FPM, MySQL 8, certbot, ufw)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y -q
    apt-get install -y -q --no-install-recommends \
        nginx mysql-server php-fpm php-cli php-mysql php-mbstring php-curl php-gd php-xml php-opcache \
        rsync cron openssl ca-certificates curl unzip logrotate util-linux
    [[ $SELF_SIGNED -eq 1 ]] || apt-get install -y -q --no-install-recommends certbot
    [[ $FIREWALL -eq 0 ]] || apt-get install -y -q --no-install-recommends ufw
fi

for tool in php mysql nginx rsync openssl curl perl runuser; do
    command -v "$tool" >/dev/null 2>&1 || die "'$tool' is not installed (run without --skip-packages)."
done

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || die "PHP 8.2 or newer is required (found $PHP_VERSION)."
for ext in pdo_mysql mbstring curl gd xml fileinfo json openssl; do
    php -m | grep -qix "$ext" || die "PHP extension '$ext' is missing (apt-get install php-$ext)."
done
FPM_SERVICE="php${PHP_VERSION}-fpm"
[[ -d "/etc/php/${PHP_VERSION}/fpm/pool.d" ]] || die "PHP-FPM $PHP_VERSION is not installed (apt-get install php-fpm)."
info "PHP $PHP_VERSION, $(mysql --version | sed 's/^mysql *//' | cut -c1-60)"

# ----------------------------------------------------------------------------- 2. system user, directories
log "Creating system user '$APP_USER' and directories"
if ! id -u "$APP_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "$APP_DIR" --no-create-home --shell /usr/sbin/nologin "$APP_USER"
fi
install -d -m 0755 /var/lib/qms /var/www/letsencrypt
install -d -m 0750 -o root -g "$APP_USER" /etc/qms
install -d -m 0750 -o root -g "$APP_USER" "$SECRETS_DIR"
install -d -m 0750 -o "$APP_USER" -g "$APP_USER" /var/log/qms
install -d -m 0751 -o root -g "$APP_USER" "$APP_DIR"

# ----------------------------------------------------------------------------- 3. MySQL
log "Configuring MySQL"
install -m 0644 "$SRC_DIR/deploy/mysql/qms.cnf" /etc/mysql/mysql.conf.d/qms.cnf
svc_enable mysql
svc restart mysql
for _ in $(seq 1 30); do mysql_root -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
mysql_root -e 'SELECT 1' >/dev/null || die "Cannot connect to MySQL as root over the local socket."
# PHP (user qms) connects through the socket: the directory must be traversable (Ubuntu default 0755).
[[ -d /run/mysqld ]] && chmod 0755 /run/mysqld
[[ "$(mysql_root -N -e 'SELECT @@log_bin_trust_function_creators')" == "1" ]] || die "MySQL did not load /etc/mysql/mysql.conf.d/qms.cnf."

if [[ -f "$DB_SECRETS" ]]; then
    # shellcheck source=/dev/null
    source "$DB_SECRETS"
    info "Reusing the database credentials in $DB_SECRETS"
else
    QMS_DB_APP_PASSWORD="$(gen_password)"
    QMS_DB_MIGRATOR_PASSWORD="$(gen_password)"
    QMS_DB_BACKUP_PASSWORD="$(gen_password)"
    ( umask 077; cat > "$DB_SECRETS" <<EOF
# QMS database accounts - generated by deploy/install.sh. Keep this file secret (root only).
QMS_DB_NAME=$DB_NAME
QMS_DOMAIN=$DOMAIN
QMS_DB_APP_PASSWORD=$QMS_DB_APP_PASSWORD
QMS_DB_MIGRATOR_PASSWORD=$QMS_DB_MIGRATOR_PASSWORD
QMS_DB_BACKUP_PASSWORD=$QMS_DB_BACKUP_PASSWORD
EOF
    )
    chown root:root "$DB_SECRETS"
    chmod 0600 "$DB_SECRETS"
fi

mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'qms_app'@'localhost' IDENTIFIED BY '$QMS_DB_APP_PASSWORD';
CREATE USER IF NOT EXISTS 'qms_migrator'@'localhost' IDENTIFIED BY '$QMS_DB_MIGRATOR_PASSWORD';
CREATE USER IF NOT EXISTS 'qms_backup'@'localhost' IDENTIFIED BY '$QMS_DB_BACKUP_PASSWORD';
ALTER USER 'qms_app'@'localhost' IDENTIFIED BY '$QMS_DB_APP_PASSWORD';
ALTER USER 'qms_migrator'@'localhost' IDENTIFIED BY '$QMS_DB_MIGRATOR_PASSWORD' ACCOUNT UNLOCK;
ALTER USER 'qms_backup'@'localhost' IDENTIFIED BY '$QMS_DB_BACKUP_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER,
      CREATE TEMPORARY TABLES, LOCK TABLES, CREATE VIEW, SHOW VIEW
   ON \`$DB_NAME\`.* TO 'qms_migrator'@'localhost';
SQL

( umask 077; printf '[client]\nuser=qms_backup\npassword=%s\nprotocol=socket\n' "$QMS_DB_BACKUP_PASSWORD" > "$SECRETS_DIR/backup.cnf" )
chown root:root "$SECRETS_DIR/backup.cnf"
chmod 0600 "$SECRETS_DIR/backup.cnf"

# ----------------------------------------------------------------------------- 4. backup before an update
if [[ $UPDATE -eq 1 ]]; then
    log "Backing up before the update"
    touch /var/lib/qms/maintenance
    if [[ -x /usr/local/sbin/qms-backup ]]; then
        /usr/local/sbin/qms-backup
    else
        warn "/usr/local/sbin/qms-backup not found - no backup taken."
    fi
fi

# ----------------------------------------------------------------------------- 5. application files
log "Installing the application into $APP_DIR"
if [[ "$(realpath "$SRC_DIR")" != "$(realpath "$APP_DIR")" ]]; then
    rsync -a --delete \
        --exclude '/.env' --exclude '/storage/' --exclude '/tests/' --exclude '/dist/' \
        "$SRC_DIR/" "$APP_DIR/"
    rsync -a --ignore-existing --exclude 'installed.lock' --exclude 'setup-key.txt' "$SRC_DIR/storage/" "$APP_DIR/storage/"
fi
install -d -m 0750 "$APP_DIR/storage/uploads/tmp" "$APP_DIR/storage/uploads/logo" "$APP_DIR/storage/uploads/certificates" \
    "$APP_DIR/storage/cache" "$APP_DIR/storage/logs"

chown -R root:"$APP_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +
find "$APP_DIR" -type f -exec chmod 0640 {} +
chmod 0751 "$APP_DIR"
find "$APP_DIR/public" -type d -exec chmod 0755 {} +
find "$APP_DIR/public" -type f -exec chmod 0644 {} +
chown -R "$APP_USER":"$APP_USER" "$APP_DIR/storage"
find "$APP_DIR/storage" -type d -exec chmod 0750 {} +
find "$APP_DIR/storage" -type f -exec chmod 0640 {} +
info "Code: root:$APP_USER read-only for PHP; storage/: $APP_USER; public/: readable by nginx"

# ----------------------------------------------------------------------------- 6. configuration (.env)
GOOGLE_KEY="$SECRETS_DIR/google-sa.json"
PROXIES=""
if [[ -f "$APP_DIR/.env" ]]; then
    PROXIES="$(sed -n 's/^TRUSTED_PROXIES=//p' "$APP_DIR/.env" | head -1)"
fi

write_env() {   # write_env <db user> <db password>
    ( umask 027; cat > "$APP_DIR/.env.tmp" <<EOF
# QMS server configuration - written by deploy/install.sh. Not web-accessible
# (outside public/), readable by root and the qms group only. Never commit it.
APP_ENV=production
APP_URL=https://$DOMAIN/
APP_FORCE_HTTPS=true

# MySQL over the local socket (localhost).
DB_HOST=localhost
DB_PORT=3306
DB_NAME=$DB_NAME
DB_USER=$1
DB_PASS=$2

COOKIE_SECURE=true
SESSION_COOKIE=__Host-qms_session

# Behind a cloud load balancer / reverse proxy, list its address(es) so the
# client IP and HTTPS are detected correctly, e.g. TRUSTED_PROXIES=10.0.0.0/8
TRUSTED_PROXIES=$PROXIES

# Google Sheets: path of the service-account key (never the key itself).
GOOGLE_CREDENTIALS_FILE=$GOOGLE_KEY
EOF
    )
    mv "$APP_DIR/.env.tmp" "$APP_DIR/.env"
    chown root:"$APP_USER" "$APP_DIR/.env"
    chmod 0640 "$APP_DIR/.env"
}

# ----------------------------------------------------------------------------- 7. database schema and data
log "Creating / upgrading the database schema (as qms_migrator)"
write_env qms_migrator "$QMS_DB_MIGRATOR_PASSWORD"
qms migrate
qms seed

log "Granting least-privilege access to qms_app and qms_backup"
# Whole GRANT statements (some span several lines) for qms_app and qms_backup.
perl -0777 -ne 'while (/^(GRANT\s.*?;)/msg) { my $s = $1; print "$s\n" unless $s =~ /qms_migrator/ }' \
    "$SRC_DIR/database/security/qms_db_users.sql" | sed -E "s/ ON qms\./ ON \`$DB_NAME\`./" | mysql_root
info "qms_app: DML only, no DDL, no DELETE on reports, append-only evidence tables"

ADMIN_PASSWORD=""
admins="$(mysql_root -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.users u JOIN \`$DB_NAME\`.roles r ON r.id = u.role_id WHERE r.code = 'SUPER_ADMIN'")"
if [[ "$admins" == "0" ]]; then
    log "Creating the first Super Admin '$ADMIN_USER'"
    output="$(qms create-admin --username="$ADMIN_USER" --name="QMS Administrator" --generate 2>&1)" || die "Could not create the admin: $output"
    ADMIN_PASSWORD="$(printf '%s\n' "$output" | sed -n 's/.*One-time password (shown only now): *//p' | tr -d '\r' | sed 's/\x1b\[[0-9;]*m//g' | tail -1)"
    [[ -n "$ADMIN_PASSWORD" ]] || die "Admin created but the one-time password could not be read: $output"
    # Saved at once, so a failure in a later step cannot lose it.
    ( umask 077; printf 'QMS first login\nURL: https://%s/\nUser: %s\nOne-time password: %s\n(Change it at first login; delete this file afterwards.)\n' \
        "$DOMAIN" "$ADMIN_USER" "$ADMIN_PASSWORD" > /root/qms-first-login.txt )
    info "One-time password saved in /root/qms-first-login.txt"
fi

if [[ $DEMO -eq 1 ]]; then
    log "Loading DEMO master data and templates"
    qms seed:demo
    warn "Demo data loaded: replace the demo parts, machines, gauges and placeholder specifications before real use."
fi

write_env qms_app "$QMS_DB_APP_PASSWORD"
mysql_root -e "ALTER USER 'qms_migrator'@'localhost' ACCOUNT LOCK;"
# The web setup page stays switched off: installation is complete.
printf '{"installed_at": "%s", "by": "deploy/install.sh"}\n' "$(date -u +%FT%TZ)" > "$APP_DIR/storage/installed.lock"
chown "$APP_USER":"$APP_USER" "$APP_DIR/storage/installed.lock"
rm -f "$APP_DIR/storage/setup-key.txt"
info "Runtime connects as qms_app; qms_migrator is locked until the next update."

# ----------------------------------------------------------------------------- 8. PHP-FPM
log "Configuring PHP-FPM pool 'qms'"
render "$SRC_DIR/deploy/php/qms-fpm.conf.template" "/etc/php/$PHP_VERSION/fpm/pool.d/qms.conf"
chmod 0644 "/etc/php/$PHP_VERSION/fpm/pool.d/qms.conf"
install -m 0644 "$SRC_DIR/deploy/php/99-qms.ini" "/etc/php/$PHP_VERSION/fpm/conf.d/99-qms.ini"
install -m 0644 "$SRC_DIR/deploy/php/99-qms.ini" "/etc/php/$PHP_VERSION/cli/conf.d/99-qms.ini"
if [[ -f "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf" ]]; then
    mv "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf" "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf.disabled"
fi
"php-fpm$PHP_VERSION" -t >/dev/null 2>&1 || { "php-fpm$PHP_VERSION" -t; die "PHP-FPM configuration test failed."; }
svc_enable "$FPM_SERVICE"
svc restart "$FPM_SERVICE"

# ----------------------------------------------------------------------------- 9. nginx and TLS
log "Configuring nginx and HTTPS for $DOMAIN"
rm -f /etc/nginx/sites-enabled/default
svc_enable nginx
if [[ $UPDATE -eq 1 && -f /etc/nginx/sites-available/qms.conf ]]; then
    SSL_CERT="$(sed -n 's/^ *ssl_certificate  *\([^;]*\);/\1/p' /etc/nginx/sites-available/qms.conf | head -1)"
    SSL_KEY="$(sed -n 's/^ *ssl_certificate_key  *\([^;]*\);/\1/p' /etc/nginx/sites-available/qms.conf | head -1)"
elif [[ $SELF_SIGNED -eq 1 ]]; then
    SSL_CERT=/etc/ssl/certs/qms-selfsigned.crt
    SSL_KEY=/etc/ssl/private/qms-selfsigned.key
    if [[ ! -f "$SSL_CERT" ]]; then
        if [[ "$DOMAIN" =~ ^[0-9.]+$ ]]; then SAN="IP:$DOMAIN"; else SAN="DNS:$DOMAIN"; fi
        openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout "$SSL_KEY" -out "$SSL_CERT" \
            -subj "/CN=$DOMAIN" -addext "subjectAltName=$SAN" >/dev/null 2>&1
        chmod 0600 "$SSL_KEY"
    fi
    SUMMARY+=("TLS: self-signed certificate (browsers warn once). Re-run with --domain <dns-name> --email <address> for Let's Encrypt.")
else
    SSL_CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
    SSL_KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
    if [[ ! -f "$SSL_CERT" ]]; then
        render "$SRC_DIR/deploy/nginx/acme-only.conf.template" /etc/nginx/sites-available/qms.conf
        ln -sf /etc/nginx/sites-available/qms.conf /etc/nginx/sites-enabled/qms.conf
        nginx -t >/dev/null 2>&1 && svc reload nginx
        certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" --email "$EMAIL" \
            --agree-tos --no-eff-email --non-interactive \
            || die "Let's Encrypt failed. Check that $DOMAIN points to this server and port 80 is open, or use --self-signed."
    fi
    install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
    printf '#!/bin/sh\nnginx -t && (systemctl reload nginx 2>/dev/null || service nginx reload)\n' > /etc/letsencrypt/renewal-hooks/deploy/qms-reload-nginx
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/qms-reload-nginx
    SUMMARY+=("TLS: Let's Encrypt certificate, renewed automatically by certbot.")
fi
render "$SRC_DIR/deploy/nginx/qms.conf.template" /etc/nginx/sites-available/qms.conf
ln -sf /etc/nginx/sites-available/qms.conf /etc/nginx/sites-enabled/qms.conf
nginx -t >/dev/null 2>&1 || { nginx -t; die "nginx configuration test failed."; }
svc reload nginx

# ----------------------------------------------------------------------------- 10. scheduled jobs, backups, logs
log "Installing cron jobs, backup scripts and log rotation"
install -m 0750 "$SRC_DIR/deploy/backup/qms-backup.sh" /usr/local/sbin/qms-backup
install -m 0750 "$SRC_DIR/deploy/backup/qms-restore.sh" /usr/local/sbin/qms-restore
if [[ ! -f /etc/qms/backup.conf ]]; then
    printf '# QMS backup settings\nQMS_APP_DIR=%s\nQMS_DB_NAME=%s\nQMS_BACKUP_DIR=/var/backups/qms\nQMS_BACKUP_KEEP_DAYS=14\n# QMS_BACKUP_OFFSITE_CMD=%s\n' \
        "$APP_DIR" "$DB_NAME" "'rclone copy /var/backups/qms remote:qms-backups'" > /etc/qms/backup.conf
    chmod 0640 /etc/qms/backup.conf
fi
render "$SRC_DIR/deploy/cron/qms.template" /etc/cron.d/qms
chmod 0644 /etc/cron.d/qms
install -m 0644 "$SRC_DIR/deploy/logrotate/qms" /etc/logrotate.d/qms
svc_enable cron || true

# ----------------------------------------------------------------------------- 11. firewall
if [[ $FIREWALL -eq 1 ]] && command -v ufw >/dev/null 2>&1; then
    log "Configuring the UFW firewall (SSH, HTTP, HTTPS only)"
    ufw allow OpenSSH >/dev/null
    ufw allow 'Nginx Full' >/dev/null
    ufw --force enable >/dev/null || warn "UFW could not be enabled here (container?). Use the cloud provider's firewall."
fi

# ----------------------------------------------------------------------------- 12. finish
rm -f /var/lib/qms/maintenance
svc reload "$FPM_SERVICE" || true

log "Checking the installation"
code="$(curl --noproxy '*' -sk -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health" || true)"
if [[ "$code" == "200" ]]; then info "https://$DOMAIN/health answers 200"; else warn "Health check returned HTTP $code - see /var/log/nginx/qms.error.log and $APP_DIR/storage/logs/"; fi

printf '\n\033[1;32m%s\033[0m\n' "QMS is ready."
printf '  URL:            https://%s/\n' "$DOMAIN"
if [[ -n "$ADMIN_PASSWORD" ]]; then
    printf '  Super Admin:    %s\n  Password:       %s   (one-time: you must change it at first login)\n' "$ADMIN_USER" "$ADMIN_PASSWORD"
    printf '  Saved in:       /root/qms-first-login.txt (delete after the first login)\n'
fi
printf '  DB credentials: %s (root only)\n' "$DB_SECRETS"
printf '  Backups:        /var/backups/qms (nightly 01:30 UTC) - copy them off the server\n'
printf '  Logs:           %s/storage/logs, /var/log/qms, /var/log/nginx/qms.*.log\n' "$APP_DIR"
for line in "${SUMMARY[@]}"; do printf '  %s\n' "$line"; done
printf '  Next:           README.md section "After installation" (company name, users, Google Sheets).\n\n'
