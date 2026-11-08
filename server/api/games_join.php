<?php
// POST {joinCode} -> {gameId, seat}
require_once __DIR__ . '/bootstrap.php';

$u = require_user();
$in = json_in();
$code = strtoupper(trim((string)($in['joinCode'] ?? '')));

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT * FROM prg_games WHERE join_code = ?');
    $st->execute([$code]);
    $g = $st->fetch();
    if (!$g) { $pdo->rollBack(); json_out(['error' => 'NO_SUCH_GAME'], 404); }

    $st = $pdo->prepare('SELECT seat, user_id FROM prg_game_players WHERE game_id = ? ORDER BY seat');
    $st->execute([$g['id']]);
    $players = $st->fetchAll();
    foreach ($players as $p) {
        if ((int)$p['user_id'] === (int)$u['id']) {
            $pdo->commit();
            json_out(['gameId' => (int)$g['id'], 'seat' => (int)$p['seat']]); // already in
        }
    }
    if ($g['status'] !== 'lobby') { $pdo->rollBack(); json_out(['error' => 'ALREADY_STARTED'], 409); }
    if (count($players) >= (int)$g['player_count']) { $pdo->rollBack(); json_out(['error' => 'GAME_FULL'], 409); }

    $seat = count($players);
    $pdo->prepare('INSERT INTO prg_game_players (game_id, seat, user_id) VALUES (?,?,?)')
        ->execute([(int)$g['id'], $seat, (int)$u['id']]);
    $pdo->prepare('UPDATE prg_games SET updated_at = ? WHERE id = ?')->execute([now(), (int)$g['id']]);
    $pdo->commit();
    json_out(['gameId' => (int)$g['id'], 'seat' => $seat]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['error' => 'JOIN_FAILED', 'detail' => $e->getMessage()], 500);
}
