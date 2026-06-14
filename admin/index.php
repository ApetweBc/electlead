<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../lib.php';

ensure_session_started();
require_admin();

$voteOpen = is_vote_open($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        set_flash('error', 'Invalid request token. Refresh and try again.');
        header('Location: /admin/index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'set_vote_state') {
        $nextState = (string) ($_POST['vote_state'] ?? 'closed');
        $open = $nextState === 'open';
        set_vote_open($pdo, $open);

        set_flash('success', $open ? 'Voting is now OPEN.' : 'Voting is now CLOSED.');
        header('Location: /admin/index.php');
        exit;
    }

    if ($action === 'add_category') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            set_flash('error', 'Category name is required.');
            header('Location: /admin/index.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
            $stmt->execute([':name' => $name]);
            set_flash('success', 'Category added.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                set_flash('error', 'Category already exists.');
            } else {
                set_flash('error', 'Failed to add category.');
            }
        }

        header('Location: /admin/index.php');
        exit;
    }

    if ($action === 'update_nomination') {
        $nominationId = (int) ($_POST['nomination_id'] ?? 0);
        $criminalCheck = (string) ($_POST['criminal_check'] ?? 'pending');
        $characterCheck = (string) ($_POST['character_check'] ?? 'pending');
        $committeeNotes = trim((string) ($_POST['committee_notes'] ?? ''));
        $decision = (string) ($_POST['decision'] ?? 'save');

        $allowed = ['pending', 'pass', 'fail'];
        if (!in_array($criminalCheck, $allowed, true) || !in_array($characterCheck, $allowed, true)) {
            set_flash('error', 'Invalid check status value.');
            header('Location: /admin/index.php');
            exit;
        }

        $nominatorCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM nomination_nominators WHERE nomination_id = :nomination_id');
        $nominatorCountStmt->execute([':nomination_id' => $nominationId]);
        $countRow = $nominatorCountStmt->fetch();
        $nominatorCount = (int) ($countRow['total'] ?? 0);

        $status = 'nominated';
        if ($decision === 'reject') {
            $status = 'rejected';
        }
        if ($decision === 'approve') {
            if ($nominatorCount < 2) {
                set_flash('error', 'Cannot approve: candidate has fewer than 2 nominators.');
                header('Location: /admin/index.php');
                exit;
            }
            if ($criminalCheck !== 'pass' || $characterCheck !== 'pass') {
                set_flash('error', 'Cannot approve: criminal and character checks must both be pass.');
                header('Location: /admin/index.php');
                exit;
            }
            $status = 'verified';
        }

        $update = $pdo->prepare(
            'UPDATE nominations
             SET criminal_check = :criminal_check,
                 character_check = :character_check,
                 committee_notes = :committee_notes,
                 status = :status
             WHERE id = :id'
        );
        $update->execute([
            ':criminal_check' => $criminalCheck,
            ':character_check' => $characterCheck,
            ':committee_notes' => $committeeNotes,
            ':status' => $status,
            ':id' => $nominationId,
        ]);

        set_flash('success', 'Candidate record updated.');
        header('Location: /admin/index.php');
        exit;
    }
}

$voteOpen = is_vote_open($pdo);

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$nominations = $pdo->query(
    'SELECT n.id, n.category_id, n.candidate_name, n.criminal_check, n.character_check, n.status, n.committee_notes, c.name AS category_name,
            (SELECT COUNT(*) FROM nomination_nominators nn WHERE nn.nomination_id = n.id) AS nominator_count,
            (SELECT GROUP_CONCAT(nn2.nominator_name SEPARATOR ", ") FROM nomination_nominators nn2 WHERE nn2.nomination_id = n.id) AS nominators
     FROM nominations n
     INNER JOIN categories c ON c.id = n.category_id
    WHERE n.status = "nominated"
     ORDER BY c.name, n.created_at DESC'
)->fetchAll();

$selectedNominationId = isset($_GET['nomination_id']) ? (int) $_GET['nomination_id'] : 0;
$selectedNomination = null;

if (count($nominations) > 0) {
    if ($selectedNominationId <= 0) {
        $selectedNomination = $nominations[0];
        $selectedNominationId = (int) $selectedNomination['id'];
    } else {
        foreach ($nominations as $nomination) {
            if ((int) $nomination['id'] === $selectedNominationId) {
                $selectedNomination = $nomination;
                break;
            }
        }

        if ($selectedNomination === null) {
            $selectedNomination = $nominations[0];
            $selectedNominationId = (int) $selectedNomination['id'];
        }
    }
}

$results = $pdo->query(
    'SELECT c.name AS category_name, n.candidate_name, COUNT(b.id) AS vote_count
     FROM categories c
     LEFT JOIN nominations n ON n.category_id = c.id AND n.status = "verified"
     LEFT JOIN ballots b ON b.nomination_id = n.id
     GROUP BY c.id, c.name, n.id, n.candidate_name
     ORDER BY c.name, vote_count DESC, n.candidate_name'
)->fetchAll();

$resultsByCategory = [];
foreach ($results as $row) {
    $category = $row['category_name'];
    $resultsByCategory[$category][] = $row;
}

$flashes = get_flashes();
$csrfToken = csrf_token();
$username = (string) ($_SESSION['admin_username'] ?? 'Admin');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ElectLead - Admin Dashboard</title>
    <link rel="stylesheet" href="/styles.css" />
</head>

