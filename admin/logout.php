<?php

declare(strict_types=1);

require __DIR__ . '/../lib.php';

ensure_session_started();
session_unset();
session_destroy();

header('Location: /admin/login.php');
exit;
