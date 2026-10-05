<?php
declare(strict_types=1);

$DB = __DIR__ . '/getswole.db';

$db = new PDO('sqlite:' . $DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$lifters = $db->query('SELECT name FROM lifters ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$lifterName = (string)($_GET['lifter'] ?? '');
if (!in_array($lifterName, $lifters, true)) {
    $lifterName = '';
}

$requestedDate = (string)($_GET['date'] ?? '');
$date = null;
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $requestedDate, $m)
    && checkdate((int)$m[2], (int)$m[3], (int)$m[1])
) {
    $date = $requestedDate;
}

$rows = [];
$mostRecentDate = null;

if ($lifterName !== '') {
    $stmt = $db->prepare(
        "SELECT MAX(date(e.logged_at))
         FROM exercise_entries e
         JOIN sessions s ON s.id = e.session_id
         JOIN lifters l ON l.id = s.lifter_id
         WHERE l.name = ?"
    );
    $stmt->execute([$lifterName]);
    $mostRecentDate = $stmt->fetchColumn() ?: null;

    if ($date === null) {
        $date = $mostRecentDate;
    }

    if ($date !== null) {
        $stmt = $db->prepare(
            "SELECT m.name AS machine, st.reps, st.weight, st.num_sets, st.comment
             FROM sets st
             JOIN exercise_entries e ON e.id = st.entry_id
             JOIN sessions s ON s.id = e.session_id
             JOIN lifters l ON l.id = s.lifter_id
             JOIN machines m ON m.id = e.machine_id
             WHERE l.name = ? AND date(e.logged_at) = ?
             ORDER BY s.session_order, e.entry_order, st.set_order"
        );
        $stmt->execute([$lifterName, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function history_url(?string $lifter, ?string $date): string {
    $params = [];
    if ($lifter !== null && $lifter !== '') $params['lifter'] = $lifter;
    if ($date !== null && $date !== '') $params['date'] = $date;
    return 'history.php' . ($params ? '?' . http_build_query($params) : '');
}

function fmt_weight($weight): string {
    return rtrim(rtrim(sprintf('%.2f', (float)$weight), '0'), '.');
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

$prevDate = null;
$nextDate = null;
$heading = null;
if ($date !== null) {
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    $heading = $dateObj->format('l, F j, Y');

    $stmt = $db->prepare(
        "SELECT MAX(date(e.logged_at))
         FROM exercise_entries e
         JOIN sessions s ON s.id = e.session_id
         JOIN lifters l ON l.id = s.lifter_id
         WHERE l.name = ? AND date(e.logged_at) < ?"
    );
    $stmt->execute([$lifterName, $date]);
    $prevDate = $stmt->fetchColumn() ?: null;

    $stmt = $db->prepare(
        "SELECT MIN(date(e.logged_at))
         FROM exercise_entries e
         JOIN sessions s ON s.id = e.session_id
         JOIN lifters l ON l.id = s.lifter_id
         WHERE l.name = ? AND date(e.logged_at) > ?"
    );
    $stmt->execute([$lifterName, $date]);
    $nextDate = $stmt->fetchColumn() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Get Swole - History</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <header>
    <nav class="lifter-nav">
      <?php foreach ($lifters as $l): ?>
        <a
          href="<?= h(history_url($l, null)) ?>"
          class="lifter-link<?= $l === $lifterName ? ' active' : '' ?>"
        ><?= h($l) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="nav-links">
      <a href="index.html" class="nav-link">Back to log</a>
      <a href="<?= h('summary.php' . ($lifterName !== '' ? '?' . http_build_query(['lifter' => $lifterName]) : '')) ?>" class="nav-link">View summary</a>
    </div>
  </header>

  <main id="history">
    <?php if ($lifterName === ''): ?>
      <p class="muted">Choose a lifter above to see their history.</p>
    <?php elseif ($date === null): ?>
      <p class="muted">No workouts logged yet for <?= h($lifterName) ?>.</p>
    <?php else: ?>
      <nav class="day-nav">
        <?php if ($prevDate !== null): ?>
          <a href="<?= h(history_url($lifterName, $prevDate)) ?>" class="day-nav-link">&lsaquo; Prev day</a>
        <?php else: ?>
          <span class="day-nav-link disabled">&lsaquo; Prev day</span>
        <?php endif; ?>
        <h2><?= h($heading) ?></h2>
        <?php if ($nextDate !== null): ?>
          <a href="<?= h(history_url($lifterName, $nextDate)) ?>" class="day-nav-link">Next day &rsaquo;</a>
        <?php else: ?>
          <span class="day-nav-link disabled">Next day &rsaquo;</span>
        <?php endif; ?>
      </nav>
      <?php if ($date !== $mostRecentDate): ?>
        <p><a href="<?= h(history_url($lifterName, null)) ?>">Jump to most recent day</a></p>
      <?php endif; ?>

      <div class="sets-table-wrap">
        <?php if (!$rows): ?>
          <p>No sets logged on this day.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr><th>Machine</th><th>Reps</th><th>Weight</th><th>Sets</th><th>Comment</th></tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h($row['machine']) ?></td>
                  <td><?= h($row['reps']) ?></td>
                  <td><?= h(fmt_weight($row['weight'])) ?></td>
                  <td><?= h($row['num_sets']) ?></td>
                  <td><?= h($row['comment'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