<body>
    <div class="bg-layer" aria-hidden="true"></div>

    <main class="app-shell">
        <header class="hero">
            <p class="eyebrow">Admin Portal</p>
            <h1>Election Dashboard</h1>
            <p class="lead">Signed in as <?= e($username) ?>.</p>
            <div class="portal-switch">
                <a class="btn-link" href="/index.php">Client Portal</a>
                <a class="btn-link active-link" href="/admin/index.php">Admin Portal</a>
                <a class="btn-link" href="/admin/import_voters.php">Import Voters</a>
                <a class="btn-link danger-link" href="/admin/logout.php">Sign Out</a>
            </div>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <section class="card flash flash-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </section>
        <?php endforeach; ?>

        <section class="card grid-two">
            <div>
                <h2>Manage Categories</h2>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                    <input type="hidden" name="action" value="add_category" />
                    <label class="sr-only" for="category-name">Category name</label>
                    <input id="category-name" name="name" type="text" placeholder="e.g. Treasurer" required />
                    <button type="submit">Add Category</button>
                </form>
                <ul class="pill-list">
                    <?php foreach ($categories as $category): ?>
                        <li><?= e($category['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div>
                <h2>Voter Registry</h2>
                <p class="lead">Import voter CSV with unique IDs to enforce one vote per category.</p>
                <a class="btn-link inline-block" href="/admin/import_voters.php">Go to Mass Import</a>
            </div>
        </section>

        <section class="card grid-two">
            <div>
                <h2>Voting Control</h2>
                <p class="lead">Current status: <strong><?= $voteOpen ? 'OPEN' : 'CLOSED' ?></strong></p>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                    <input type="hidden" name="action" value="set_vote_state" />
                    <input type="hidden" name="vote_state" value="<?= $voteOpen ? 'closed' : 'open' ?>" />
                    <button type="submit" class="<?= $voteOpen ? 'reject' : '' ?>">
                        <?= $voteOpen ? 'Close Vote' : 'Open Vote' ?>
                    </button>
                </form>
            </div>

            <div>
                <h2>Client Visibility Rule</h2>
                <ul class="rules">
                    <li>When vote is open: voting form shown, nomination form hidden.</li>
                    <li>When vote is closed: nomination form shown, voting form hidden.</li>
                </ul>
            </div>
        </section>

        <section class="card">
            <h2>Election Committee Verification</h2>

            <?php if (count($nominations) === 0): ?>
                <p>No nominations yet.</p>
            <?php endif; ?>

            <?php if ($selectedNomination !== null): ?>
                <form method="get" class="inline-form">
                    <label for="nomination-select">Select candidate record</label>
                    <select id="nomination-select" name="nomination_id" onchange="this.form.submit()">
                        <?php foreach ($nominations as $nominationOption): ?>
                            <option value="<?= (int) $nominationOption['id'] ?>" <?= (int) $nominationOption['id'] === $selectedNominationId ? 'selected' : '' ?>>
                                <?= e($nominationOption['candidate_name']) ?> - <?= e($nominationOption['category_name']) ?> (<?= e($nominationOption['status']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <article class="verify-item">
                    <div class="verify-header">
                        <strong><?= e($selectedNomination['candidate_name']) ?> - <?= e($selectedNomination['category_name']) ?></strong>
                        <span class="status-chip status-<?= e($selectedNomination['status']) ?>"><?= e($selectedNomination['status']) ?></span>
                    </div>
                    <p>
                        <strong>Nominators (<?= (int) $selectedNomination['nominator_count'] ?>):</strong>
                        <?= e((string) ($selectedNomination['nominators'] ?? '')) ?>
                    </p>

                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                        <input type="hidden" name="action" value="update_nomination" />
                        <input type="hidden" name="nomination_id" value="<?= (int) $selectedNomination['id'] ?>" />

                        <label>
                            Criminal check
                            <select name="criminal_check">
                                <option value="pending" <?= $selectedNomination['criminal_check'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                <option value="pass" <?= $selectedNomination['criminal_check'] === 'pass' ? 'selected' : '' ?>>pass</option>
                                <option value="fail" <?= $selectedNomination['criminal_check'] === 'fail' ? 'selected' : '' ?>>fail</option>
                            </select>
                        </label>

                        <label>
                            Character check
                            <select name="character_check">
                                <option value="pending" <?= $selectedNomination['character_check'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                <option value="pass" <?= $selectedNomination['character_check'] === 'pass' ? 'selected' : '' ?>>pass</option>
                                <option value="fail" <?= $selectedNomination['character_check'] === 'fail' ? 'selected' : '' ?>>fail</option>
                            </select>
                        </label>

                        <label class="span-2">
                            Committee notes
                            <textarea name="committee_notes" rows="2"><?= e((string) $selectedNomination['committee_notes']) ?></textarea>
                        </label>

                        <div class="actions span-2">
                            <button type="submit" name="decision" value="save" class="secondary">Save Checks</button>
                            <button type="submit" name="decision" value="approve">Approve Candidate</button>
                            <button type="submit" name="decision" value="reject" class="reject">Reject Candidate</button>
                        </div>
                    </form>
                </article>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Results Dashboard (Admin Only)</h2>
            <div class="result-grid">
                <?php foreach ($resultsByCategory as $categoryName => $rows): ?>
                    <section class="result-card">
                        <h3><?= e($categoryName) ?></h3>
                        <ul>
                            <?php
                            $hasCandidates = false;
                            foreach ($rows as $row):
                                if ($row['candidate_name'] === null) {
                                    continue;
                                }
                                $hasCandidates = true;
                            ?>
                                <li><?= e($row['candidate_name']) ?>: <strong><?= (int) $row['vote_count'] ?></strong> vote(s)</li>
                            <?php endforeach; ?>
                            <?php if (!$hasCandidates): ?>
                                <li>No verified candidates yet.</li>
                            <?php endif; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>

</html>