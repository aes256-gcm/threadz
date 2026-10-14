<?php

declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

require __DIR__ . '/../src/app.php';

$pdo = db();
$user = current_user($pdo);
$boards = $pdo->query('SELECT * FROM boards ORDER BY name')->fetchAll();
$requestedSlug = preg_replace('/[^a-z0-9]/', '', (string) ($_GET['board'] ?? 'general'));
$boardStmt = $pdo->prepare('SELECT * FROM boards WHERE slug = :slug');
$boardStmt->execute(['slug' => $requestedSlug]);
$board = $boardStmt->fetch();

if (!$board) {
    http_response_code(404);
    $board = $boards[0] ?? null;
}
if (!$board) {
    http_response_code(503);
    exit('No boards are configured. Run database/schema.sql first.');
}

$threadId = filter_input(INPUT_GET, 'thread', FILTER_VALIDATE_INT) ?: null;
$returnPath = '/?board=' . urlencode($board['slug']) . ($threadId ? '&thread=' . $threadId : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $upload = null;

    try {
        if ($action === 'register') {
            $username = request_text('username', 24, true);
            $password = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $username)) throw new InvalidArgumentException('Username must be 3–24 letters, numbers, or underscores.');
            if (strlen($password) < 8 || strlen($password) > 128) throw new InvalidArgumentException('Password must be 8–128 characters.');

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash) RETURNING id');
                $stmt->execute(['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int) $stmt->fetchColumn();
            } catch (PDOException $error) {
                if ($error->getCode() === '23505') throw new InvalidArgumentException('That username is already taken.');
                throw $error;
            }
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            flash('success', 'Account created. Earn credits by posting.');
            redirect($returnPath);
        }

        if ($action === 'login') {
            $username = request_text('username', 24, true);
            $password = (string) ($_POST['password'] ?? '');
            $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE LOWER(username) = LOWER(:username)');
            $stmt->execute(['username' => $username]);
            $account = $stmt->fetch();
            if (!$account || !password_verify($password, $account['password_hash'])) throw new InvalidArgumentException('Username or password is incorrect.');
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            flash('success', 'Signed in.');
            redirect($returnPath);
        }

        if ($action === 'logout') {
            unset($_SESSION['user_id']);
            session_regenerate_id(true);
            flash('success', 'Signed out.');
            redirect($returnPath);
        }

        if ($action === 'unlock') {
            $user = require_user($user);
            $unlockId = filter_input(INPUT_POST, 'unlock_id', FILTER_VALIDATE_INT);
            if (!$unlockId) throw new InvalidArgumentException('Locked content not found.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id, creator_id, price FROM unlockables WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $unlockId]);
            $unlock = $stmt->fetch();
            if (!$unlock) throw new InvalidArgumentException('Locked content not found.');
            if ((int) $unlock['creator_id'] === (int) $user['id']) {
                $pdo->commit();
                redirect($returnPath);
            }
            $exists = $pdo->prepare('SELECT 1 FROM unlock_purchases WHERE unlockable_id = :unlockable_id AND buyer_id = :buyer_id');
            $exists->execute(['unlockable_id' => $unlockId, 'buyer_id' => $user['id']]);
            if ($exists->fetchColumn()) {
                $pdo->commit();
                redirect($returnPath);
            }

            $ids = [(int) $user['id'], (int) $unlock['creator_id']];
            sort($ids);
            $locks = $pdo->prepare('SELECT id FROM users WHERE id IN (:first, :second) ORDER BY id FOR UPDATE');
            $locks->execute(['first' => $ids[0], 'second' => $ids[1]]);
            $price = (int) $unlock['price'];
            $debit = $pdo->prepare('UPDATE users SET credits = credits - :debit WHERE id = :id AND credits >= :minimum');
            $debit->execute(['debit' => $price, 'minimum' => $price, 'id' => $user['id']]);
            if ($debit->rowCount() !== 1) throw new InvalidArgumentException('You do not have enough credits.');
            $pdo->prepare('UPDATE users SET credits = credits + :price WHERE id = :id')->execute(['price' => $price, 'id' => $unlock['creator_id']]);
            $pdo->prepare('INSERT INTO unlock_purchases (unlockable_id, buyer_id, price_paid) VALUES (:unlockable_id, :buyer_id, :price)')->execute(['unlockable_id' => $unlockId, 'buyer_id' => $user['id'], 'price' => $price]);
            $ledger = $pdo->prepare('INSERT INTO credit_transactions (user_id, amount, kind, reference_id, note) VALUES (:user_id, :amount, :kind, :reference_id, :note)');
            $ledger->execute(['user_id' => $user['id'], 'amount' => -$price, 'kind' => 'unlock_purchase', 'reference_id' => $unlockId, 'note' => 'Unlocked paid content']);
            $ledger->execute(['user_id' => $unlock['creator_id'], 'amount' => $price, 'kind' => 'unlock_sale', 'reference_id' => $unlockId, 'note' => 'Sold paid content']);
            $pdo->commit();
            flash('success', "Unlocked for {$price} credits.");
            redirect($returnPath);
        }

        if ($action === 'new_thread') {
            $user = require_user($user);
            $subject = request_text('subject', 120);
            $body = request_text('body', 8000, true);
            $unlock = requested_unlockable();
            $upload = save_upload('image');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO threads (board_id, user_id, subject, author, body, image_path, image_name) VALUES (:board_id, :user_id, :subject, :author, :body, :image_path, :image_name) RETURNING id');
            $stmt->execute([
                'board_id' => $board['id'], 'user_id' => $user['id'], 'subject' => $subject, 'author' => $user['username'], 'body' => $body,
                'image_path' => $upload['path'] ?? null, 'image_name' => $upload['name'] ?? null,
            ]);
            $newId = (int) $stmt->fetchColumn();
            create_unlockable($pdo, (int) $user['id'], $newId, null, $unlock);
            $earned = award_posting_credits($pdo, (int) $user['id'], THREAD_REWARD, 'thread_reward', $newId);
            $pdo->commit();
            flash('success', $earned ? "Thread posted. You earned {$earned} credits." : 'Thread posted. Daily credit limit already reached.');
            redirect('/?board=' . urlencode($board['slug']) . '&thread=' . $newId);
        }

        if ($action === 'reply') {
            $user = require_user($user);
            $postedThreadId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
            if (!$postedThreadId) throw new InvalidArgumentException('Thread not found.');
            $body = request_text('body', 8000, true);
            $unlock = requested_unlockable();
            $upload = save_upload('image');

            $pdo->beginTransaction();
            $exists = $pdo->prepare('SELECT id FROM threads WHERE id = :id AND board_id = :board_id FOR UPDATE');
            $exists->execute(['id' => $postedThreadId, 'board_id' => $board['id']]);
            if (!$exists->fetchColumn()) throw new InvalidArgumentException('Thread not found.');
            $stmt = $pdo->prepare('INSERT INTO replies (thread_id, user_id, author, body, image_path, image_name) VALUES (:thread_id, :user_id, :author, :body, :image_path, :image_name) RETURNING id');
            $stmt->execute([
                'thread_id' => $postedThreadId, 'user_id' => $user['id'], 'author' => $user['username'], 'body' => $body,
                'image_path' => $upload['path'] ?? null, 'image_name' => $upload['name'] ?? null,
            ]);
            $replyId = (int) $stmt->fetchColumn();
            create_unlockable($pdo, (int) $user['id'], null, $replyId, $unlock);
            $earned = award_posting_credits($pdo, (int) $user['id'], REPLY_REWARD, 'reply_reward', $replyId);
            $pdo->prepare('UPDATE threads SET bumped_at = NOW() WHERE id = :id')->execute(['id' => $postedThreadId]);
            $pdo->commit();
            flash('success', $earned ? "Reply posted. You earned {$earned} credit." : 'Reply posted. Daily credit limit already reached.');
            redirect('/?board=' . urlencode($board['slug']) . '&thread=' . $postedThreadId . '#replies');
        }

        throw new InvalidArgumentException('Unknown action.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (isset($upload['path'])) @unlink(__DIR__ . $upload['path']);
        flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'Something went wrong. Please try again.');
        redirect($returnPath);
    }
}

