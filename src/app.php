<?php

declare(strict_types=1);

const THREAD_REWARD = 2;
const REPLY_REWARD = 1;
const DAILY_EARNING_CAP = 5;

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO(
        env('DATABASE_URL', 'pgsql:host=localhost;port=5432;dbname=threadline'),
        env('DATABASE_USER', 'threadline'),
        env('DATABASE_PASSWORD', 'threadline'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh, and try again.');
    }
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function format_date(string $date): string
{
    return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Australia/Sydney'))->format('d/m/y(D) H:i');
}

function request_text(string $key, int $max, bool $required = false): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($required && $value === '') throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . ' is required.');
    if (mb_strlen($value) > $max) throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . " must be {$max} characters or fewer.");
    return $value;
}

function current_user(PDO $pdo): ?array
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if (!$id) return null;
    $stmt = $pdo->prepare('SELECT id, username, credits, created_at FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    if (!$user) unset($_SESSION['user_id']);
    return $user ?: null;
}

function require_user(?array $user): array
{
    if (!$user) throw new InvalidArgumentException('Sign in to do that.');
    return $user;
}

function posting_earnings_today(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0)::int FROM credit_transactions WHERE user_id = :user_id AND kind IN ('thread_reward', 'reply_reward') AND created_at >= date_trunc('day', NOW())");
    $stmt->execute(['user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}

function award_posting_credits(PDO $pdo, int $userId, int $reward, string $kind, int $referenceId): int
{
    $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
    $lock->execute(['id' => $userId]);
    if ($lock->fetchColumn() === false) throw new InvalidArgumentException('Account not found.');
    $award = min($reward, max(0, DAILY_EARNING_CAP - posting_earnings_today($pdo, $userId)));
    if ($award === 0) return 0;
    $pdo->prepare('UPDATE users SET credits = credits + :reward WHERE id = :id')->execute(['reward' => $award, 'id' => $userId]);
    $pdo->prepare('INSERT INTO credit_transactions (user_id, amount, kind, reference_id, note) VALUES (:user_id, :amount, :kind, :reference_id, :note)')->execute([
        'user_id' => $userId, 'amount' => $award, 'kind' => $kind, 'reference_id' => $referenceId,
        'note' => $kind === 'thread_reward' ? 'Created a thread' : 'Posted a reply',
    ]);
    return $award;
}

function requested_unlockable(): ?array
{
    if (($_POST['add_unlock'] ?? '') !== '1') return null;
    $type = (string) ($_POST['unlock_type'] ?? '');
    if (!in_array($type, ['tutorial', 'link', 'download'], true)) throw new InvalidArgumentException('Choose a valid locked content type.');
    $title = request_text('unlock_title', 120, true);
    $content = request_text('unlock_content', 12000, true);
    $price = filter_var($_POST['unlock_price'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($price === false || $price === null) throw new InvalidArgumentException('Unlock price must be between 1 and 100 credits.');
    $scheme = strtolower((string) parse_url($content, PHP_URL_SCHEME));
    if ($type !== 'tutorial' && (filter_var($content, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true))) {
        throw new InvalidArgumentException('Locked links must be complete http:// or https:// URLs.');
    }
    return ['type' => $type, 'title' => $title, 'content' => $content, 'price' => (int) $price];
}

function create_unlockable(PDO $pdo, int $creatorId, ?int $threadId, ?int $replyId, ?array $unlock): void
{
    if (!$unlock) return;
    $stmt = $pdo->prepare('INSERT INTO unlockables (creator_id, thread_id, reply_id, content_type, title, content, price) VALUES (:creator_id, :thread_id, :reply_id, :content_type, :title, :content, :price)');
    $stmt->execute([
        'creator_id' => $creatorId, 'thread_id' => $threadId, 'reply_id' => $replyId,
        'content_type' => $unlock['type'], 'title' => $unlock['title'], 'content' => $unlock['content'], 'price' => $unlock['price'],
    ]);
}

function enrich_posts(PDO $pdo, array $posts, string $postType, ?int $viewerId): array
{
    $column = $postType === 'thread' ? 'thread_id' : 'reply_id';
    $stmt = $pdo->prepare("SELECT u.*, (u.creator_id = :viewer_creator OR EXISTS (SELECT 1 FROM unlock_purchases p WHERE p.unlockable_id = u.id AND p.buyer_id = :viewer_buyer)) AS has_access FROM unlockables u WHERE u.{$column} = :post_id");
    foreach ($posts as &$post) {
        $stmt->execute(['viewer_creator' => $viewerId ?? 0, 'viewer_buyer' => $viewerId ?? 0, 'post_id' => $post['id']]);
        $post['unlock'] = $stmt->fetch() ?: null;
    }
    unset($post);
    return $posts;
}

function has_access(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 't';
}

function save_upload(string $field): ?array
{
    $file = $_FILES[$field] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The image could not be uploaded.');

    $limit = max(1, (int) env('MAX_UPLOAD_MB', '5')) * 1024 * 1024;
    if ((int) $file['size'] > $limit) throw new InvalidArgumentException('The image is larger than the upload limit.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) throw new InvalidArgumentException('Upload a JPG, PNG, GIF, or WebP image.');

    $stored = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $directory = __DIR__ . '/../public/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('Upload directory is unavailable.');
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $stored)) throw new RuntimeException('The image could not be saved.');
    return ['path' => '/uploads/' . $stored, 'name' => mb_substr(basename((string) $file['name']), 0, 255)];
}

function render_unlockable(array $unlock, ?array $viewer): void
{
    $accessible = has_access($unlock['has_access'] ?? false);
    ?>
    <section class="unlockable">
        <strong><?= e($unlock['title']) ?></strong>
        <span class="unlockable__type"><?= e(ucfirst($unlock['content_type'])) ?></span>
        <?php if ($accessible): ?>
            <?php if ($unlock['content_type'] === 'tutorial'): ?>
                <p><?= nl2br(e($unlock['content'])) ?></p>
            <?php else: ?>
                <p><a href="<?= e($unlock['content']) ?>" target="_blank" rel="noopener<?= $unlock['content_type'] === 'download' ? ' nofollow' : '' ?>"><?= $unlock['content_type'] === 'download' ? 'Open download link' : 'Open link' ?></a></p>
            <?php endif; ?>
        <?php elseif ($viewer): ?>
            <p>Locked · <?= (int) $unlock['price'] ?> credits</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlock">
                <input type="hidden" name="unlock_id" value="<?= (int) $unlock['id'] ?>">
                <button type="submit">Unlock for <?= (int) $unlock['price'] ?> credits</button>
            </form>
        <?php else: ?>
            <p>Locked · <?= (int) $unlock['price'] ?> credits · sign in to unlock</p>
        <?php endif; ?>
    </section>
    <?php
}

function render_post(array $post, bool $isThread = false, ?string $boardSlug = null, ?array $viewer = null): void
{
    $number = (int) $post['id'];
    $anchor = ($isThread ? 't' : 'r') . $number;
    $replyCount = (int) ($post['reply_count'] ?? 0);
    ?>
    <article class="post <?= $isThread ? 'post--thread' : 'post--reply' ?>" id="<?= $anchor ?>">
        <header class="post__meta">
            <?php if ($isThread && ($post['subject'] ?? '') !== ''): ?><strong class="post__subject"><?= e($post['subject']) ?></strong><?php endif; ?>
            <span class="post__author"><?= e($post['display_author'] ?? $post['author']) ?></span>
            <time datetime="<?= e($post['created_at']) ?>"><?= e(format_date($post['created_at'])) ?></time>
            <a class="post__number" href="#<?= $anchor ?>">No.<?= $number ?></a>
        </header>
        <div class="post__content">
            <?php if (!empty($post['image_path'])): ?>
                <figure class="post__media">
                    <a href="<?= e($post['image_path']) ?>" target="_blank" rel="noopener"><img src="<?= e($post['image_path']) ?>" alt="Uploaded image: <?= e($post['image_name']) ?>" loading="lazy"></a>
                    <figcaption><?= e($post['image_name']) ?></figcaption>
                </figure>
            <?php endif; ?>
            <p class="post__body"><?= nl2br(e($post['body'])) ?></p>
            <?php if (!empty($post['unlock'])) render_unlockable($post['unlock'], $viewer); ?>
        </div>
        <?php if ($isThread && $boardSlug !== null): ?>
            <footer class="post__footer"><a href="/?board=<?= urlencode($boardSlug) ?>&thread=<?= $number ?>">Open thread · <?= $replyCount ?> <?= $replyCount === 1 ? 'reply' : 'replies' ?></a></footer>
        <?php endif; ?>
    </article>
    <?php
}

function render_unlock_fields(string $prefix): void
{
    ?>
    <details class="unlock-fields">
        <summary>Add locked content</summary>
        <label class="check-row"><input type="checkbox" name="add_unlock" value="1"> Include a paid unlock</label>
        <div class="form-row"><label for="<?= $prefix ?>-unlock-type">Type</label><select id="<?= $prefix ?>-unlock-type" name="unlock_type"><option value="tutorial">Tutorial / text</option><option value="link">Link</option><option value="download">Download link</option></select></div>
        <div class="form-row"><label for="<?= $prefix ?>-unlock-title">Title</label><input id="<?= $prefix ?>-unlock-title" name="unlock_title" maxlength="120"></div>
        <div class="form-row"><label for="<?= $prefix ?>-unlock-content">Content or URL</label><textarea id="<?= $prefix ?>-unlock-content" name="unlock_content" maxlength="12000" rows="4"></textarea></div>
        <div class="form-row"><label for="<?= $prefix ?>-unlock-price">Price <span>1–100 credits</span></label><input id="<?= $prefix ?>-unlock-price" name="unlock_price" type="number" min="1" max="100" value="5"></div>
    </details>
    <?php
}
