<?php
declare(strict_types=1);

header('Content-Type: application/json');

$DB = __DIR__ . '/getswole.db';

$db = new PDO('sqlite:' . $DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$machines = $db->query(
    'SELECT m.id, m.name, g.name AS muscle_group
     FROM machines m
     LEFT JOIN muscle_groups g ON g.id = m.muscle_group_id
     ORDER BY m.name'
)->fetchAll(PDO::FETCH_ASSOC);

$sets = $db->query(
    'SELECT st.id, l.name AS lifter, e.machine_id, e.logged_at,
            st.reps, st.weight, st.num_sets, st.comment
     FROM sets st
     JOIN exercise_entries e ON e.id = st.entry_id
     JOIN sessions s ON s.id = e.session_id
     JOIN lifters l ON l.id = s.lifter_id'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($machines as &$row) {
    $row['id'] = (int)$row['id'];
}
unset($row);

foreach ($sets as &$row) {
    $row['id'] = (int)$row['id'];
    $row['machine_id'] = (int)$row['machine_id'];
    $row['reps'] = (int)$row['reps'];
    $row['weight'] = (float)$row['weight'];
    $row['num_sets'] = (int)$row['num_sets'];
}
unset($row);

echo json_encode(['machines' => $machines, 'sets' => $sets]);
