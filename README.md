# Threadline

Threadline is a small anonymous imageboard built with PHP and PostgreSQL. It supports multiple boards, threads, replies, bump ordering, and optional image uploads.

## Local development

Run the application and database with Docker:

```bash
docker compose up --build
```

Open <http://localhost:8080>. The database schema and starter boards are created automatically on first startup.

## Zenith preview

Create a gitignored `.env.local` containing matching `DATABASE_PASSWORD` and `POSTGRES_PASSWORD` values, then run:

```bash
zenith check --upload-size
zenith up
```
