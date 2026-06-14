<?php

declare(strict_types=1);

function ensure_settings_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS election_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function is_vote_open(PDO $pdo): bool
{
    ensure_settings_table($pdo);

    $stmt = $pdo->prepare('SELECT setting_value FROM election_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => 'vote_open']);
    $row = $stmt->fetch();

    return $row && $row['setting_value'] === '1';
}

function set_vote_open(PDO $pdo, bool $open): void
{
    ensure_settings_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO election_settings (setting_key, setting_value)
         VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([
        ':key' => 'vote_open',
        ':value' => $open ? '1' : '0',
    ]);
}

function ensure_session_started(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function set_flash(string $type, string $message): void
{
    ensure_session_started();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    ensure_session_started();
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function is_admin_authenticated(): bool
{
    ensure_session_started();
    return isset($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!is_admin_authenticated()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function csrf_token(): string
{
    ensure_session_started();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool
{
    ensure_session_started();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
