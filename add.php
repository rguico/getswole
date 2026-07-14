<?php
declare(strict_types=1);

// Ports the grammar in scripts/parse_getswole.py (see that file for the
// derivation) so live input parses identically to the .md file.

header('Content-Type: application/json');

$DB = __DIR__ . '/getswole.db';

function split_top_level_commas(string $text): array {
    $parts = [];
    $depth = 0;
    $current = '';
    for ($i = 0, $len = strlen($text); $i < $len; $i++) {
        $ch = $text[$i];
        if ($ch === '(') $depth++;
        elseif ($ch === ')') $depth--;
        if ($ch === ',' && $depth === 0) {
            $parts[] = $current;
            $current = '';
        } else {
            $current .= $ch;
        }
    }
    $parts[] = $current;
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

/** @return array{0: ?float, 1: ?int, 2: ?string} */
function parse_token(string $token): array {
    if (!preg_match('/^\s*([\d.]+)\s*((?:\([^)]*\)\s*)*)(.*)$/', $token, $m)) {
        $trimmed = trim($token);
        return [null, null, $trimmed !== '' ? $trimmed : null];
    }
    [, $value, $parenBlob, $trailing] = $m;
    $multiplier = null;
    $comments = [];
    if (preg_match_all('/\(([^)]*)\)/', $parenBlob, $pm)) {
        foreach ($pm[1] as $content) {
            $content = trim($content);
            if ($content === '') continue;
            if (ctype_digit($content)) {
                $multiplier = (int)$content;
            } else {
                $comments[] = $content;
            }
        }
    }
    $trailing = trim($trailing);
    if ($trailing !== '') $comments[] = $trailing;
    $comment = $comments ? implode('; ', $comments) : null;
    return [(float)$value, $multiplier, $comment];
}

function build_groups(string $expr): array {
    $chunks = preg_split('/\s*(?<![a-z])x(?![a-z])\s*/i', trim($expr));
    $groups = [];
    $repsText = $chunks[0];
    $n = count($chunks);
    for ($i = 1; $i < $n; $i++) {
        $items = split_top_level_commas($chunks[$i]);
        if ($i < $n - 1) {
            $groups[] = [$repsText, array_slice($items, 0, -1)];
            $repsText = end($items);
        } else {
            $groups[] = [$repsText, $items];
        }
    }
    return $groups;
}

/** @return array{0: array, 1: ?string, 2: string[]} */
function parse_expr(string $expr): array {
    $groups = build_groups($expr);
    $rows = [];
    $entryComments = [];
    $warnings = [];
    $loneGroup = count($groups) === 1;

    foreach ($groups as [$repsText, $weightTokens]) {
        $repsVals = [];
        foreach (split_top_level_commas($repsText) as $rt) {
            [$val, $mult, $comment] = parse_token($rt);
            if ($val === null) {
                $warnings[] = "unparsed reps token: \"$rt\"";
                continue;
            }
            if ($mult !== null) {
                $warnings[] = "unexpected multiplier on reps token: \"$rt\"";
            }
            $repsVals[] = (int)$val;
            if ($comment) $entryComments[] = $comment;
        }

        $weights = [];
        foreach ($weightTokens as $wt) {
            [$val, $mult, $comment] = parse_token($wt);
            if ($val === null) {
                $warnings[] = "unparsed weight token: \"$wt\"";
                continue;
            }
            $weights[] = [$val, $mult, $comment];
        }

        if (!$repsVals || !$weights) continue;

        $numReps = count($repsVals);
        $numWeights = count($weights);

        if ($numReps === 1 && $numWeights === 1) {
            [$weight, $mult, $comment] = $weights[0];
            $numSets = $mult !== null ? $mult : ($loneGroup ? 3 : 1);
            $rows[] = [$repsVals[0], $weight, $numSets, $comment];
        } elseif ($numReps > 1 && $numWeights === 1) {
            [$weight, $mult, $comment] = $weights[0];
            if ($mult !== null && $mult !== $numReps) {
                $warnings[] = "multiplier ($mult) on weight doesn't match reps count ($numReps)";
            }
            foreach ($repsVals as $reps) {
                $rows[] = [$reps, $weight, 1, $comment];
            }
        } elseif ($numReps === 1 && $numWeights > 1) {
            $reps = $repsVals[0];
            foreach ($weights as [$weight, $mult, $comment]) {
                $rows[] = [$reps, $weight, $mult ?? 1, $comment];
            }
        } elseif ($numReps === $numWeights) {
            foreach ($repsVals as $idx => $reps) {
                [$weight, $mult, $comment] = $weights[$idx];
                $rows[] = [$reps, $weight, $mult ?? 1, $comment];
            }
        } else {
            $warnings[] = "mismatched reps/weight counts";
        }
    }

    $entryComment = $entryComments ? implode('; ', $entryComments) : null;
    return [$rows, $entryComment, $warnings];
}

// --- request handling ---

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ['invalid JSON body']]);
    exit;
}