$user = current_user($pdo);
$earnedToday = $user ? posting_earnings_today($pdo, (int) $user['id']) : 0;
$activeThread = null;
$replies = [];
$threads = [];

if ($threadId) {
    $stmt = $pdo->prepare('SELECT t.*, COALESCE(u.username, t.author) AS display_author, COUNT(r.id)::int AS reply_count FROM threads t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN replies r ON r.thread_id = t.id WHERE t.id = :id AND t.board_id = :board_id GROUP BY t.id, u.username');
    $stmt->execute(['id' => $threadId, 'board_id' => $board['id']]);
    $activeThread = $stmt->fetch();
    if (!$activeThread) {
        http_response_code(404);
    } else {
        $activeThread = enrich_posts($pdo, [$activeThread], 'thread', $user ? (int) $user['id'] : null)[0];
        $stmt = $pdo->prepare('SELECT r.*, COALESCE(u.username, r.author) AS display_author FROM replies r LEFT JOIN users u ON u.id = r.user_id WHERE r.thread_id = :thread_id ORDER BY r.created_at ASC, r.id ASC');
        $stmt->execute(['thread_id' => $threadId]);
        $replies = enrich_posts($pdo, $stmt->fetchAll(), 'reply', $user ? (int) $user['id'] : null);
    }
} else {
    $stmt = $pdo->prepare('SELECT t.*, COALESCE(u.username, t.author) AS display_author, COUNT(r.id)::int AS reply_count FROM threads t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN replies r ON r.thread_id = t.id WHERE t.board_id = :board_id GROUP BY t.id, u.username ORDER BY t.bumped_at DESC LIMIT 30');
    $stmt->execute(['board_id' => $board['id']]);
    $threads = enrich_posts($pdo, $stmt->fetchAll(), 'thread', $user ? (int) $user['id'] : null);
}

