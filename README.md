# Threadline

A small anonymous PHP imageboard backed by PostgreSQL. It supports multiple boards, threads, replies, optional image uploads, and bump ordering.

## Run it

Docker is the only requirement:

```bash
docker compose up --build
```

Open <http://localhost:8080>. The first startup creates the schema and three starter boards automatically.

To stop it:

```bash
docker compose down
```

To also remove the local database and start fresh:

```bash
docker compose down -v
```

## Configuration

The defaults live in `docker-compose.yml`. Change `APP_NAME`, the database credentials, or `MAX_UPLOAD_MB` there for local use. In a non-Docker environment, copy `.env.example` values into your process environment, serve `public/` as the document root, enable `pdo_pgsql`, and apply `database/schema.sql` to PostgreSQL.

Uploads are stored in `public/uploads/`; production deployments should use persistent object storage and add moderation, rate limiting, and scheduled cleanup.
