# Admin panel

Laravel 13, Vue 3 and Inertia admin panel with webmail. Setup, cron for scheduled mail and configuration are described in the [root README](../README.md).

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm ci && npm run dev      # or npm run build
php artisan serve
```
