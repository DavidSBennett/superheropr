<?php
// GET ?game=ID&since=V -> {unchanged:true} | {version, you, players, state}
// Conditional short-poll endpoint: the unchanged response is ~20 bytes.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/botturns.php';

$u = require_user();
$gameId = (int)($_GET['game'] ?? 0);
$since = (int)($_GET['since'] ?? -1);

$st = db()->prepare('SELECT * FROM prg_games WHERE id = ?');
$st->execute([$gameId]);
$g = $st->fetch();
if (!$g) { json_out(['error' => 'NO_SUCH_GAME'], 404); }

$ps = db()->prepare(
    'SELECT p.seat, p.user_id, p.is_bot, s.display_name FROM prg_game_players p
     JOIN prg_shim_users s ON s.id = p.user_id WHERE p.game_id = ? ORDER BY p.seat'
);
$ps->execute([$gameId]);
$players = $ps->fetchAll();

// Safety net for solo games: if a poll finds the game waiting on a bot
// (e.g. after a timeout or error), push the bots along, then re-read.
$botUserIds = array_map(fn($p) => (int)$p['user_id'], array_filter($players, fn($p) => (int)$p['is_bot'] === 1));
if ($g['status'] === 'active' && in_array((int)$g['turn_user_id'], $botUserIds, true)) {
    advance_bots($gameId);
    $st = db()->prepare('SELECT * FROM prg_games WHERE id = ?');
    $st->execute([$gameId]);
    $g = $st->fetch();
}
$mySeat = null;
foreach ($players as $p) {
    if ((int)$p['user_id'] === (int)$u['id']) { $mySeat = (int)$p['seat']; }
}
if ($mySeat === null) { json_out(['error' => 'NOT_IN_GAME'], 403); }

if ((int)$g['state_version'] === $since && $g['status'] !== 'lobby') {
    json_out(['unchanged' => true]);
}

$names = [];
foreach ($players as $p) {
    $names[] = ['seat' => (int)$p['seat'], 'name' => $p['display_name'], 'isBot' => (int)$p['is_bot'] === 1];
}
$base = [
    'version' => (int)$g['state_version'],
    'status' => $g['status'],
    'joinCode' => $g['join_code'],
    'playerCount' => (int)$g['player_count'],
    'createdBy' => (int)$g['created_by'],
    'you' => ['seat' => $mySeat, 'userId' => (int)$u['id']],
    'names' => $names,
];
if ($g['status'] === 'lobby') {
    json_out($base + ['state' => null]);
}
$state = json_decode($g['state'], true);
json_out($base + ['state' => Engine::filterForViewer($state, $mySeat)]);
