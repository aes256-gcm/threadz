BEGIN;

INSERT INTO users (username, password_hash, credits) VALUES
    ('economy_creator', 'test-only', 5),
    ('economy_buyer', 'test-only', 5);

INSERT INTO threads (board_id, user_id, subject, author, body)
SELECT b.id, u.id, 'Credit test', u.username, 'Test thread'
FROM boards b, users u
WHERE b.slug = 'general' AND u.username = 'economy_creator';

INSERT INTO credit_transactions (user_id, amount, kind, reference_id, note)
SELECT u.id, 2, 'thread_reward', t.id, 'test reward'
FROM users u JOIN threads t ON t.user_id = u.id
WHERE u.username = 'economy_creator';

UPDATE users SET credits = credits + 2 WHERE username = 'economy_creator';

INSERT INTO unlockables (creator_id, thread_id, content_type, title, content, price)
SELECT u.id, t.id, 'tutorial', 'Test tutorial', 'Locked test content', 3
FROM users u JOIN threads t ON t.user_id = u.id
WHERE u.username = 'economy_creator';

UPDATE users SET credits = credits - 3 WHERE username = 'economy_buyer';
UPDATE users SET credits = credits + 3 WHERE username = 'economy_creator';

INSERT INTO unlock_purchases (unlockable_id, buyer_id, price_paid)
SELECT x.id, u.id, 3 FROM unlockables x, users u
WHERE x.title = 'Test tutorial' AND u.username = 'economy_buyer';

INSERT INTO credit_transactions (user_id, amount, kind, reference_id, note)
SELECT u.id, -3, 'unlock_purchase', x.id, 'test purchase' FROM users u, unlockables x
WHERE u.username = 'economy_buyer' AND x.title = 'Test tutorial';

INSERT INTO credit_transactions (user_id, amount, kind, reference_id, note)
SELECT u.id, 3, 'unlock_sale', x.id, 'test sale' FROM users u, unlockables x
WHERE u.username = 'economy_creator' AND x.title = 'Test tutorial';

SELECT 1 / (
    (SELECT credits = 10 FROM users WHERE username = 'economy_creator')
    AND (SELECT credits = 2 FROM users WHERE username = 'economy_buyer')
    AND (SELECT SUM(amount) = 2 FROM credit_transactions ct JOIN users u ON u.id = ct.user_id WHERE u.username = 'economy_creator' AND kind IN ('thread_reward', 'reply_reward'))
    AND (SELECT COUNT(*) = 1 FROM unlock_purchases)
)::integer AS assertions_passed;

ROLLBACK;
