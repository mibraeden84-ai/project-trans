# Deploy (Public Website)

This project can run like a normal public website using Docker Compose:

- PHP + Apache (app)
- PostgreSQL (database)
- Optional Caddy (HTTPS + domain + automatic SSL)

Persistent storage:

- Database data (`pgdata` volume)
- Uploaded files (`uploads` volume)

## Option A: Public HTTPS website (recommended)

You need:

- A VPS/server with a public IP
- A domain name pointing to that IP (DNS A record)
- Docker + Docker Compose installed on the server

### 1) Copy files to your server

Copy the whole `website/` folder to the server.

### 2) Configure environment

In the `website/` folder:

- Copy `.env.example` to `.env`
- Add a real domain:

```bash
SITE_DOMAIN=your-domain.com
SITE_URL=https://your-domain.com
```

### 3) Start (HTTPS)

```bash
docker compose up -d --build
docker compose -f docker-compose.prod.yml up -d
```

Open:

- `https://your-domain.com`

## Option B: Public HTTP (no SSL)

Install Docker + Docker Compose, then copy the `website/` folder to the server.

### 1) Configure environment

In the `website/` folder:

- Copy `.env.example` to `.env`
- Update:
  - `SITE_URL` to your real domain/IP (example: `http://your-domain.com`)
  - `DB_PASS` to a strong password

### 2) Start

Run:

```bash
docker compose up -d --build
```

Then open:

- `SITE_URL` (or `http://SERVER_IP:APP_PORT`)

## Notes

- The database schema is auto-created on first run from `schema.sql`.
- `config.php` uses a project-local session path (`website/tmp/sessions`) to avoid permission issues on hosts where PHP can't write to system temp.
