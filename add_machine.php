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

$name = trim((string)($input['name'] ?? ''));
if ($name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ['name is required']]);
    exit;
}

$db = new PDO('sqlite:' . $DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $db->prepare('SELECT id FROM machines WHERE name = ? COLLATE NOCASE');
$stmt->execute([$name]);
if ($stmt->fetchColumn() !== false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ["machine already exists: $name"]]);
    exit;
}

try {
    $stmt = $db->prepare('INSERT INTO machines (name, muscle_group_id) VALUES (?, NULL)');
    $stmt->execute([$name]);
    $id = (int)$db->lastInsertId();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => ['database error: ' . $e->getMessage()]]);
    exit;
}

echo json_encode(['ok' => true, 'machine' => ['id' => $id, 'name' => $name, 'muscle_group' => null]]);
