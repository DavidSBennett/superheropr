<?php
// GET ?game=ID -> the full card definitions for that game's card set.
// Definitions are public (rulebook knowledge); only hands, mandates and deck
// order are secret, and those never pass through here.
require_once __DIR__ . '/bootstrap.php';

require_user();
$gameId = (int)($_GET['game'] ?? 0);
$st = db()->prepare('SELECT card_set_id FROM prg_games WHERE id = ?');
$st->execute([$gameId]);
$g = $st->fetch();
if (!$g) { json_out(['error' => 'NO_SUCH_GAME'], 404); }

$cs = load_card_set((int)$g['card_set_id']);
header('Cache-Control: private, max-age=3600');
json_out([
    'crises' => $cs->crises,
    'locations' => $cs->locations,
    'mandates' => $cs->mandates,
    'fallen' => $cs->fallen,
    'spin' => $cs->spin,
]);
