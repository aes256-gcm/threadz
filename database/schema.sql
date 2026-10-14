CREATE TABLE IF NOT EXISTS boards (
    id BIGSERIAL PRIMARY KEY,
    slug VARCHAR(16) UNIQUE NOT NULL CHECK (slug ~ '^[a-z0-9]+$'),
    name VARCHAR(80) NOT NULL,
    description VARCHAR(240) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS threads (
    id BIGSERIAL PRIMARY KEY,
    board_id BIGINT NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    subject VARCHAR(120) NOT NULL DEFAULT '',
    author VARCHAR(40) NOT NULL DEFAULT 'Anonymous',
    body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 8000),
    image_path VARCHAR(255),
    image_name VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    bumped_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS replies (
    id BIGSERIAL PRIMARY KEY,
    thread_id BIGINT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    author VARCHAR(40) NOT NULL DEFAULT 'Anonymous',
    body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 8000),
    image_path VARCHAR(255),
    image_name VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS threads_board_bumped_idx ON threads (board_id, bumped_at DESC);
CREATE INDEX IF NOT EXISTS replies_thread_created_idx ON replies (thread_id, created_at ASC);

INSERT INTO boards (slug, name, description) VALUES
    ('general', 'General', 'Anything that does not fit elsewhere.'),
    ('tech', 'Technology', 'Programming, hardware, software, and the web.'),
    ('creative', 'Creative', 'Art, photography, writing, and works in progress.')
ON CONFLICT (slug) DO NOTHING;

