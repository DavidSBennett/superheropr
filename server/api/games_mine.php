<?php
// GET -> games this user is in; feeds the Your Games widget.
require_once __DIR__ . '/bootstrap.php';

$u = require_user();
$st = db()->prepare(
    'SELECT g.id, g.join_code, g.status, g.player_count, g.turn_user_id, g.updated_at, p.seat
     FROM prg_games g JOIN prg_game_players p ON p.game_id = g.id
     WHERE p.user_id = ? ORDER BY g.updated_at DESC LIMIT 25'
);
$st->execute([(int)$u['id']]);
$out = [];
foreach ($st as $g) {
    $cnt = db()->prepare('SELECT COUNT(*) c FROM prg_game_players WHERE game_id = ?');
    $cnt->execute([(int)$g['id']]);
    $out[] = [
        'gameId' => (int)$g['id'],
        'joinCode' => $g['join_code'],
        'status' => $g['status'],
        'seat' => (int)$g['seat'],
        'playersJoined' => (int)$cnt->fetch()['c'],
        'playerCount' => (int)$g['player_count'],
        'yourTurn' => $g['status'] === 'active' && (int)$g['turn_user_id'] === (int)$u['id'],
        'updatedAt' => $g['updated_at'],
    ];
}
json_out(['games' => $out]);
