FROM debian:bookworm-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        dovecot-core \
        dovecot-imapd \
        dovecot-lmtpd \
        dovecot-pgsql \
        dovecot-sieve \
        gettext-base \
        netcat-openbsd \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd -g 5000 vmail && useradd -u 5000 -g vmail -s /usr/sbin/nologin -d /var/mail/vhosts vmail \
    && mkdir -p /var/mail/vhosts

COPY conf.d/ /etc/dovecot/conf.d/
COPY dovecot-sql.conf.ext.template /etc/dovecot/dovecot-sql.conf.ext.template
COPY master-users.template /etc/dovecot/master-users.template
COPY 10-ssl.conf.template /etc/dovecot/conf.d/10-ssl.conf.template
COPY sieve/ /etc/dovecot/sieve/
RUN chown -R vmail:vmail /etc/dovecot/sieve
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 143 993 24 12345

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
