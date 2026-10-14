<?php

declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

require __DIR__ . '/../src/app.php';

$pdo = db();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        $author = request_text('name', 40) ?: 'Anonymous';
        $body = request_text('body', 8000, true);
        $upload = save_upload('image');

        if ($action === 'new_thread') {
            $subject = request_text('subject', 120);
            $stmt = $pdo->prepare('INSERT INTO threads (board_id, subject, author, body, image_path, image_name) VALUES (:board_id, :subject, :author, :body, :image_path, :image_name) RETURNING id');
            $stmt->execute([
                'board_id' => $board['id'], 'subject' => $subject, 'author' => $author, 'body' => $body,
                'image_path' => $upload['path'] ?? null, 'image_name' => $upload['name'] ?? null,
            ]);
            $newId = (int) $stmt->fetchColumn();
            flash('success', 'Thread posted.');
            redirect('/?board=' . urlencode($board['slug']) . '&thread=' . $newId);
        }

        if ($action === 'reply') {
            $postedThreadId = filter_input(INPUT_POST, 'thread_id', FILTER_VALIDATE_INT);
            if (!$postedThreadId) {
                throw new InvalidArgumentException('Thread not found.');
            }
            $pdo->beginTransaction();
            $exists = $pdo->prepare('SELECT id FROM threads WHERE id = :id AND board_id = :board_id FOR UPDATE');
            $exists->execute(['id' => $postedThreadId, 'board_id' => $board['id']]);
            if (!$exists->fetchColumn()) {
                throw new InvalidArgumentException('Thread not found.');
            }
            $stmt = $pdo->prepare('INSERT INTO replies (thread_id, author, body, image_path, image_name) VALUES (:thread_id, :author, :body, :image_path, :image_name)');
            $stmt->execute([
                'thread_id' => $postedThreadId, 'author' => $author, 'body' => $body,
                'image_path' => $upload['path'] ?? null, 'image_name' => $upload['name'] ?? null,
            ]);
            $pdo->prepare('UPDATE threads SET bumped_at = NOW() WHERE id = :id')->execute(['id' => $postedThreadId]);
            $pdo->commit();
            flash('success', 'Reply posted.');
            redirect('/?board=' . urlencode($board['slug']) . '&thread=' . $postedThreadId . '#replies');
        }

        throw new InvalidArgumentException('Unknown action.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isset($upload['path'])) {
            @unlink(__DIR__ . $upload['path']);
        }
        flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'Something went wrong. Please try again.');
        $target = '/?board=' . urlencode($board['slug']) . ($threadId ? '&thread=' . $threadId : '');
        redirect($target);
    }
}

$activeThread = null;
$replies = [];
$threads = [];

if ($threadId) {
    $stmt = $pdo->prepare('SELECT t.*, COUNT(r.id)::int AS reply_count FROM threads t LEFT JOIN replies r ON r.thread_id = t.id WHERE t.id = :id AND t.board_id = :board_id GROUP BY t.id');
    $stmt->execute(['id' => $threadId, 'board_id' => $board['id']]);
    $activeThread = $stmt->fetch();
    if (!$activeThread) {
        http_response_code(404);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM replies WHERE thread_id = :thread_id ORDER BY created_at ASC, id ASC');
        $stmt->execute(['thread_id' => $threadId]);
        $replies = $stmt->fetchAll();
    }
} else {
    $stmt = $pdo->prepare('SELECT t.*, COUNT(r.id)::int AS reply_count FROM threads t LEFT JOIN replies r ON r.thread_id = t.id WHERE t.board_id = :board_id GROUP BY t.id ORDER BY t.bumped_at DESC LIMIT 30');
    $stmt->execute(['board_id' => $board['id']]);
    $threads = $stmt->fetchAll();
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
        <?php foreach ($boards as $navBoard): ?>
            <a href="/?board=<?= urlencode($navBoard['slug']) ?>" <?= $navBoard['id'] === $board['id'] ? 'aria-current="page"' : '' ?>>/<?= e($navBoard['slug']) ?>/</a>
        <?php endforeach; ?>
    </nav>
</header>

<main id="content">
    <?php if ($flash): ?>
        <div class="notice notice--<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($threadId && !$activeThread): ?>
        <section class="empty-state">
            <h2>Thread not found</h2>
            <p>It may have been removed, or it belongs to another board.</p>
            <a class="button" href="/?board=<?= urlencode($board['slug']) ?>">Back to /<?= e($board['slug']) ?>/</a>
        </section>
    <?php elseif ($activeThread): ?>
        <div class="page-actions"><a href="/?board=<?= urlencode($board['slug']) ?>">← Back to board</a></div>
        <?php render_post($activeThread); ?>
        <section class="composer" aria-labelledby="reply-title">
            <details open>
                <summary id="reply-title">Reply to thread</summary>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="thread_id" value="<?= (int) $activeThread['id'] ?>">
                    <div class="form-row"><label for="reply-name">Name <span>optional</span></label><input id="reply-name" name="name" maxlength="40" autocomplete="nickname" placeholder="Anonymous"></div>
                    <div class="form-row"><label for="reply-body">Comment</label><textarea id="reply-body" name="body" maxlength="8000" rows="5" required></textarea></div>
                    <div class="form-row"><label for="reply-image">Image <span>optional, max <?= (int) env('MAX_UPLOAD_MB', '5') ?> MB</span></label><input id="reply-image" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></div>
                    <button type="submit">Post reply</button>
                </form>
            </details>
        </section>
        <section class="replies" id="replies" aria-label="Replies">
            <?php if (!$replies): ?><p class="empty-line">No replies yet.</p><?php endif; ?>
            <?php foreach ($replies as $reply) render_post($reply); ?>
        </section>
    <?php else: ?>
        <section class="composer" aria-labelledby="thread-title">
            <details>
                <summary id="thread-title">Start a new thread</summary>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="new_thread">
                    <div class="form-row"><label for="subject">Subject <span>optional</span></label><input id="subject" name="subject" maxlength="120"></div>
                    <div class="form-row"><label for="name">Name <span>optional</span></label><input id="name" name="name" maxlength="40" autocomplete="nickname" placeholder="Anonymous"></div>
                    <div class="form-row"><label for="body">Comment</label><textarea id="body" name="body" maxlength="8000" rows="6" required></textarea></div>
                    <div class="form-row"><label for="image">Image <span>optional, max <?= (int) env('MAX_UPLOAD_MB', '5') ?> MB</span></label><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></div>
                    <button type="submit">Create thread</button>
                </form>
            </details>
        </section>
        <section class="thread-list" aria-label="Threads">
            <?php if (!$threads): ?>
                <div class="empty-state"><h2>No threads yet</h2><p>Open the form above and start the conversation.</p></div>
            <?php endif; ?>
            <?php foreach ($threads as $thread) render_post($thread, true, $board['slug']); ?>
        </section>
    <?php endif; ?>
</main>

<footer class="site-footer">A tiny anonymous imageboard. Be decent.</footer>
</body>
</html>

