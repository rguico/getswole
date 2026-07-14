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

$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ['id is required']]);
    exit;
}

$db = new PDO('sqlite:' . $DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT entry_id FROM sets WHERE id = ?');
    $stmt->execute([$id]);
    $entryId = $stmt->fetchColumn();
    if ($entryId === false) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'errors' => ["no set with id $id"]]);
        exit;
    }

    $db->prepare('DELETE FROM sets WHERE id = ?')->execute([$id]);

    // Clean up the parent entry if that was its last remaining set.
    $stmt = $db->prepare('SELECT COUNT(*) FROM sets WHERE entry_id = ?');
    $stmt->execute([$entryId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->prepare('DELETE FROM exercise_entries WHERE id = ?')->execute([$entryId]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => ['database error: ' . $e->getMessage()]]);
    exit;
}

echo json_encode(['ok' => true]);
