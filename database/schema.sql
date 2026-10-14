CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(24) NOT NULL CHECK (username ~ '^[A-Za-z0-9_]{3,24}$'),
    password_hash VARCHAR(255) NOT NULL,
    credits INTEGER NOT NULL DEFAULT 0 CHECK (credits >= 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS users_username_lower_idx ON users (LOWER(username));
ALTER TABLE users ALTER COLUMN credits SET DEFAULT 0;

CREATE TABLE IF NOT EXISTS boards (
    id BIGSERIAL PRIMARY KEY,
    slug VARCHAR(16) UNIQUE NOT NULL CHECK (slug ~ '^[a-z0-9]+$'),
    name VARCHAR(80) NOT NULL,
    description VARCHAR(240) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS threads (
    id BIGSERIAL PRIMARY KEY,
    board_id BIGINT NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
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
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    author VARCHAR(40) NOT NULL DEFAULT 'Anonymous',
    body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 8000),
    image_path VARCHAR(255),
    image_name VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Upgrade databases created by earlier versions of Threadline.
ALTER TABLE threads ADD COLUMN IF NOT EXISTS user_id BIGINT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE replies ADD COLUMN IF NOT EXISTS user_id BIGINT REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS unlockables (
    id BIGSERIAL PRIMARY KEY,
    creator_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    thread_id BIGINT REFERENCES threads(id) ON DELETE CASCADE,
    reply_id BIGINT REFERENCES replies(id) ON DELETE CASCADE,
    content_type VARCHAR(16) NOT NULL CHECK (content_type IN ('tutorial', 'link', 'download')),
    title VARCHAR(120) NOT NULL,
    content TEXT NOT NULL CHECK (char_length(content) BETWEEN 1 AND 12000),
    price INTEGER NOT NULL CHECK (price BETWEEN 1 AND 100),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (num_nonnulls(thread_id, reply_id) = 1)
);

CREATE UNIQUE INDEX IF NOT EXISTS unlockables_thread_idx ON unlockables (thread_id) WHERE thread_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS unlockables_reply_idx ON unlockables (reply_id) WHERE reply_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS unlock_purchases (
    id BIGSERIAL PRIMARY KEY,
    unlockable_id BIGINT NOT NULL REFERENCES unlockables(id) ON DELETE CASCADE,
    buyer_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    price_paid INTEGER NOT NULL CHECK (price_paid > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (unlockable_id, buyer_id)
);

CREATE TABLE IF NOT EXISTS credit_transactions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount INTEGER NOT NULL CHECK (amount <> 0),
    kind VARCHAR(32) NOT NULL CHECK (kind IN ('thread_reward', 'reply_reward', 'unlock_purchase', 'unlock_sale', 'signup_bonus', 'thread_fee', 'reply_fee')),
    reference_id BIGINT,
    note VARCHAR(160) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE credit_transactions DROP CONSTRAINT IF EXISTS credit_transactions_kind_check;
ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_kind_check CHECK (kind IN ('thread_reward', 'reply_reward', 'unlock_purchase', 'unlock_sale', 'signup_bonus', 'thread_fee', 'reply_fee'));

CREATE INDEX IF NOT EXISTS threads_board_bumped_idx ON threads (board_id, bumped_at DESC);
CREATE INDEX IF NOT EXISTS replies_thread_created_idx ON replies (thread_id, created_at ASC);
CREATE INDEX IF NOT EXISTS credit_transactions_user_day_idx ON credit_transactions (user_id, created_at DESC);

INSERT INTO boards (slug, name, description) VALUES
    ('general', 'General', 'Anything that does not fit elsewhere.'),
    ('tech', 'Technology', 'Programming, hardware, software, and the web.'),
    ('creative', 'Creative', 'Art, photography, writing, and works in progress.')
ON CONFLICT (slug) DO NOTHING;
