FROM debian:bookworm-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        postfix \
        postfix-pgsql \
        gettext-base \
        openssl \
        ca-certificates \
        netcat-openbsd \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd -g 5000 vmail && useradd -u 5000 -g vmail -s /usr/sbin/nologin -d /var/mail/vhosts vmail \
    && mkdir -p /var/mail/vhosts /etc/postfix/pgsql /etc/postfix/certs \
    && chown -R vmail:vmail /var/mail/vhosts

COPY main.cf.template /etc/postfix/main.cf.template
COPY master.cf /etc/postfix/master.cf
COPY pgsql/*.template /etc/postfix/pgsql/
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 25 587 465

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
