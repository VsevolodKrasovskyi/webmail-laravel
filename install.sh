#!/usr/bin/env bash
#
# One-command install of the whole stack on a fresh Debian or Ubuntu server:
# Docker (Postgres, Postfix, Dovecot, Rspamd, Redis), PHP, nginx, a Let's Encrypt
# certificate and the admin panel. Safe to run again: it keeps existing .env files
# and secrets, and rebuilds and migrates, so it also works as an update after `git pull`.
#
#   sudo ./install.sh                      # guided, asks in a whiptail dialog
#   sudo ./install.sh --host mail.example.com --domain example.com --email you@example.com -y
#
# Run it from the cloned repository, in a folder the web server can read (for example
# /opt/mailserver, not /root or /home).

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADMIN="$ROOT/admin"
PHP_VERSION="8.4"
WEB_USER="www-data"
LOGFILE="/var/log/mailserver-install.log"

HOST="${APP_DOMAIN:-}"
DOMAIN="${MAIL_DOMAIN:-}"
EMAIL="${CERTBOT_EMAIL:-}"
TIMEZONE="${APP_TIMEZONE:-}"
MAIL_FROM="${MAIL_FROM_ADDRESS:-}"
ASSUME_YES=0
NO_TUI=0
DRY_RUN=0

UI="plain"          # "tui" when a whiptail dialog is used
OUT_FD=1            # where progress goes, a terminal even when the output is moved to the log
ERR_FD=2
QUIET=0             # 1 once command output goes to the log file instead of the terminal
WARNINGS=""
STEP=0
STEP_TOTAL=5

# Progress. In the dialog mode the commands are quiet and only the steps are shown.
step() { STEP=$((STEP + 1)); printf '\n\033[1;34m[%d/%d]\033[0m \033[1m%s\033[0m\n' "$STEP" "$STEP_TOTAL" "$*" >&"$OUT_FD"; }
log()  { if [ "$QUIET" -eq 1 ]; then printf '      - %s\n' "$*" >&"$OUT_FD"; else printf '\n\033[1;34m==>\033[0m %s\n' "$*"; fi; }
warn() {
    WARNINGS="${WARNINGS}- $*"$'\n'
    # Before the install starts the dialog shows the warnings in its summary.
    if [ "$UI" = "tui" ] && [ "$QUIET" -eq 0 ] && [ "$STEP" -eq 0 ]; then return 0; fi
    printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&"$ERR_FD"
}
die() {
    printf '\033[1;31mError:\033[0m %s\n' "$*" >&"$ERR_FD"
    if [ "$QUIET" -eq 1 ]; then
        printf 'Last lines of %s:\n' "$LOGFILE" >&"$ERR_FD"
        tail -n 15 "$LOGFILE" >&"$ERR_FD" 2>/dev/null || true
    fi
    exit 1
}

trap 'die "Failed on line $LINENO. Fix the cause and run ./install.sh again, it continues where it stopped."' ERR

usage() {
    cat <<EOF
Usage: sudo ./install.sh [options]

Without options it asks for what it needs in a dialog (whiptail). With the options and -y it
runs without any question.

  --host NAME       Mail server host name, e.g. mail.example.com (needs a public DNS record)
  --domain NAME     Your main mail domain, e.g. example.com
  --email ADDRESS   E-mail for Let's Encrypt expiry notices
  --timezone NAME   IANA time zone, e.g. Europe/Sofia (default: UTC)
  --from ADDRESS    Sender of the system e-mail (invitations, password reset), a mailbox you create
                    in the panel. A name without @ gets the mail domain. Default: noreply@DOMAIN
  -y, --yes         Do not ask for confirmation, no dialog
  --no-tui          Plain questions on the command line instead of the dialog
  --dry-run         Show the dialogs and the steps without installing or changing anything
  -h, --help        This text

Every option can also be given as an environment variable: APP_DOMAIN, MAIL_DOMAIN,
CERTBOT_EMAIL, APP_TIMEZONE, MAIL_FROM_ADDRESS. Missing values are asked for. Details of an install in the
dialog mode go to $LOGFILE.
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --host) HOST="${2:?--host needs a value}"; shift 2 ;;
        --domain) DOMAIN="${2:?--domain needs a value}"; shift 2 ;;
        --email) EMAIL="${2:?--email needs a value}"; shift 2 ;;
        --timezone) TIMEZONE="${2:?--timezone needs a value}"; shift 2 ;;
        --from) MAIL_FROM="${2:?--from needs a value}"; shift 2 ;;
        -y|--yes) ASSUME_YES=1; shift ;;
        --no-tui) NO_TUI=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage; die "Unknown option: $1" ;;
    esac
done

# --- Checks ---------------------------------------------------------------------------

