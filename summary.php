<?php
declare(strict_types=1);

header('Cache-Control: no-cache');

$DB = __DIR__ . '/getswole.db';

$db = new PDO('sqlite:' . $DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$lifters = $db->query('SELECT name FROM lifters ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$lifterName = (string)($_GET['lifter'] ?? $_COOKIE['lifter'] ?? '');
if (!in_array($lifterName, $lifters, true)) {
    $lifterName = '';
}
if ($lifterName !== '' && ($_COOKIE['lifter'] ?? '') !== $lifterName) {
    setcookie('lifter', $lifterName, [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}

$SECTIONS = ['Upper', 'Core', 'Lower'];
$rows = [];

if ($lifterName !== '') {
    $stmt = $db->prepare(
        "WITH lifter_sets AS (
            SELECT st.id, st.reps, st.weight, e.logged_at, e.machine_id
            FROM sets st
            JOIN exercise_entries e ON e.id = st.entry_id
            JOIN sessions s ON s.id = e.session_id
            JOIN lifters l ON l.id = s.lifter_id
            WHERE l.name = ?
        ),
        ranked AS (
            SELECT *, ROW_NUMBER() OVER (
                PARTITION BY machine_id ORDER BY logged_at DESC, id DESC
            ) AS rn
            FROM lifter_sets
        )
        SELECT m.name AS machine_name, g.name AS group_name,
               r.reps, r.weight, r.logged_at
        FROM machines m
        JOIN muscle_groups g ON g.id = m.muscle_group_id
        LEFT JOIN ranked r ON r.machine_id = m.id AND r.rn = 1
        WHERE g.name IN ('Upper', 'Core', 'Lower')
        ORDER BY m.name"
    );
    $stmt->execute([$lifterName]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$bySection = array_fill_keys($SECTIONS, []);
foreach ($rows as $row) {
    $bySection[$row['group_name']][] = $row;
}

function summary_url(?string $lifter): string {
    return 'summary.php' . ($lifter ? '?' . http_build_query(['lifter' => $lifter]) : '');
}

function fmt_weight($weight): string {
    return rtrim(rtrim(sprintf('%.2f', (float)$weight), '0'), '.');
}

function fmt_date(string $loggedAt): string {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $loggedAt);
    return $dt ? $dt->format('M j, Y') : $loggedAt;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Get Swole - Summary</title>
  <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
  <header>
    <nav class="lifter-nav">
      <?php foreach ($lifters as $l): ?>
        <a
          href="<?= h(summary_url($l)) ?>"
          class="lifter-link<?= $l === $lifterName ? ' active' : '' ?>"
        ><?= h($l) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="nav-links">
      <a href="index.php" class="nav-link">Back to log</a>
      <a href="<?= h('history.php' . ($lifterName !== '' ? '?' . http_build_query(['lifter' => $lifterName]) : '')) ?>" class="nav-link">View history</a>
    </div>
  </header>

  <main id="summary">
    <?php if ($lifterName === ''): ?>
      <p class="muted">Choose a lifter above to see their summary.</p>
    <?php else: ?>
      <?php foreach ($SECTIONS as $section): ?>
        <section class="summary-section">
          <h2><?= h($section) ?></h2>
          <?php if (!$bySection[$section]): ?>
            <p class="muted">No machines in this category.</p>
          <?php else: ?>
            <table class="summary-table">
              <thead>
                <tr><th>Machine</th><th>Last date</th><th>Weight</th></tr>
              </thead>
              <tbody>
                <?php foreach ($bySection[$section] as $row): ?>
                  <tr>
                    <td><?= h($row['machine_name']) ?></td>
                    <?php if ($row['logged_at'] !== null): ?>
                      <td><?= h(fmt_date($row['logged_at'])) ?></td>
                      <td><?= h(fmt_weight($row['weight'])) ?></td>
                    <?php else: ?>
                      <td class="muted" colspan="2">Not logged yet</td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</body>
</html>
