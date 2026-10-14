# Threadline

Threadline is a small account-based imageboard built with Go, HTMX, and PostgreSQL. It supports multiple boards, threads, replies, bump ordering, optional image uploads, and creator content unlocked with credits.

Accounts earn 2 credits for a new thread and 1 for a reply, capped at 5 earned credits per day. Creators can attach tutorial text, links, or download links to posts and choose an unlock price from 1–100 credits. Unlock purchases transfer credits directly to the creator.

## Local development

Run the application and database with Docker:

```bash
docker compose up --build
```

Open <http://localhost:8080>. The database schema and starter boards are created automatically on first startup.

For development without Docker, provide the `DB_*` variables used in `docker-compose.yml` and run `go run .`.

## Zenith preview

Create a gitignored `.env.local` containing matching `DATABASE_PASSWORD` and `POSTGRES_PASSWORD` values, then run:

```bash
zenith check --upload-size
zenith up
```
