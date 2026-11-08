<?php
// POST {gameId} -> {version}. Creator only, once all seats are filled.
require_once __DIR__ . '/bootstrap.php';

$u = require_user();
$in = json_in();
$gameId = (int)($in['gameId'] ?? 0);

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT * FROM prg_games WHERE id = ?');
    $st->execute([$gameId]);
    $g = $st->fetch();
    if (!$g) { $pdo->rollBack(); json_out(['error' => 'NO_SUCH_GAME'], 404); }
    if ((int)$g['created_by'] !== (int)$u['id']) { $pdo->rollBack(); json_out(['error' => 'NOT_CREATOR'], 403); }
    if ($g['status'] !== 'lobby') { $pdo->rollBack(); json_out(['error' => 'ALREADY_STARTED'], 409); }

    $st = $pdo->prepare('SELECT seat, user_id FROM prg_game_players WHERE game_id = ? ORDER BY seat');
    $st->execute([$gameId]);
    $players = $st->fetchAll();
    if (count($players) !== (int)$g['player_count']) {
        $pdo->rollBack();
        json_out(['error' => 'NOT_FULL', 'detail' => count($players) . '/' . $g['player_count'] . ' seats filled'], 409);
    }

    $cards = load_card_set((int)$g['card_set_id']);
    $state = Engine::newGame($cards, array_map(fn($p) => (int)$p['user_id'], $players), (int)$g['rng_seed']);
    $pdo->prepare('UPDATE prg_games SET status=\'active\', state_version=1, state=?, turn_user_id=?, updated_at=? WHERE id=?')
        ->execute([json_encode($state, JSON_UNESCAPED_SLASHES), waiting_on($state), now(), $gameId]);
    $pdo->commit();
    json_out(['version' => 1]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['error' => 'START_FAILED', 'detail' => $e->getMessage()], 500);
}
