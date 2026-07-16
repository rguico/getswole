<?php
declare(strict_types=1);

header('Content-Type: application/json');

$DB = __DIR__ . '/getswole.db';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ['invalid JSON body']]);
    exit;
}

$machineId = filter_var($input['machine_id'] ?? null, FILTER_VALIDATE_INT);
if ($machineId === false || $machineId === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ['machine_id is required']]);
    exit;
}

$db = new PDO('sqlite:' . $DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT id FROM machines WHERE id = ?');
    $stmt->execute([$machineId]);
    if ($stmt->fetchColumn() === false) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'errors' => ["no machine with id $machineId"]]);
        exit;
    }

    $db->prepare(
        'DELETE FROM sets WHERE entry_id IN (SELECT id FROM exercise_entries WHERE machine_id = ?)'
    )->execute([$machineId]);

    $db->prepare('DELETE FROM exercise_entries WHERE machine_id = ?')->execute([$machineId]);

    $db->prepare('DELETE FROM machines WHERE id = ?')->execute([$machineId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => ['database error: ' . $e->getMessage()]]);
    exit;
}

echo json_encode(['ok' => true]);
