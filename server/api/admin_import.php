<?php
// Card importer. GET renders a minimal upload form; POST validates and, if
// clean, writes a new prg_card_sets version. Accepts the five CSVs; accepts
// pr_game_cards.xlsx instead only when PhpSpreadsheet is installed on the
// host (vendor/autoload.php present). Existing games keep their card set.
require_once __DIR__ . '/bootstrap.php';

$u = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    $files = implode('', array_map(
        fn($f) => "<label>$f.csv <input type=file name=$f accept=.csv></label><br>",
        array_keys(Validate::HEADERS)
    ));
    echo "<!doctype html><title>Superhero P.R. - card import</title>
<body style='font-family:system-ui;max-width:40rem;margin:2rem auto'>
<h1>Card import</h1>
<form method=post enctype=multipart/form-data>
<label>Version label <input name=version required placeholder='2026-11-07-a'></label><br><br>
$files<br>
<label>Or workbook <input type=file name=xlsx accept=.xlsx></label>
<p><label><input type=checkbox name=allow_counts value=1> allow unexpected row counts</label></p>
<button>Validate and import</button></form>";
    exit;
}

$version = trim((string)($_POST['version'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version)) {
    json_out(['ok' => false, 'errors' => ['bad version label']], 422);
}
$enforceCounts = empty($_POST['allow_counts']);

$fileBytes = []; // name => raw csv bytes
if (!empty($_FILES['xlsx']['tmp_name'])) {
    if (!is_file(__DIR__ . '/../../vendor/autoload.php')) {
        json_out(['ok' => false, 'errors' => ['PhpSpreadsheet not installed on this host; upload the CSVs instead']], 422);
    }
    require_once __DIR__ . '/../../vendor/autoload.php';
    $wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['xlsx']['tmp_name']);
    foreach ($wb->getSheetNames() as $sheet) {
        if (!isset(Validate::HEADERS[$sheet])) { continue; }
        $rows = $wb->getSheetByName($sheet)->toArray(null, true, false, false);
        $lines = [];
        foreach ($rows as $r) {
            if (implode('', array_map(strval(...), $r)) === '') { continue; }
            $lines[] = implode(',', array_map(function ($v) {
                $v = (string)$v;
                return (str_contains($v, ',') || str_contains($v, '"'))
                    ? '"' . str_replace('"', '""', $v) . '"' : $v;
            }, $r));
        }
        $fileBytes[$sheet] = implode("\r\n", $lines) . "\r\n";
    }
}
foreach (array_keys(Validate::HEADERS) as $name) {
    if (!empty($_FILES[$name]['tmp_name'])) {
        $fileBytes[$name] = file_get_contents($_FILES[$name]['tmp_name']);
    }
}
if (!$fileBytes) { json_out(['ok' => false, 'errors' => ['no files uploaded']], 422); }

// A card set must be complete: all five files, every time.
$missing = array_diff(array_keys(Validate::HEADERS), array_keys($fileBytes));
if ($missing) {
    json_out(['ok' => false, 'errors' => ['incomplete card set, missing: ' . implode(', ', $missing)]], 422);
}

$allRows = [];
$errors = [];
$report = [];
foreach ($fileBytes as $name => $bytes) {
    [$rows, $errs, $rep] = Validate::file($name, $bytes, $enforceCounts);
    array_push($errors, ...$errs);
    array_push($report, ...$rep);
    foreach ($rows as $row) {
        $allRows[] = ['kind' => Validate::FILE_TO_KIND[$name], 'card_id' => $row['id'], 'data' => $row];
    }
}
if ($errors) { json_out(['ok' => false, 'errors' => $errors, 'report' => $report], 422); }

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO prg_card_sets (version, imported_by, imported_at, notes) VALUES (?,?,?,?)')
        ->execute([$version, (int)$u['id'], now(), 'import via admin_import.php']);
    $setId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO prg_cards (card_set_id, kind, card_id, data) VALUES (?,?,?,?)');
    foreach ($allRows as $r) {
        $ins->execute([$setId, $r['kind'], $r['card_id'], json_encode($r['data'], JSON_UNESCAPED_SLASHES)]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['ok' => false, 'errors' => ['db: ' . $e->getMessage()]], 500);
}
json_out(['ok' => true, 'cardSetId' => $setId, 'version' => $version, 'report' => $report, 'cards' => count($allRows)]);
