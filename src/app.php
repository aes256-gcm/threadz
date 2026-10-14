<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

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
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page, and try again.');
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
    if ($required && $value === '') {
        throw new InvalidArgumentException(ucfirst($key) . ' is required.');
    }
    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException(ucfirst($key) . " must be {$max} characters or fewer.");
    }
    return $value;
}

function save_upload(string $field): ?array
{
    $file = $_FILES[$field] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The image could not be uploaded.');
    }

    $limit = max(1, (int) env('MAX_UPLOAD_MB', '5')) * 1024 * 1024;
    if ((int) $file['size'] > $limit) {
        throw new InvalidArgumentException('The image is larger than the upload limit.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new InvalidArgumentException('Upload a JPG, PNG, GIF, or WebP image.');
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $directory = __DIR__ . '/../public/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Upload directory is unavailable.');
    }
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $stored)) {
        throw new RuntimeException('The image could not be saved.');
    }

    return ['path' => '/uploads/' . $stored, 'name' => mb_substr(basename((string) $file['name']), 0, 255)];
}

function render_post(array $post, bool $isThread = false, ?string $boardSlug = null): void
{
    $number = (int) $post['id'];
    $anchor = ($isThread ? 't' : 'r') . $number;
    $replyCount = (int) ($post['reply_count'] ?? 0);
    ?>
    <article class="post <?= $isThread ? 'post--thread' : 'post--reply' ?>" id="<?= $anchor ?>">
        <header class="post__meta">
            <?php if ($isThread && ($post['subject'] ?? '') !== ''): ?>
                <strong class="post__subject"><?= e($post['subject']) ?></strong>
            <?php endif; ?>
            <span class="post__author"><?= e($post['author']) ?></span>
            <time datetime="<?= e($post['created_at']) ?>"><?= e(format_date($post['created_at'])) ?></time>
            <a class="post__number" href="#<?= $anchor ?>">No.<?= $number ?></a>
        </header>
        <div class="post__content">
            <?php if (!empty($post['image_path'])): ?>
                <figure class="post__media">
                    <a href="<?= e($post['image_path']) ?>" target="_blank" rel="noopener">
                        <img src="<?= e($post['image_path']) ?>" alt="Uploaded image: <?= e($post['image_name']) ?>" loading="lazy">
                    </a>
                    <figcaption><?= e($post['image_name']) ?></figcaption>
                </figure>
            <?php endif; ?>
            <p class="post__body"><?= nl2br(e($post['body'])) ?></p>
        </div>
        <?php if ($isThread && $boardSlug !== null): ?>
            <footer class="post__footer">
                <a href="/?board=<?= urlencode($boardSlug) ?>&thread=<?= $number ?>">Open thread · <?= $replyCount ?> <?= $replyCount === 1 ? 'reply' : 'replies' ?></a>
            </footer>
        <?php endif; ?>
    </article>
    <?php
}

