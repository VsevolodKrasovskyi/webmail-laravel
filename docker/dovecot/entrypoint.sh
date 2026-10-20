#!/bin/sh
set -e

: "${DB_HOST:=postgres}"
: "${DB_PORT:=5432}"
: "${DB_DATABASE:=mailserver}"
: "${DB_USERNAME:=mailserver}"
: "${DB_PASSWORD:?DB_PASSWORD must be set}"
: "${DOVECOT_MASTER_PASSWORD:?DOVECOT_MASTER_PASSWORD must be set (used by the admin panel to open a mailbox's webmail without its real password)}"
: "${APP_DOMAIN:?APP_DOMAIN must be set}"

export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DOVECOT_MASTER_PASSWORD APP_DOMAIN

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
until nc -z "${DB_HOST}" "${DB_PORT}" >/dev/null 2>&1; do
    sleep 1
done

envsubst < /etc/dovecot/dovecot-sql.conf.ext.template > /etc/dovecot/dovecot-sql.conf.ext
chown root:root /etc/dovecot/dovecot-sql.conf.ext
chmod 600 /etc/dovecot/dovecot-sql.conf.ext

envsubst < /etc/dovecot/master-users.template > /etc/dovecot/master-users
chown dovecot:dovecot /etc/dovecot/master-users
chmod 600 /etc/dovecot/master-users

envsubst '${APP_DOMAIN}' < /etc/dovecot/conf.d/10-ssl.conf.template > /etc/dovecot/conf.d/10-ssl.conf

echo "Waiting for TLS certificate at /etc/letsencrypt/live/${APP_DOMAIN}..."
until [ -f "/etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem" ] && [ -f "/etc/letsencrypt/live/${APP_DOMAIN}/privkey.pem" ]; do
    sleep 1
done

mkdir -p /var/mail/vhosts
chown -R vmail:vmail /var/mail/vhosts

exec dovecot -F
