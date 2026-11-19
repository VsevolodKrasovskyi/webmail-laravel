#!/usr/bin/env bash
#
# Update an installed stack: pull the new code, back up the database, rebuild the mail
# containers, update the panel (composer, migrations, frontend) and reload php-fpm.
# Needs no questions, the values come from the existing .env files.
#
#   sudo ./update.sh
#
# Run it from the repository that install.sh set up. The panel runs on the host (php-fpm and
# nginx), only Postgres, Redis, Postfix, Dovecot and Rspamd are containers, so both parts are
# updated here.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADMIN="$ROOT/admin"
WEB_USER="www-data"
BACKUP_DIR="/var/backups/mailserver"
KEEP_BACKUPS=10

DO_GIT=1
DO_BACKUP=1
DO_PULL_IMAGES=0
FORCE=0
DOWN=0
BACKUP_FILE=""

log()  { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# A failed update must not leave the panel in maintenance mode.
on_error() {
    local line="$1"
    printf '\033[1;31mError:\033[0m Update failed on line %s.\n' "$line" >&2
    if [ "$DOWN" -eq 1 ]; then
        (cd "$ADMIN" && php artisan up >/dev/null 2>&1) && warn "The panel was switched back on, it may be half updated."
    fi
    if [ -n "$BACKUP_FILE" ] && [ -f "$BACKUP_FILE" ]; then
        printf 'The database was backed up before the change: %s\n' "$BACKUP_FILE" >&2
        printf 'Restore: gunzip -c %s | docker compose exec -T postgres psql -U <user> <database>\n' "$BACKUP_FILE" >&2
    fi
    printf 'Fix the cause and run ./update.sh again, it is safe to repeat.\n' >&2
    exit 1
}
trap 'on_error $LINENO' ERR

usage() {
    cat <<EOF
Usage: sudo ./update.sh [options]

  --no-git       Do not run git pull, update with the code as it is
  --no-backup    Skip the database backup before the migrations
  --pull         Also pull newer images of Postgres, Redis and Rspamd (off by default,
                 the database image is not upgraded on its own)
  --force        Run the update even when git reports no new commits
  -h, --help     This text

The backup goes to $BACKUP_DIR, the last $KEEP_BACKUPS are kept.
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --no-git) DO_GIT=0; shift ;;
        --no-backup) DO_BACKUP=0; shift ;;
        --pull) DO_PULL_IMAGES=1; shift ;;
        --force) FORCE=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage; die "Unknown option: $1" ;;
    esac
done

# --- Checks ---------------------------------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo ./update.sh"
[ -f "$ROOT/.env" ] && [ -f "$ADMIN/.env" ] || die "No .env files here. Nothing is installed in $ROOT yet, run ./install.sh first."
for cmd in docker php composer npm; do
    command -v "$cmd" >/dev/null || die "$cmd is not installed, run ./install.sh first."
done
docker compose version >/dev/null 2>&1 || die "The docker compose plugin is missing."

get_env() { grep -E "^$2=" "$1" 2>/dev/null | head -1 | cut -d= -f2- || true; }
DB_USERNAME="$(get_env "$ROOT/.env" DB_USERNAME)"
DB_DATABASE="$(get_env "$ROOT/.env" DB_DATABASE)"
[ -n "$DB_USERNAME" ] && [ -n "$DB_DATABASE" ] || die "DB_USERNAME and DB_DATABASE must be set in $ROOT/.env."

# --- Code -----------------------------------------------------------------------------

OLD_REV="" NEW_REV=""
if [ "$DO_GIT" -eq 1 ] && [ -d "$ROOT/.git" ]; then
    log "Code (git pull)"
    cd "$ROOT"
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        git status --short --untracked-files=no >&2
        die "There are uncommitted changes in tracked files. Commit or stash them, or run with --no-git."
    fi
    OLD_REV="$(git rev-parse --short HEAD)"
    git pull --ff-only
    NEW_REV="$(git rev-parse --short HEAD)"

    if [ "$OLD_REV" = "$NEW_REV" ] && [ "$FORCE" -ne 1 ]; then
        note "Already up to date ($NEW_REV). Nothing to do, use --force to rebuild anyway."
        exit 0
    fi
    [ "$OLD_REV" != "$NEW_REV" ] && git --no-pager log --oneline "$OLD_REV..$NEW_REV" | sed 's/^/    /'
elif [ "$DO_GIT" -eq 1 ]; then
    warn "$ROOT is not a git repository, updating with the code as it is."
fi

# --- Database backup ------------------------------------------------------------------

if [ "$DO_BACKUP" -eq 1 ]; then
    log "Database backup"
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/db-$(date +%Y%m%d-%H%M%S).sql.gz"
    if (cd "$ROOT" && docker compose exec -T postgres pg_dump -U "$DB_USERNAME" "$DB_DATABASE") | gzip > "$BACKUP_FILE" && [ -s "$BACKUP_FILE" ]; then
        chmod 600 "$BACKUP_FILE"
        note "$BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"
        # Keep the newest backups only.
        # shellcheck disable=SC2012
        ls -1t "$BACKUP_DIR"/db-*.sql.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
    else
        rm -f "$BACKUP_FILE"
        BACKUP_FILE=""
        die "The database backup failed, nothing was changed. Is the stack running? (docker compose ps)"
    fi
fi

# --- Mail stack -----------------------------------------------------------------------

log "Mail stack (docker compose)"
cd "$ROOT"
if [ "$DO_PULL_IMAGES" -eq 1 ]; then
    docker compose pull --ignore-buildable
fi
docker compose up -d --build

# --- Panel ----------------------------------------------------------------------------

log "Admin panel"
cd "$ADMIN"

# Visitors see a short maintenance page while the code and the database change.
php artisan down --retry=60 >/dev/null 2>&1 && DOWN=1

COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan optimize:clear --no-interaction >/dev/null
php artisan migrate --force --no-interaction
npm ci --silent --no-audit --no-fund
npm run build --silent

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache
php artisan optimize --no-interaction >/dev/null
chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache

# php-fpm keeps the old code in its opcache until it is reloaded.
FPM="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
systemctl reload "$FPM" 2>/dev/null || systemctl restart "$FPM" 2>/dev/null || warn "Could not reload $FPM, reload php-fpm by hand."

if [ "$DOWN" -eq 1 ]; then
    php artisan up >/dev/null
    DOWN=0
fi

# --- Done -----------------------------------------------------------------------------

printf '\n\033[1;32mUpdated.\033[0m'
[ -n "$NEW_REV" ] && printf ' %s -> %s' "$OLD_REV" "$NEW_REV"
printf '\n'
[ -n "$BACKUP_FILE" ] && note "Database backup: $BACKUP_FILE"
note "Containers: cd $ROOT && docker compose ps"
note "The nginx site and the .env files are not changed by an update. To change the host name, domain or"
note "certificate run ./install.sh again."