$flash = take_flash();
$appName = env('APP_NAME', 'Threadline');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= e($board['name']) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<header class="site-header">
    <a class="brand" href="/"><?= e($appName) ?></a>
    <nav aria-label="Boards">
        <?php foreach ($boards as $navBoard): ?><a href="/?board=<?= urlencode($navBoard['slug']) ?>" <?= $navBoard['id'] === $board['id'] ? 'aria-current="page"' : '' ?>>/<?= e($navBoard['slug']) ?>/</a><?php endforeach; ?>
    </nav>
    <?php if ($user): ?>
        <div class="account"><strong>@<?= e($user['username']) ?></strong> · <?= (int) $user['credits'] ?> credits · <?= $earnedToday ?>/<?= DAILY_EARNING_CAP ?> earned today
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Sign out</button></form>
        </div>
    <?php endif; ?>
</header>

<main id="content">
    <?php if (!$user): ?>
        <section class="auth-panel">
            <details><summary>Sign in</summary><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login"><label>Username <input name="username" maxlength="24" autocomplete="username" required></label><label>Password <input name="password" type="password" maxlength="128" autocomplete="current-password" required></label><button type="submit">Sign in</button></form></details>
            <details><summary>Create account</summary><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="register"><label>Username <input name="username" minlength="3" maxlength="24" pattern="[A-Za-z0-9_]+" autocomplete="username" required></label><label>Password <input name="password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required></label><button type="submit">Create account</button></form></details>
        </section>
    <?php endif; ?>

    <?php if ($flash): ?><div class="notice notice--<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if ($threadId && !$activeThread): ?>
        <section class="empty-state"><h2>Thread not found</h2><p>It may have been removed, or it belongs to another board.</p><a class="button" href="/?board=<?= urlencode($board['slug']) ?>">Back to /<?= e($board['slug']) ?>/</a></section>
    <?php elseif ($activeThread): ?>
        <div class="page-actions"><a href="/?board=<?= urlencode($board['slug']) ?>">← Back to board</a></div>
        <?php render_post($activeThread, true, null, $user); ?>
        <section class="composer" aria-labelledby="reply-title">
            <?php if ($user): ?>
                <details open><summary id="reply-title">Reply to thread · earn 1 credit</summary>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="<?= (int) $activeThread['id'] ?>">
                        <div class="form-row"><label for="reply-body">Comment</label><textarea id="reply-body" name="body" maxlength="8000" rows="5" required></textarea></div>
                        <div class="form-row"><label for="reply-image">Image <span>optional, max <?= (int) env('MAX_UPLOAD_MB', '5') ?> MB</span></label><input id="reply-image" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></div>
                        <?php render_unlock_fields('reply'); ?>
                        <button type="submit">Post reply · earn 1 credit</button>
                    </form>
                </details>
            <?php else: ?><p class="login-prompt">Sign in above to reply.</p><?php endif; ?>
        </section>
        <section class="replies" id="replies" aria-label="Replies">
            <?php if (!$replies): ?><p class="empty-line">No replies yet.</p><?php endif; ?>
            <?php foreach ($replies as $reply) render_post($reply, false, null, $user); ?>
        </section>
    <?php else: ?>
        <section class="composer" aria-labelledby="thread-title">
            <?php if ($user): ?>
                <details><summary id="thread-title">Start a new thread · earn 2 credits</summary>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="new_thread">
                        <div class="form-row"><label for="subject">Subject <span>optional</span></label><input id="subject" name="subject" maxlength="120"></div>
                        <div class="form-row"><label for="body">Comment</label><textarea id="body" name="body" maxlength="8000" rows="6" required></textarea></div>
                        <div class="form-row"><label for="image">Image <span>optional, max <?= (int) env('MAX_UPLOAD_MB', '5') ?> MB</span></label><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></div>
                        <?php render_unlock_fields('thread'); ?>
                        <button type="submit">Create thread · earn 2 credits</button>
                    </form>
                </details>
            <?php else: ?><p class="login-prompt">Sign in above to create a thread.</p><?php endif; ?>
        </section>
        <section class="thread-list" aria-label="Threads">
            <?php if (!$threads): ?><div class="empty-state"><h2>No threads yet</h2><p>Create an account and start the conversation.</p></div><?php endif; ?>
            <?php foreach ($threads as $thread) render_post($thread, true, $board['slug'], $user); ?>
        </section>
    <?php endif; ?>
</main>

<footer class="site-footer">Earn up to 5 credits per day by posting. Spend credits to unlock creator content.</footer>
</body>
</html>
