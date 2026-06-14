<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/lib.php';

ensure_session_started();

$voteOpen = is_vote_open($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        set_flash('error', 'Invalid request token. Refresh and try again.');
        header('Location: /index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'nominate') {
        if ($voteOpen) {
            set_flash('error', 'Nomination is closed because voting has opened.');
            header('Location: /index.php');
            exit;
        }

        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $candidateName = trim((string) ($_POST['candidate_name'] ?? ''));
        $nominatorOne = trim((string) ($_POST['nominator_one'] ?? ''));
        $nominatorTwo = trim((string) ($_POST['nominator_two'] ?? ''));
        $extraRaw = trim((string) ($_POST['nominator_extra'] ?? ''));

        $extra = array_filter(array_map('trim', explode(',', $extraRaw)));
        $nominators = array_values(array_unique(array_filter([$nominatorOne, $nominatorTwo, ...$extra])));

        if ($categoryId <= 0 || $candidateName === '' || count($nominators) < 2) {
            set_flash('error', 'Nomination requires category, candidate name, and at least 2 unique nominators.');
            header('Location: /index.php');
            exit;
        }

        try {
            $pdo->beginTransaction();

            $insertNomination = $pdo->prepare(
                'INSERT INTO nominations (category_id, candidate_name) VALUES (:category_id, :candidate_name)'
            );
            $insertNomination->execute([
                ':category_id' => $categoryId,
                ':candidate_name' => $candidateName,
            ]);

            $nominationId = (int) $pdo->lastInsertId();
            $insertNominator = $pdo->prepare(
                'INSERT INTO nomination_nominators (nomination_id, nominator_name) VALUES (:nomination_id, :nominator_name)'
            );

            foreach ($nominators as $nominator) {
                $insertNominator->execute([
                    ':nomination_id' => $nominationId,
                    ':nominator_name' => $nominator,
                ]);
            }

            $pdo->commit();
            set_flash('success', 'Nomination submitted. Awaiting committee verification.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                set_flash('error', 'This candidate is already nominated in the selected category.');
            } else {
                set_flash('error', 'Failed to submit nomination.');
            }
        }

        header('Location: /index.php');
        exit;
    }

    if ($action === 'vote') {
        if (!$voteOpen) {
            set_flash('error', 'Voting is not open yet. Please wait for admin to open voting.');
            header('Location: /index.php');
            exit;
        }

        $voterId = trim((string) ($_POST['voter_id'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $nominationId = (int) ($_POST['nomination_id'] ?? 0);

        if ($voterId === '' || $categoryId <= 0 || $nominationId <= 0) {
            set_flash('error', 'Voter ID, category, and candidate are required.');
            header('Location: /index.php');
            exit;
        }

        $voterStmt = $pdo->prepare('SELECT voter_id, active FROM voters WHERE voter_id = :voter_id LIMIT 1');
        $voterStmt->execute([':voter_id' => $voterId]);
        $voter = $voterStmt->fetch();

        if (!$voter || (int) $voter['active'] !== 1) {
            set_flash('error', 'Voter ID is not registered or inactive. Contact the election committee.');
            header('Location: /index.php');
            exit;
        }

        $candidateStmt = $pdo->prepare(
            'SELECT id FROM nominations WHERE id = :nomination_id AND category_id = :category_id AND status = "verified" LIMIT 1'
        );
        $candidateStmt->execute([
            ':nomination_id' => $nominationId,
            ':category_id' => $categoryId,
        ]);
        $candidate = $candidateStmt->fetch();

        if (!$candidate) {
            set_flash('error', 'Selected candidate is not verified for this category.');
            header('Location: /index.php');
            exit;
        }

        try {
            $pdo->beginTransaction();

            $markParticipation = $pdo->prepare(
                'INSERT INTO voter_participation (voter_id, category_id) VALUES (:voter_id, :category_id)'
            );
            $markParticipation->execute([
                ':voter_id' => $voterId,
                ':category_id' => $categoryId,
            ]);

            // Ballot table stores no voter_id to preserve secret ballot records.
            $insertBallot = $pdo->prepare(
                'INSERT INTO ballots (category_id, nomination_id) VALUES (:category_id, :nomination_id)'
            );
            $insertBallot->execute([
                ':category_id' => $categoryId,
                ':nomination_id' => $nominationId,
            ]);

            $pdo->commit();
            set_flash('success', 'Vote cast successfully.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                set_flash('error', 'This voter has already voted in this category.');
            } else {
                set_flash('error', 'Failed to cast vote.');
            }
        }

        header('Location: /index.php');
        exit;
    }
}

$voteOpen = is_vote_open($pdo);

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$verifiedCandidates = $pdo->query(
    'SELECT n.id, n.category_id, n.candidate_name, c.name AS category_name
     FROM nominations n
     INNER JOIN categories c ON c.id = n.category_id
     WHERE n.status = "verified"
     ORDER BY c.name, n.candidate_name'
)->fetchAll();

$candidatesByCategory = [];
foreach ($verifiedCandidates as $candidate) {
    $key = (int) $candidate['category_id'];
    $candidatesByCategory[$key][] = $candidate;
}

$flashes = get_flashes();
$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ElectLead - Client Portal</title>
    <link rel="stylesheet" href="/styles.css" />
</head>

<body>
    <div class="bg-layer" aria-hidden="true"></div>

    <main class="app-shell">
        <header class="hero">
            <p class="eyebrow">Client Portal</p>
            <h1>ElectLead</h1>
            <p class="lead">Nominate candidates and cast one secret ballot per category using your voter ID.</p>
            <div class="portal-switch">
                <a class="btn-link active-link" href="/index.php">Client Portal</a>
                <a class="btn-link" href="/admin/login.php">Admin Portal</a>
            </div>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <section class="card flash flash-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </section>
        <?php endforeach; ?>

        <section class="card grid-two">
            <div>
                <h2>Election Categories</h2>
                <ul class="pill-list">
                    <?php foreach ($categories as $category): ?>
                        <li><?= e($category['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h2>Quick Rules</h2>
                <ul class="rules">
                    <li>Secret ballot voting.</li>
                    <li>Each candidate needs at least 2 nominators.</li>
                    <li>Candidates must be verified by committee before voting.</li>
                    <li>One vote per voter ID in each category.</li>
                </ul>
                <p class="lead"><strong>Voting status:</strong> <?= $voteOpen ? 'OPEN' : 'CLOSED' ?></p>
            </div>
        </section>

        <?php if (!$voteOpen): ?>
            <section class="card">
                <h2>Nominate Candidate</h2>
                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                    <input type="hidden" name="action" value="nominate" />

                    <label>
                        Category
                        <select name="category_id" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Candidate name
                        <input type="text" name="candidate_name" required />
                    </label>

                    <label>
                        Nominator 1
                        <input type="text" name="nominator_one" required />
                    </label>

                    <label>
                        Nominator 2
                        <input type="text" name="nominator_two" required />
                    </label>

                    <label class="span-2">
                        Additional nominators (optional, comma-separated)
                        <input type="text" name="nominator_extra" />
                    </label>

                    <button class="span-2" type="submit">Submit Nomination</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($voteOpen): ?>
            <section class="card">
                <h2>Cast Secret Vote</h2>
                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                    <input type="hidden" name="action" value="vote" />

                    <label>
                        Voter ID
                        <input type="text" name="voter_id" required />
                    </label>

                    <label>
                        Category
                        <select name="category_id" id="vote-category" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="span-2">
                        Verified candidate
                        <select name="nomination_id" id="vote-candidate" required>
                            <option value="">Select candidate</option>
                        </select>
                    </label>

                    <button class="span-2" type="submit">Cast Vote</button>
                </form>
            </section>
        <?php endif; ?>
    </main>

    <?php if ($voteOpen): ?>
        <script>
            const candidatesByCategory = <?= json_encode($candidatesByCategory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
            const categorySelect = document.getElementById('vote-category');
            const candidateSelect = document.getElementById('vote-candidate');

            function renderCandidateOptions() {
                const categoryId = categorySelect.value;
                const candidates = candidatesByCategory[categoryId] || [];
                candidateSelect.innerHTML = '';

                if (candidates.length === 0) {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'No verified candidates available';
                    candidateSelect.appendChild(option);
                    return;
                }

                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = 'Select candidate';
                candidateSelect.appendChild(defaultOption);

                for (const candidate of candidates) {
                    const option = document.createElement('option');
                    option.value = candidate.id;
                    option.textContent = candidate.candidate_name;
                    candidateSelect.appendChild(option);
                }
            }

            categorySelect.addEventListener('change', renderCandidateOptions);
            renderCandidateOptions();
        </script>
    <?php endif; ?>
</body>

</html>