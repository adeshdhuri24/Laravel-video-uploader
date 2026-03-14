# Laravel Video Uploader

Upload large videos (chunked) through the browser. Files are stitched and stored on S3, and processing runs in the background with Laravel queues.

---

## What you need

- PHP 8.2+, Composer, Node/npm  
- MySQL (or something compatible)  
- An AWS account and an S3 bucket  

---

## Get it running

Clone the repo, then:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Fill in `.env`: database credentials, `APP_URL`, and your AWS keys (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`). Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` — the seeder will create one admin user from these.

If the app and the API live on the same domain (e.g. you’re using Herd or `php artisan serve`), add that host to `SANCTUM_STATEFUL_DOMAINS` — `localhost`,`127.0.0.1:8000`.

Then:

```bash
php artisan migrate
php artisan db:seed
npm install && npm run build
```

Start a queue worker (required — assembly and S3 upload run here; admin email uses a separate queue). Process both queues so uploads and notifications are handled:

```bash
php artisan queue:work --queue=uploads,notifications --timeout=600 --tries=3
```

In another terminal, run the app (`php artisan serve` or use Herd/Valet), open `APP_URL`, log in with the admin credentials, and use **Upload Video** from the dashboard.

---

## Tuning

- **Email when an upload finishes:** set `MAIL_*` in `.env` so the app can send the admin notification.  
- **PHP limits:** uploads are sent in chunks (default 10 MB). Set `upload_max_filesize` and `post_max_size` to at least ~15–20 MB so each chunk request works.  
- **Upload behaviour:** `.env.example` lists `UPLOAD_*` vars (chunk size, max file size, S3 prefix, etc.) if you want to change defaults.