[ -d "$ADMIN" ] && [ -f "$ROOT/docker-compose.yml" ] || die "Run this from the cloned repository (docker-compose.yml and admin/ must be next to it)."

if [ "$DRY_RUN" -eq 1 ]; then
    # A preview of the dialogs and steps: no root, no Debian, nothing is installed or written.
    LOGFILE="${TMPDIR:-/tmp}/mailserver-install-dry-run.log"
    ID="${ID:-debian}"
else
    [ "$(id -u)" -eq 0 ] || die "Run as root: sudo ./install.sh"
    [ -f /etc/os-release ] || die "Cannot detect the operating system."
    # shellcheck disable=SC1091
    . /etc/os-release
    case "${ID:-}" in
        debian|ubuntu) ;;
        *) die "Only Debian and Ubuntu are supported (found: ${PRETTY_NAME:-unknown})." ;;
    esac
    command -v apt-get >/dev/null || die "apt-get not found."

    case "$ROOT" in
        /root|/root/*|/home|/home/*) die "The repository is in $ROOT, the web server cannot read it. Move it, for example: mv \"$ROOT\" /opt/mailserver" ;;
    esac
fi

export DEBIAN_FRONTEND=noninteractive

# The dialog needs a terminal on both sides and is skipped with -y or --no-tui.
if [ "$DRY_RUN" -eq 1 ] && [ "$UI" = "plain" ] && { [ ! -t 0 ] || [ ! -t 1 ]; }; then
    warn "Dry run without a terminal, the dialog cannot be shown."
fi
if [ "$ASSUME_YES" -ne 1 ] && [ "$NO_TUI" -ne 1 ] && [ -t 0 ] && [ -t 1 ]; then
    if [ "$DRY_RUN" -eq 0 ]; then
        command -v whiptail >/dev/null || apt-get install -y -qq whiptail >/dev/null 2>&1 || true
    fi
    command -v whiptail >/dev/null && UI="tui"
fi

# Height of a whiptail box for a text: its lines plus the frame and buttons, never taller than the terminal.
dialog_height() {
    local lines rows
    lines="$(printf '%s\n' "$1" | wc -l)"
    rows="$(tput lines 2>/dev/null || echo 24)"
    local h=$((lines + 7))
    [ "$h" -gt $((rows - 1)) ] && h=$((rows - 1))
    echo "$h"
}

get_env() { grep -E "^$2=" "$1" 2>/dev/null | head -1 | cut -d= -f2-; }

finish() {
    local mail_from="$MAIL_FROM"
    NEXT_STEPS="The panel is at https://$HOST

Next steps
  1. Open https://$HOST right now and create the administrator. The first account to
     register gets full control, so do it before anyone else can reach the page.
  2. Firewall: allow 25, 80, 143, 443, 465, 587 and 993
     (ufw allow 25,80,143,443,465,587,993/tcp).
  3. Many hosting providers block outgoing port 25 by default. If mail to other servers
     does not leave, ask the provider to open it.
  4. In the panel add your domain ($DOMAIN), then create the mailbox
     $mail_from. Invitations and password reset e-mails are
     sent from it, nothing is sent until it exists.
  5. DNS for $DOMAIN (the Domains page shows the DKIM record):
       MX      $DOMAIN  ->  $HOST
       TXT     $DOMAIN  ->  v=spf1 mx -all
       TXT     <selector>._domainkey.$DOMAIN  ->  value from the Domains page
       TXT     _dmarc.$DOMAIN  ->  v=DMARC1; p=quarantine
       PTR     the server IP  ->  $HOST (set at your hosting provider)
     With Cloudflare, add an API key on the API keys page and press Sync DNS on a domain.
  6. Optional: Google sign-in (GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in $ADMIN/.env,
     then: cd $ADMIN && php artisan config:cache).

To update later: cd $ROOT && sudo ./update.sh"

if [ "$UI" = "tui" ]; then
    local short="Done. The panel is at https://$HOST

  1. Open it now and create the administrator. The first account gets full control.
  2. Open the ports 25, 80, 143, 443, 465, 587 and 993 in the firewall.
  3. In the panel add the domain $DOMAIN and create the mailbox $mail_from,
     the system e-mail is sent from it.
  4. Add the DNS records (MX, SPF, DKIM, DMARC, PTR).

The full list stays in this terminal and in $LOGFILE."
    printf '\n%s\n' "$NEXT_STEPS" >> "$LOGFILE"
    whiptail --title "Done" --msgbox "$short" "$(dialog_height "$short")" 80 >&"$OUT_FD" 2>&"$ERR_FD" || true
fi
printf '\n\033[1;32mDone.\033[0m %s\n' "$NEXT_STEPS" >&"$OUT_FD"
}

# --- Values ---------------------------------------------------------------------------

host_re='^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$'
valid_host()   { [[ "$1" =~ $host_re ]] || { echo "Invalid host name: $1"; return 1; }; }
valid_domain() { [[ "$1" =~ $host_re ]] || { echo "Invalid domain: $1"; return 1; }; }
valid_email()  { [[ "$1" == *@*.* ]] || { echo "Invalid e-mail: $1"; return 1; }; }
valid_sender() { [[ "$1" == *@*.* || "$1" =~ ^[a-zA-Z0-9._+-]+$ ]] || { echo "Invalid sender: $1 (a name such as noreply, or a full address)"; return 1; }; }
valid_tz()     { [ -n "$1" ] && [ -f "/usr/share/zoneinfo/$1" ] || { echo "Unknown time zone: $1 (an IANA name such as Europe/Sofia or UTC)"; return 1; }; }

# Values of an earlier install are the defaults, so running again is a matter of pressing Enter.
env_default() { [ -f "$ROOT/.env" ] && { grep -E "^$1=" "$ROOT/.env" | head -1 | cut -d= -f2- || true; } || true; }
D_HOST="$(env_default APP_DOMAIN)"; [ "$D_HOST" = "mail.example.com" ] && D_HOST=""
D_DOMAIN="$(env_default MAIL_DOMAIN)"; [ "$D_DOMAIN" = "example.com" ] && D_DOMAIN=""
D_TZ="$(env_default APP_TIMEZONE)"; D_TZ="${D_TZ:-UTC}"
D_FROM=""
if [ -f "$ADMIN/.env" ]; then
    D_FROM="$(grep -E "^MAIL_FROM_ADDRESS=" "$ADMIN/.env" | head -1 | cut -d= -f2- || true)"
    [ "$D_FROM" = "noreply@example.com" ] && D_FROM=""
fi

# ask VAR "Title" "Question" default validator
ask() {
    local var="$1" title="$2" question="$3" default="${4:-}" validate="$5" value err
    [ -n "${!var}" ] && return 0

    if [ "$UI" = "tui" ]; then
        value="$default"
        while :; do
            value="$(whiptail --title "$title" --inputbox "$question" "$(( $(printf '%s\n' "$question" | wc -l) + 8 ))" 74 "$value" 3>&1 1>&2 2>&3)" || die "Cancelled."
            value="$(echo "$value" | tr -d '[:space:]')"
            if err="$("$validate" "$value" 2>&1)"; then
                printf -v "$var" '%s' "$value"
                return 0
            fi
            whiptail --title "Check the value" --msgbox "$err" 9 70
        done
    fi

    if [ ! -t 0 ]; then
        [ -n "$default" ] && { printf -v "$var" '%s' "$default"; return 0; }
        die "$title: pass it as an option (see --help)."
    fi
    read -r -p "$title ($question)${default:+ [$default]}: " value
    printf -v "$var" '%s' "${value:-$default}"
    [ -n "${!var}" ] || die "$title is required."
}

if [ "$UI" = "tui" ]; then
    whiptail --title "Mail server installer" --yesno "This installs the whole mail stack on this server:\n\n  - Docker, PHP $PHP_VERSION, Composer, Node.js, nginx, certbot\n  - a Let's Encrypt certificate for the mail host name\n  - Postgres, Postfix, Dovecot and Rspamd (docker compose)\n  - the admin panel\n\nYou need a DNS A record of the mail host name that points at this server. Continue?" 17 76 || die "Cancelled."
fi

ask HOST "Mail host name" "e.g. mail.example.com, needs a public DNS A record" "$D_HOST" valid_host
HOST="$(echo "$HOST" | tr '[:upper:]' '[:lower:]')"
ask DOMAIN "Main mail domain" "e.g. example.com, the domain of the mailboxes" "${D_DOMAIN:-$(echo "$HOST" | cut -d. -f2-)}" valid_domain
ask EMAIL "E-mail for Let's Encrypt" "expiry notices of the certificate go here" "" valid_email
ask TIMEZONE "Time zone" "an IANA name such as Europe/Sofia or UTC" "$D_TZ" valid_tz
DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]')"
ask MAIL_FROM "System e-mail sender" "Invitations and password reset e-mails are sent from it.
Press Enter to keep the default, or type another name or a full address.
Create this mailbox in the panel after the install." "${D_FROM:-noreply@$DOMAIN}" valid_sender
# A name without @ belongs to the mail domain.
MAIL_FROM="$(echo "$MAIL_FROM" | tr '[:upper:]' '[:lower:]')"
[[ "$MAIL_FROM" == *@* ]] || MAIL_FROM="$MAIL_FROM@$DOMAIN"

DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]')"
err="$(valid_host "$HOST" 2>&1)" || die "$err"
err="$(valid_domain "$DOMAIN" 2>&1)" || die "$err"
err="$(valid_email "$EMAIL" 2>&1)" || die "$err"
err="$(valid_tz "$TIMEZONE" 2>&1)" || die "$err"
err="$(valid_sender "$MAIL_FROM" 2>&1)" || die "$err"

# Public IP of this server against the DNS record, a mismatch is the most common reason for a failed certificate.
SERVER_IP="$(curl -fsS4 --max-time 8 https://api.ipify.org 2>/dev/null || true)"
DNS_IP="$(getent ahostsv4 "$HOST" 2>/dev/null | awk '{print $1; exit}' || true)"
if [ -z "$DNS_IP" ]; then
    warn "$HOST does not resolve yet. Create the A record first, otherwise the certificate step fails."
elif [ -n "$SERVER_IP" ] && [ "$DNS_IP" != "$SERVER_IP" ]; then
    warn "$HOST resolves to $DNS_IP but this server is $SERVER_IP. The certificate step fails unless they match."
fi

# Ports 80 and 443 may already belong to nginx, that is fine. Port 25 has to be free for Postfix.
if ss -ltnH "sport = :25" 2>/dev/null | grep -q .; then
    warn "Port 25 is already in use (another mail server?), Postfix cannot listen on it."
fi

SUMMARY="Host name:            $HOST
Mail domain:          $DOMAIN
Let's Encrypt e-mail: $EMAIL
Time zone:            $TIMEZONE
System e-mail sender: $MAIL_FROM
Install in:           $ROOT
Log of the install:   $LOGFILE"

if [ "$UI" = "tui" ]; then
    if [ -n "$WARNINGS" ]; then
        SUMMARY="$SUMMARY

Warnings:
$WARNINGS"
    fi
    SUMMARY="$SUMMARY

Install now?"
    whiptail --title "Ready to install" --yesno "$SUMMARY" "$(dialog_height "$SUMMARY")" 78 || die "Cancelled."
else
    printf '\n%s\n\nThis installs Docker, PHP %s, Composer, Node.js, nginx and certbot, gets a certificate\nfor %s and starts the mail stack and the panel.\n' "$SUMMARY" "$PHP_VERSION" "$HOST"
    if [ "$ASSUME_YES" -ne 1 ] && [ -t 0 ]; then
        read -r -p "Continue? [y/N] " go
        [[ "$go" =~ ^[Yy]$ ]] || die "Cancelled."
    fi
fi

# In the dialog mode the commands write to the log file and the terminal only shows the steps.
if [ "$UI" = "tui" ]; then
    : > "$LOGFILE"
    chmod 600 "$LOGFILE"
    exec 3>&1 4>&2
    OUT_FD=3
    ERR_FD=4
    QUIET=1
    exec >>"$LOGFILE" 2>&1
    printf '\nFollow the details in another terminal: tail -f %s\n' "$LOGFILE" >&"$OUT_FD"
fi

# Dry run: the same screens and step lines, nothing is installed or changed.
if [ "$DRY_RUN" -eq 1 ]; then
    for title in "Packages (Docker, PHP, Composer, Node.js, nginx, certbot)" "Configuration" "Web server and certificate" "Mail stack (docker compose)" "Admin panel (composer, migrations, frontend)"; do
        step "$title"
        log "simulated, nothing is changed"
        echo "dry run: $title" >> "$LOGFILE"
        sleep 1
    done
    finish
    printf '\n(dry run: nothing was installed)\n' >&"$OUT_FD"
    exit 0
fi

# --- Packages -------------------------------------------------------------------------

step "Packages (Docker, PHP, Composer, Node.js, nginx, certbot)"
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

step "Configuration"
log "Root .env (mail stack)"
if [ ! -f "$ROOT/.env" ]; then
    cp "$ROOT/.env.example" "$ROOT/.env"
    set_env "$ROOT/.env" DB_PASSWORD "$(secret)"
    set_env "$ROOT/.env" DOVECOT_MASTER_PASSWORD "$(secret)"
    chmod 600 "$ROOT/.env"
else
    echo "Keeping the existing .env (secrets are not regenerated)."
    [ "$QUIET" -eq 1 ] && log "Keeping the existing .env, secrets are not regenerated"
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
# The sender of system e-mail (invitations, password reset). It must be a real mailbox, which is
# created in the panel after the install.
set_env "$ADMIN/.env" MAIL_FROM_ADDRESS "$MAIL_FROM"

# --- nginx and the certificate --------------------------------------------------------

step "Web server and certificate"
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

step "Mail stack (docker compose)"
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

step "Admin panel (composer, migrations, frontend)"
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

finish
