#!/usr/bin/env bash
#
# One-command install of the whole stack on a fresh Debian or Ubuntu server:
# Docker (Postgres, Postfix, Dovecot, Rspamd, Redis), PHP, nginx, a Let's Encrypt
# certificate and the admin panel. Safe to run again: it keeps existing .env files
# and secrets, and rebuilds and migrates, so it also works as an update after `git pull`.
#
#   sudo ./install.sh --host mail.example.com --domain example.com --email you@example.com
#
# Run it from the cloned repository, in a folder the web server can read (for example
# /opt/mailserver, not /root or /home).

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADMIN="$ROOT/admin"
PHP_VERSION="8.4"
WEB_USER="www-data"

HOST="${APP_DOMAIN:-}"
DOMAIN="${MAIL_DOMAIN:-}"
EMAIL="${CERTBOT_EMAIL:-}"
TIMEZONE="${APP_TIMEZONE:-}"
ASSUME_YES=0

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

trap 'die "Failed on line $LINENO. Fix the cause and run ./install.sh again, it continues where it stopped."' ERR

usage() {
    cat <<EOF
Usage: sudo ./install.sh [options]

  --host NAME       Mail server host name, e.g. mail.example.com (needs a public DNS record)
  --domain NAME     Your main mail domain, e.g. example.com
  --email ADDRESS   E-mail for Let's Encrypt expiry notices
  --timezone NAME   IANA time zone, e.g. Europe/Sofia (default: UTC)
  -y, --yes         Do not ask for confirmation
  -h, --help        This text

Every option can also be given as an environment variable: APP_DOMAIN, MAIL_DOMAIN,
CERTBOT_EMAIL, APP_TIMEZONE. Missing values are asked for.
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --host) HOST="${2:?--host needs a value}"; shift 2 ;;
        --domain) DOMAIN="${2:?--domain needs a value}"; shift 2 ;;
        --email) EMAIL="${2:?--email needs a value}"; shift 2 ;;
        --timezone) TIMEZONE="${2:?--timezone needs a value}"; shift 2 ;;
        -y|--yes) ASSUME_YES=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage; die "Unknown option: $1" ;;
    esac
done

# --- Checks ---------------------------------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo ./install.sh"
[ -f /etc/os-release ] || die "Cannot detect the operating system."
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
    debian|ubuntu) ;;
    *) die "Only Debian and Ubuntu are supported (found: ${PRETTY_NAME:-unknown})." ;;
esac
command -v apt-get >/dev/null || die "apt-get not found."
[ -d "$ADMIN" ] && [ -f "$ROOT/docker-compose.yml" ] || die "Run this from the cloned repository (docker-compose.yml and admin/ must be next to it)."

