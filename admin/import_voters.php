<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../lib.php';

ensure_session_started();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        set_flash('error', 'Invalid request token. Refresh and try again.');
        header('Location: /admin/import_voters.php');
        exit;
    }

    if (!isset($_FILES['voter_csv']) || $_FILES['voter_csv']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'CSV upload failed.');
        header('Location: /admin/import_voters.php');
        exit;
    }

    $tmpName = $_FILES['voter_csv']['tmp_name'];
    $handle = fopen($tmpName, 'r');
    if ($handle === false) {
        set_flash('error', 'Unable to read uploaded file.');
        header('Location: /admin/import_voters.php');
        exit;
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        set_flash('error', 'CSV is empty.');
        header('Location: /admin/import_voters.php');
        exit;
    }

    $normalizedHeader = array_map(
        static fn($v) => strtolower(trim((string) $v)),
        $header
    );

    $required = ['voter_id', 'full_name'];
    foreach ($required as $col) {
        if (!in_array($col, $normalizedHeader, true)) {
            fclose($handle);
            set_flash('error', 'CSV must include columns: voter_id, full_name (active optional).');
            header('Location: /admin/import_voters.php');
            exit;
        }
    }

    $idxVoterId = array_search('voter_id', $normalizedHeader, true);
    $idxFullName = array_search('full_name', $normalizedHeader, true);
    $idxActive = array_search('active', $normalizedHeader, true);

    $upsert = $pdo->prepare(
        'INSERT INTO voters (voter_id, full_name, active)
         VALUES (:voter_id, :full_name, :active)
         ON DUPLICATE KEY UPDATE
           full_name = VALUES(full_name),
           active = VALUES(active)'
    );

    $processed = 0;
    $skipped = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $voterId = trim((string) ($row[$idxVoterId] ?? ''));
        $fullName = trim((string) ($row[$idxFullName] ?? ''));

        if ($voterId === '' || $fullName === '') {
            $skipped++;
            continue;
        }

        $active = 1;
        if ($idxActive !== false) {
            $activeRaw = strtolower(trim((string) ($row[$idxActive] ?? '1')));
            $active = in_array($activeRaw, ['0', 'false', 'no', 'inactive'], true) ? 0 : 1;
        }

        $upsert->execute([
            ':voter_id' => $voterId,
            ':full_name' => $fullName,
            ':active' => $active,
        ]);
        $processed++;
    }

    fclose($handle);
    set_flash('success', "Voter import complete. Processed {$processed} row(s), skipped {$skipped} row(s).");
    header('Location: /admin/import_voters.php');
    exit;
}

$flashes = get_flashes();
$csrfToken = csrf_token();
$voters = $pdo->query('SELECT voter_id, full_name, active FROM voters ORDER BY voter_id LIMIT 100')->fetchAll();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ElectLead - Import Voters</title>
    <link rel="stylesheet" href="/styles.css" />
</head>

<body>
    <div class="bg-layer" aria-hidden="true"></div>

    <main class="app-shell">
        <header class="hero">
            <p class="eyebrow">Admin Portal</p>
            <h1>Mass Import Voters</h1>
            <p class="lead">Import voter list with unique voter IDs.</p>
            <div class="portal-switch">
                <a class="btn-link" href="/index.php">Client Portal</a>
                <a class="btn-link active-link" href="/admin/index.php">Admin Dashboard</a>
                <a class="btn-link danger-link" href="/admin/logout.php">Sign Out</a>
            </div>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <section class="card flash flash-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </section>
        <?php endforeach; ?>

        <section class="card">
            <h2>Upload CSV</h2>
            <p>Required columns: <strong>voter_id</strong>, <strong>full_name</strong>. Optional: <strong>active</strong>.</p>

            <form method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

                <label class="span-2">
                    Voter CSV file
                    <input type="file" name="voter_csv" accept=".csv,text/csv" required />
                </label>

                <button class="span-2" type="submit">Import Voters</button>
            </form>
        </section>

        <section class="card">
            <h2>Current Voters (Top 100)</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Voter ID</th>
                            <th>Full Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($voters as $voter): ?>
                            <tr>
                                <td><?= e($voter['voter_id']) ?></td>
                                <td><?= e($voter['full_name']) ?></td>
                                <td><?= (int) $voter['active'] === 1 ? 'active' : 'inactive' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>

</html>