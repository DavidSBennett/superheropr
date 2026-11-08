<?php
// POST {playerCount} -> {gameId, joinCode}. Creator takes seat 0.
require_once __DIR__ . '/bootstrap.php';

$u = require_user();
$in = json_in();
$n = (int)($in['playerCount'] ?? 0);
if ($n < 2 || $n > 5) { json_out(['error' => 'BAD_PLAYER_COUNT'], 422); }

$pdo = db();
$cardSetId = latest_card_set_id();
$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
for ($try = 0; $try < 20; $try++) {
    $code = '';
    for ($i = 0; $i < 6; $i++) { $code .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
    try {
        $pdo->prepare('INSERT INTO prg_games (join_code, status, player_count, card_set_id, rng_seed, state_version, state, created_by, created_at, updated_at)
                       VALUES (?,?,?,?,?,0,\'\',?,?,?)')
            ->execute([$code, 'lobby', $n, $cardSetId, random_int(1, PHP_INT_MAX - 1), (int)$u['id'], now(), now()]);
        $gameId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO prg_game_players (game_id, seat, user_id) VALUES (?,0,?)')
            ->execute([$gameId, (int)$u['id']]);
        json_out(['gameId' => $gameId, 'joinCode' => $code]);
    } catch (PDOException $e) {
        // join_code collision: retry
    }
}
json_out(['error' => 'CODE_COLLISION'], 500);
