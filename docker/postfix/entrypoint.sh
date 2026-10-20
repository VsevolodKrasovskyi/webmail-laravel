#!/bin/sh
set -e

: "${DB_HOST:=postgres}"
: "${DB_PORT:=5432}"
: "${DB_DATABASE:=mailserver}"
: "${DB_USERNAME:=mailserver}"
: "${DB_PASSWORD:?DB_PASSWORD must be set}"
: "${APP_DOMAIN:=mail.example.com}"
: "${MAIL_DOMAIN:=example.com}"

export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD APP_DOMAIN MAIL_DOMAIN

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
until nc -z "${DB_HOST}" "${DB_PORT}" >/dev/null 2>&1; do
    sleep 1
done

# Only substitute our own placeholders; postfix's native $name references
# (e.g. $mydomain, $smtpd_milters) must be left alone for postfix itself to expand.
vars='${DB_HOST} ${DB_PORT} ${DB_DATABASE} ${DB_USERNAME} ${DB_PASSWORD} ${APP_DOMAIN} ${MAIL_DOMAIN}'

envsubst "$vars" < /etc/postfix/main.cf.template > /etc/postfix/main.cf
for f in /etc/postfix/pgsql/*.template; do
    out="${f%.template}"
    envsubst "$vars" < "$f" > "$out"
done

chown root:postfix /etc/postfix/pgsql/*.cf
chmod 640 /etc/postfix/pgsql/*.cf

echo "Waiting for TLS certificate at /etc/letsencrypt/live/${APP_DOMAIN}..."
until [ -f "/etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem" ] && [ -f "/etc/letsencrypt/live/${APP_DOMAIN}/privkey.pem" ]; do
    sleep 1
done

mkdir -p /var/spool/postfix/pid
postfix check
exec postfix start-fg
