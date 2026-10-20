# Mail Server

Self-hosted mail server stack: Postfix (SMTP) + Dovecot (IMAP/LMTP) + Rspamd
(antispam, DKIM signing) + PostgreSQL (virtual domains/mailboxes/aliases) +
a Laravel/Vue (Inertia) admin panel, fronted by nginx + certbot for TLS —
the whole thing is a single self-contained `docker compose up`, no manual
certificate handling required.

## Architecture

```
                        ┌────────────────┐
        25/587/465 ───▶ │    postfix     │──▶ milter ──▶ ┌─────────┐
        (SMTP)          │ (virtual smtp) │               │ rspamd  │
                        └───────┬────────┘◀── DKIM sign ──└─────────┘
                                │ LMTP
                        ┌───────▼────────┐
        143/993 ───────▶│    dovecot     │  (IMAP, Maildir, quota)
        (IMAP)          └───────┬────────┘
                                │
                        ┌───────▼────────┐
                        │   postgres     │◀── domains / mailboxes / aliases
                        └───────▲────────┘
                                │
                        ┌───────┴────────┐
         80/443 ───────▶│ admin-nginx +  │  Laravel + Vue (Inertia) panel
        (HTTP/S)        │   admin-php    │  /dashboard, /domains, /webmail...
                        └───────┬────────┘
                                │ ACME http-01 (webroot)
                        ┌───────▼────────┐
                        │    certbot     │  issues/renews the one TLS cert
                        └────────────────┘  shared by nginx, postfix, dovecot
```

All mail-routing state (domains, mailboxes, aliases, password hashes, quotas)
lives in one Postgres database. Postfix and Dovecot read it directly via SQL
lookup tables; the admin panel is the only thing that writes to it.

- **postfix** — accepts mail on 25 (SMTP), 587 (submission, STARTTLS),
  465 (SMTPS). Looks up virtual domains/mailboxes/aliases in Postgres,
  authenticates senders via Dovecot SASL, hands every message to rspamd as a
  milter, and delivers accepted mail to Dovecot over LMTP.
- **dovecot** — IMAP (143/993) + LMTP (24, internal only) + SASL auth backend
  for Postfix (12345, internal only). Mailboxes are Maildir under
  `/var/mail/vhosts/<domain>/<local-part>`, one Unix user (`vmail`, uid 5000)
  owns everything, per-mailbox quota comes straight from Postgres.
- **rspamd** — antispam engine, wired into Postfix as a milter
  (`inet:rspamd:11332`). Also signs outgoing mail with DKIM using keys the
  admin panel generates per domain. Uses Redis for greylisting/ratelimits.
- **admin-php / admin-nginx** — Laravel 13 backend + a Vue 3 / Inertia
  frontend (no separate API, no SPA build step to manage by hand — Vite
  builds it into the image). Covers domains/mailboxes/aliases CRUD, a
  dashboard, and a small webmail page (read + send mail for any mailbox,
  talking to Dovecot/Postfix over IMAP/SMTP). admin-php only ever touches
  Postgres rows and DKIM key files — it has no privileged access to mail
  delivery itself.
- **certbot** — requests and renews a single Let's Encrypt certificate for
  `APP_DOMAIN` via the HTTP-01 webroot challenge, and drops it into the
  `certs` volume that nginx, postfix and dovecot all read from. Falls back to
  a self-signed certificate (generated once by the one-shot `certs-init`
  service) when the domain isn't publicly reachable yet, so the stack always
  boots.

## First run

```bash
cp .env.example .env
# edit .env: set APP_DOMAIN/MAIL_DOMAIN to your real hostname/domain,
# CERTBOT_EMAIL, a real DB_PASSWORD, and ADMIN_EMAIL/ADMIN_PASSWORD.

docker compose up -d --build
```

`APP_DOMAIN` must already have a public A/AAAA record pointing at this
server, with port 80 reachable from the internet, before certbot can issue a
real certificate — until then the stack serves a self-signed one so
everything still works for local testing.

On first boot `admin-php` runs migrations and seeds the admin user from
`ADMIN_EMAIL`/`ADMIN_PASSWORD`. Open `https://<APP_DOMAIN>/` (or
`https://localhost/` for local testing — accept the self-signed cert
warning) and log in.

To add mail:

1. **Domains** → add `example.com`. This auto-generates a 2048-bit DKIM
   keypair; the private key is written to the shared `dkim` volume (read by
   rspamd), and the public key is shown next to the domain (click "DNS") as
   a ready-to-paste DNS TXT value.
2. **Mailboxes** → add `user@example.com` + password. Postfix/Dovecot pick it
   up immediately, no restart needed (everything is a live SQL lookup).