$lifterName = trim((string)($input['lifter'] ?? ''));
$machineId = filter_var($input['machine_id'] ?? null, FILTER_VALIDATE_INT);
$expr = trim((string)($input['expr'] ?? ''));

if ($lifterName === '' || $machineId === false || $machineId === null || $expr === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ['lifter, machine_id, and expr are required']]);
    exit;
}

$db = new PDO('sqlite:' . $DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$stmt = $db->prepare('SELECT id FROM lifters WHERE name = ?');
$stmt->execute([$lifterName]);
$lifterId = $stmt->fetchColumn();
if ($lifterId === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ["unknown lifter: $lifterName"]]);
    exit;
}

$stmt = $db->prepare('SELECT name FROM machines WHERE id = ?');
$stmt->execute([$machineId]);
$machineName = $stmt->fetchColumn();
if ($machineName === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ["unknown machine id: $machineId"]]);
    exit;
}

[$rows, $entryComment, $warnings] = parse_expr($expr);

if ($warnings) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => $warnings]);
    exit;
}

if (!$rows) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => ['could not parse any sets from input']]);
    exit;
}

$db->beginTransaction();
try {
    // One session per calendar day per lifter; reuse today's if it exists.
    $stmt = $db->prepare(
        "SELECT id FROM sessions WHERE lifter_id = ? AND date(logged_at) = date('now') ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$lifterId]);
    $sessionId = $stmt->fetchColumn();

    if ($sessionId === false) {
        $stmt = $db->prepare('SELECT COALESCE(MAX(session_order), 0) + 1 FROM sessions WHERE lifter_id = ?');
        $stmt->execute([$lifterId]);
        $sessionOrder = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('INSERT INTO sessions (lifter_id, session_order) VALUES (?, ?)');
        $stmt->execute([$lifterId, $sessionOrder]);
        $sessionId = (int)$db->lastInsertId();
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(entry_order), 0) + 1 FROM exercise_entries WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $entryOrder = (int)$stmt->fetchColumn();

    $rawText = "$machineName: $expr";
    $stmt = $db->prepare(
        'INSERT INTO exercise_entries (session_id, machine_id, entry_order, raw_text, comment) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sessionId, $machineId, $entryOrder, $rawText, $entryComment]);
    $entryId = (int)$db->lastInsertId();

    $stmt = $db->prepare(
        'INSERT INTO sets (entry_id, set_order, reps, weight, num_sets, comment) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $setOrder = 0;
    foreach ($rows as [$reps, $weight, $numSets, $comment]) {
        $setOrder++;
        $stmt->execute([$entryId, $setOrder, $reps, $weight, $numSets, $comment]);
    }

    $stmt = $db->prepare(
        "SELECT st.id, ? AS lifter, e.machine_id, e.logged_at, st.reps, st.weight, st.num_sets, st.comment
         FROM sets st
         JOIN exercise_entries e ON e.id = st.entry_id
         WHERE st.entry_id = ?
         ORDER BY st.set_order"
    );
    $stmt->execute([$lifterName, $entryId]);
    $newSets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => ['database error: ' . $e->getMessage()]]);
    exit;
}

foreach ($newSets as &$row) {
    $row['machine_id'] = (int)$row['machine_id'];
    $row['reps'] = (int)$row['reps'];
    $row['weight'] = (float)$row['weight'];
    $row['num_sets'] = (int)$row['num_sets'];
}
unset($row);

echo json_encode(['ok' => true, 'sets' => $newSets]);
