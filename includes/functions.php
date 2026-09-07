<?php
/**
 * Shared application helpers.
 */

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function old(string $key, string $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

function flash(string $type, ?string $message = null): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if ($message !== null) {
        $_SESSION['_flash'][] = [
            'type' => $type,
            'message' => $message,
        ];

        return $message;
    }

    $messages = $_SESSION['_flash'] ?? [];
    foreach ($messages as $index => $entry) {
        if (($entry['type'] ?? '') === $type) {
            unset($messages[$index]);
            $_SESSION['_flash'] = array_values($messages);

            return (string)($entry['message'] ?? '');
        }
    }

    return '';
}

function set_flash(string $type, string $message): void
{
    flash($type, $message);
}

function consume_flash(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $messages;
}

function current_page_is(string $page): bool
{
    global $current_page;
    return ($current_page ?? '') === $page;
}

function page_title(string $title): string
{
    return e($title . ' | ' . APP_NAME);
}