3. **Aliases** → `info@example.com → user@example.com`, or `@example.com`
   (catch-all) → any mailbox.
4. **Webmail** → log in with any mailbox's own email/password to read its
   inbox or send mail through it, right from the admin panel.

## Required DNS records per domain

- **MX**: `example.com. MX 10 mail.example.com.`
- **A/AAAA**: `mail.example.com` (i.e. `APP_DOMAIN`) → this server's IP.
- **PTR (reverse DNS)**: the server's IP must resolve back to `APP_DOMAIN`
  — set this with your hosting provider, not in this stack. Most receiving
  servers reject mail from hosts without matching PTR records.
- **SPF**: `example.com. TXT "v=spf1 mx ~all"`
- **DKIM**: `<selector>._domainkey.example.com. TXT` with the value shown
  next to the domain in the admin panel (selector is always `mail`).
- **DMARC**: `_dmarc.example.com. TXT "v=DMARC1; p=quarantine; rua=mailto:postmaster@example.com"`

## TLS certificates

Handled automatically — no manual steps:

- `certs-init` (one-shot) writes a self-signed certificate to the shared
  `certs` volume on first boot, so nginx/postfix/dovecot always have
  something to start with.
- `certbot` (long-running) then requests a real Let's Encrypt certificate for
  `APP_DOMAIN` over HTTP-01 (served by `admin-nginx` at
  `/.well-known/acme-challenge/`), overwrites the same files once it
  succeeds, and re-checks for renewal every 12 hours. `admin-nginx` reloads
  itself every 6 hours to pick up a renewed certificate — postfix/dovecot
  pick up a renewed one on their next restart (`docker compose restart
  postfix dovecot` after a renewal if you want it sooner; TLS libraries
  there don't hot-reload certs).
- If `APP_DOMAIN` isn't a real, publicly-reachable domain (e.g. local
  testing), certbot logs a warning and the self-signed certificate stays in
  place — everything still works, browsers/mail clients will just warn about
  the certificate.
- Set `CERTBOT_STAGING=true` while testing against a real domain to avoid
  hitting Let's Encrypt's production rate limits.

## Cloudflare DNS sync (optional)

If a domain's DNS is hosted on Cloudflare, the admin panel can create/update
its MX, SPF, DKIM and DMARC records for you (Domains → click a domain name →
"Sync DNS via Cloudflare"). It never touches the PTR record or anything
outside those four records, and never creates zones — the domain must already
exist in your Cloudflare account.

Setup:

1. Create a Cloudflare API token at <https://dash.cloudflare.com/profile/api-tokens>
   with **Zone → DNS → Edit** permission, scoped to the zones you want this
   stack to manage.
2. Set `CLOUDFLARE_API_TOKEN` in `.env` and `docker compose up -d admin-php`.
3. The "Sync DNS via Cloudflare" button appears once a token is set; without
   one it's disabled and DNS stays something you configure by hand (see
   above).

## Notes / limitations

- **Local testing quirk**: rspamd treats mail originating from RFC1918/local
  addresses as trusted and skips most checks (SPF/DKIM verification, spam
  headers) for it — this is standard rspamd behavior, not a bug. When testing
  from your own machine through Docker's port forwarding, the apparent client
  IP is often a private address, so you won't see `X-Spam-*` /
  `Authentication-Results` headers on self-addressed test mail. Real inbound
  mail from the public internet is scored and tagged normally.
- **rspamd web UI** (score/history/training) is not exposed by default. To
  use it: set a password with `docker compose exec rspamd rspamadm pw` and
  expose port 11334 for `rspamd` in `docker-compose.yml`.
- **Backups**: `pgdata` and `vmail` need backing up for disaster recovery
  (mail routing state + actual mail). `dkim` also matters — losing it means
  re-adding domains generates new DKIM keys, invalidating the DNS records you
  published. `letsencrypt` is nice to keep too (avoids re-issuing on
  restore) but is reconstructible by certbot if lost.
- The webmail page is an admin convenience (log in with any mailbox's own
  credentials, nothing is stored beyond an encrypted session value) — it is
  not a general end-user self-service portal with its own accounts/ACLs.
- Cloudflare sync only manages MX/SPF/DKIM/DMARC for each mail *domain*'s own
  zone. The `APP_DOMAIN` A/AAAA record (the mail server's own IP) and the PTR
  record are not automated — set those once by hand (see DNS records above).
  The Cloudflare API calls themselves are implemented against the documented
  v4 REST API but haven't been exercised against a live Cloudflare account in
  this repo's testing — try it on one domain first before relying on it.