case "$ROOT" in
    /root|/root/*|/home|/home/*) die "The repository is in $ROOT, the web server cannot read it. Move it, for example: mv \"$ROOT\" /opt/mailserver" ;;
esac

ask() { # ask VAR "Question" [default]
    local var="$1" question="$2" default="${3:-}" answer
    [ -n "${!var}" ] && return 0
    if [ ! -t 0 ]; then
        [ -n "$default" ] && { printf -v "$var" '%s' "$default"; return 0; }
        die "$question: pass it as an option (see --help)."
    fi
    read -r -p "$question${default:+ [$default]}: " answer
    printf -v "$var" '%s' "${answer:-$default}"
    [ -n "${!var}" ] || die "$question is required."
}

ask HOST "Mail server host name (e.g. mail.example.com)"
ask DOMAIN "Main mail domain (e.g. example.com)" "$(echo "$HOST" | cut -d. -f2-)"
ask EMAIL "E-mail for Let's Encrypt notices"
ask TIMEZONE "Time zone" "UTC"

HOST="$(echo "$HOST" | tr '[:upper:]' '[:lower:]')"
DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]')"
host_re='^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$'
[[ "$HOST" =~ $host_re ]] || die "Invalid host name: $HOST"
[[ "$DOMAIN" =~ $host_re ]] || die "Invalid domain: $DOMAIN"
[[ "$EMAIL" == *@*.* ]] || die "Invalid e-mail: $EMAIL"
[ -e "/usr/share/zoneinfo/$TIMEZONE" ] || die "Unknown time zone: $TIMEZONE"

# Public IP of this server against the DNS record, a mismatch is the most common reason for a failed certificate.
SERVER_IP="$(curl -fsS4 --max-time 8 https://api.ipify.org 2>/dev/null || true)"
DNS_IP="$(getent ahostsv4 "$HOST" 2>/dev/null | awk '{print $1; exit}')"
if [ -z "$DNS_IP" ]; then
    warn "$HOST does not resolve yet. Create the A record first, otherwise the certificate step fails."
elif [ -n "$SERVER_IP" ] && [ "$DNS_IP" != "$SERVER_IP" ]; then
    warn "$HOST resolves to $DNS_IP but this server is $SERVER_IP. The certificate step fails unless they match."
fi

# Ports 80 and 443 may already belong to nginx, that is fine. Port 25 has to be free for Postfix.
if ss -ltnH "sport = :25" 2>/dev/null | grep -q .; then
    warn "Port 25 is already in use (another mail server?), Postfix cannot listen on it."
fi

cat <<EOF

Host name:   $HOST
Mail domain: $DOMAIN
Let's Encrypt e-mail: $EMAIL
Time zone:   $TIMEZONE
Install in:  $ROOT

This installs Docker, PHP $PHP_VERSION, Composer, Node.js, nginx and certbot, gets a certificate
for $HOST and starts the mail stack and the panel.
EOF
if [ "$ASSUME_YES" -ne 1 ] && [ -t 0 ]; then
    read -r -p "Continue? [y/N] " go
    [[ "$go" =~ ^[Yy]$ ]] || die "Cancelled."
fi

export DEBIAN_FRONTEND=noninteractive

# --- Packages -------------------------------------------------------------------------

log "Base packages"
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg lsb-release unzip openssl cron nginx certbot python3-certbot-nginx >/dev/null

if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
    log "Docker (official installer from get.docker.com)"
    curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker >/dev/null 2>&1 || true

if ! command -v php >/dev/null || ! php -r 'exit(version_compare(PHP_VERSION, "'"$PHP_VERSION"'", ">=") ? 0 : 1);'; then
    log "PHP $PHP_VERSION"
    if [ "$ID" = "ubuntu" ]; then
        apt-get install -y -qq software-properties-common >/dev/null
        add-apt-repository -y ppa:ondrej/php >/dev/null
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/trusted.gpg.d/php-sury.gpg
        echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php-sury.list
    fi
    apt-get update -qq
fi
apt-get install -y -qq \
    "php$PHP_VERSION-cli" "php$PHP_VERSION-fpm" "php$PHP_VERSION-pgsql" "php$PHP_VERSION-mbstring" \
    "php$PHP_VERSION-xml" "php$PHP_VERSION-curl" "php$PHP_VERSION-zip" "php$PHP_VERSION-intl" >/dev/null

# Attachments are up to 50 MB (the Postfix limit) and travel base64-encoded.
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-mailserver.ini" <<'EOF'
upload_max_filesize = 64M
post_max_size = 96M
memory_limit = 512M
EOF
systemctl restart "php$PHP_VERSION-fpm"
# The command line `php` must be the same version as the one the panel runs under.
[ -x "/usr/bin/php$PHP_VERSION" ] && update-alternatives --set php "/usr/bin/php$PHP_VERSION" >/dev/null 2>&1 || true

if ! command -v composer >/dev/null; then
    log "Composer"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    [ "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" = "$expected" ] || die "Composer installer checksum mismatch."
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
    log "Node.js LTS (only used to build the frontend)"
    curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - >/dev/null
    apt-get install -y -qq nodejs >/dev/null
fi

id "$WEB_USER" >/dev/null 2>&1 || die "User $WEB_USER does not exist."
su -s /bin/sh "$WEB_USER" -c "test -r '$ADMIN/public/index.php'" \
    || die "$WEB_USER cannot read $ADMIN. Put the repository somewhere readable, for example /opt/mailserver."

# --- Environment files ----------------------------------------------------------------

secret() { openssl rand -base64 48 | tr -d '/+=\n' | cut -c1-32; }

# set_env FILE KEY VALUE: replaces KEY=... or appends it. Values here are host names and
# generated alphanumerics, so no escaping is needed.
set_env() {
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

get_env() { grep -E "^$2=" "$1" | head -1 | cut -d= -f2-; }

log "Root .env (mail stack)"
if [ ! -f "$ROOT/.env" ]; then
    cp "$ROOT/.env.example" "$ROOT/.env"
    set_env "$ROOT/.env" DB_PASSWORD "$(secret)"
    set_env "$ROOT/.env" DOVECOT_MASTER_PASSWORD "$(secret)"
    chmod 600 "$ROOT/.env"
else
    echo "Keeping the existing .env (secrets are not regenerated)."
fi
set_env "$ROOT/.env" APP_DOMAIN "$HOST"
set_env "$ROOT/.env" MAIL_DOMAIN "$DOMAIN"
set_env "$ROOT/.env" APP_TIMEZONE "$TIMEZONE"

DB_PASSWORD="$(get_env "$ROOT/.env" DB_PASSWORD)"
DB_DATABASE="$(get_env "$ROOT/.env" DB_DATABASE)"
DB_USERNAME="$(get_env "$ROOT/.env" DB_USERNAME)"
MASTER_PASSWORD="$(get_env "$ROOT/.env" DOVECOT_MASTER_PASSWORD)"
{ [ -n "$DB_PASSWORD" ] && [ "$DB_PASSWORD" != "change-me" ] && [ -n "$MASTER_PASSWORD" ] && [ "$MASTER_PASSWORD" != "change-me" ]; } \
    || die "DB_PASSWORD and DOVECOT_MASTER_PASSWORD in $ROOT/.env must be real secrets, not empty or change-me."

log "Panel .env"
if [ ! -f "$ADMIN/.env" ]; then
    cp "$ADMIN/.env.example" "$ADMIN/.env"
fi
chmod 640 "$ADMIN/.env"
chown "root:$WEB_USER" "$ADMIN/.env"
for pair in \
    "APP_ENV=production" "APP_DEBUG=false" "APP_URL=https://$HOST" "APP_TIMEZONE=$TIMEZONE" \
    "DB_CONNECTION=pgsql" "DB_HOST=127.0.0.1" "DB_PORT=5432" "DB_DATABASE=$DB_DATABASE" \
    "DB_USERNAME=$DB_USERNAME" "DB_PASSWORD=$DB_PASSWORD" "APP_DOMAIN=$HOST" \
    "DOVECOT_MASTER_PASSWORD=$MASTER_PASSWORD" "MAIL_MAILER=postfix" \
    "SESSION_SECURE_COOKIE=true" "LOG_LEVEL=error"; do
    set_env "$ADMIN/.env" "${pair%%=*}" "${pair#*=}"
done
# The sender of system e-mail (invitations, password reset) must be a real mailbox, kept if already set.
if [ -z "$(get_env "$ADMIN/.env" MAIL_FROM_ADDRESS)" ] || [ "$(get_env "$ADMIN/.env" MAIL_FROM_ADDRESS)" = "noreply@example.com" ]; then
    set_env "$ADMIN/.env" MAIL_FROM_ADDRESS "noreply@$DOMAIN"
fi

# --- nginx and the certificate --------------------------------------------------------

log "nginx"
SITE="/etc/nginx/sites-available/mailserver-panel"
if [ ! -f "$SITE" ] || grep -q "managed by mailserver install.sh" "$SITE"; then
    cat > "$SITE" <<EOF
# managed by mailserver install.sh (certbot adds the HTTPS part below)
server {
    listen 80;
    listen [::]:80;
    server_name $HOST;

    root $ADMIN/public;
    index index.php;
    client_max_body_size 96m;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm.sock;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
else
    warn "$SITE was edited by hand, leaving it as it is."
fi
ln -sf "$SITE" /etc/nginx/sites-enabled/mailserver-panel
nginx -t
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl reload nginx

if [ ! -f "/etc/letsencrypt/live/$HOST/fullchain.pem" ]; then
    log "Certificate for $HOST"
    certbot --nginx -d "$HOST" -m "$EMAIL" --agree-tos --no-eff-email --non-interactive --redirect
else
    echo "A certificate for $HOST already exists."
fi

# Postfix and Dovecot read the certificate from disk, tell them when it is renewed.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/mailserver.sh <<EOF
#!/bin/sh
# managed by mailserver install.sh
cd "$ROOT" || exit 0
docker compose exec -T postfix postfix reload >/dev/null 2>&1 || true
docker compose exec -T dovecot doveadm reload >/dev/null 2>&1 || true
EOF
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/mailserver.sh

# --- Mail stack -----------------------------------------------------------------------

log "Mail stack (docker compose)"
# The panel writes the DKIM private keys here and Rspamd reads them.
mkdir -p "$ROOT/docker/data/dkim"
chown "$WEB_USER:$WEB_USER" "$ROOT/docker/data/dkim"
chmod 755 "$ROOT/docker/data/dkim"
(cd "$ROOT" && docker compose up -d --build)

echo "Waiting for PostgreSQL..."
for _ in $(seq 1 60); do
    if (cd "$ROOT" && docker compose exec -T postgres pg_isready -U "$DB_USERNAME" -d "$DB_DATABASE" >/dev/null 2>&1); then
        break
    fi
    sleep 2
done
(cd "$ROOT" && docker compose exec -T postgres pg_isready -U "$DB_USERNAME" -d "$DB_DATABASE" >/dev/null) || die "PostgreSQL did not become ready. See: docker compose logs postgres"

# --- Panel ----------------------------------------------------------------------------

log "Panel (composer, migrations, frontend)"
cd "$ADMIN"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet
if [ -z "$(get_env .env APP_KEY)" ]; then
    php artisan key:generate --force --no-interaction
fi
php artisan optimize:clear --no-interaction
php artisan migrate --force --no-interaction
npm ci --silent --no-audit --no-fund
npm run build --silent

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache
php artisan optimize --no-interaction
chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache

# Scheduled sending ("Send later") runs from Laravel's scheduler.
cat > /etc/cron.d/mailserver-panel <<EOF
# managed by mailserver install.sh
* * * * * $WEB_USER cd $ADMIN && php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/mailserver-panel

# --- Done -----------------------------------------------------------------------------

cat <<EOF

Done. The panel is at https://$HOST

Next steps
  1. Open https://$HOST right now and create the administrator. The first account to
     register gets full control, so do it before anyone else can reach the page.
  2. Firewall: allow 25, 80, 143, 443, 465, 587 and 993 (ufw allow 25,80,143,443,465,587,993/tcp).
  3. Many hosting providers block outgoing port 25 by default. If mail to other servers
     does not leave, ask the provider to open it.
  4. In the panel add your domain ($DOMAIN), then create the mailbox
     $(get_env "$ADMIN/.env" MAIL_FROM_ADDRESS). Invitations and password reset e-mails are
     sent from it, nothing is sent until it exists.
  5. DNS for $DOMAIN (the Domains page shows the DKIM record, "Sync DNS" does it for Cloudflare):
       MX      $DOMAIN  ->  $HOST
       TXT     $DOMAIN  ->  v=spf1 mx -all
       TXT     <selector>._domainkey.$DOMAIN  ->  value from the Domains page
       TXT     _dmarc.$DOMAIN  ->  v=DMARC1; p=quarantine
       PTR     the server IP  ->  $HOST (set at your hosting provider)
  6. Optional: Google sign-in (GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in $ADMIN/.env,
     then: cd $ADMIN && php artisan config:cache).

To update later: git pull && sudo ./install.sh --host $HOST --domain $DOMAIN --email $EMAIL -y
EOF
