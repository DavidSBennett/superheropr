<?php
// Dev helper: import data/*.csv into whatever DB config.local.php points at.
// Usage: php import_local.php [version-label]
require_once __DIR__ . '/../api/bootstrap.php';

$version = $argv[1] ?? gmdate('Y-m-d-His');
[$cards, $errors, $report] = Validate::loadCsvDir(__DIR__ . '/../../data');
if ($cards === null) {
    fwrite(STDERR, "invalid card data:\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}
$pdo = db();
$pdo->beginTransaction();
$pdo->prepare('INSERT INTO prg_card_sets (version, imported_by, imported_at, notes) VALUES (?,0,?,?)')
    ->execute([$version, now(), 'CLI import']);
$setId = (int)$pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO prg_cards (card_set_id, kind, card_id, data) VALUES (?,?,?,?)');
$n = 0;
foreach (['crisis' => $cards->crises, 'location' => $cards->locations, 'mandate' => $cards->mandates,
          'fallen' => $cards->fallen, 'spin' => $cards->spin] as $kind => $set) {
    foreach ($set as $id => $data) {
        $ins->execute([$setId, $kind, $id, json_encode($data, JSON_UNESCAPED_SLASHES)]);
        $n++;
    }
}
$pdo->commit();
echo implode("\n", $report) . "\n";
echo "imported card set $version (id $setId, $n cards)\n";
