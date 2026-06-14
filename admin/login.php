<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../lib.php';

ensure_session_started();

if (is_admin_authenticated()) {
    header('Location: /admin/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        set_flash('error', 'Invalid request token. Refresh and try again.');
        header('Location: /admin/login.php');
        exit;
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        set_flash('success', 'Admin login successful.');
        header('Location: /admin/index.php');
        exit;
    }

    set_flash('error', 'Invalid username or password.');
    header('Location: /admin/login.php');
    exit;
}

$flashes = get_flashes();
$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ElectLead - Admin Login</title>
    <link rel="stylesheet" href="/styles.css" />
</head>

<body>
    <div class="bg-layer" aria-hidden="true"></div>

    <main class="app-shell narrow-shell">
        <header class="hero">
            <p class="eyebrow">Admin Portal</p>
            <h1>Admin Login</h1>
            <p class="lead">Committee members only.</p>
            <div class="portal-switch">
                <a class="btn-link" href="/index.php">Client Portal</a>
                <a class="btn-link active-link" href="/admin/login.php">Admin Portal</a>
            </div>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <section class="card flash flash-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </section>
        <?php endforeach; ?>

        <section class="card">
            <h2>Authentication</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

                <label>
                    Username
                    <input type="text" name="username" required />
                </label>

                <label>
                    Password
                    <input type="password" name="password" required />
                </label>

                <button class="span-2" type="submit">Sign In</button>
            </form>
        </section>
    </main>
</body>

</html>