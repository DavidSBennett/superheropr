<?php
// POST {gameId, expectedVersion, action} -> {version, state, private?}
// Optimistic concurrency: the UPDATE is guarded by state_version; a stale
// client gets 409 with the fresh state. The action log is append-only.
require_once __DIR__ . '/bootstrap.php';

$u = require_user();
$in = json_in();
$gameId = (int)($in['gameId'] ?? 0);
$expected = (int)($in['expectedVersion'] ?? -1);
$action = $in['action'] ?? null;
if (!is_array($action)) { json_out(['error' => 'BAD_ACTION'], 422); }

$pdo = db();
$isSqlite = str_starts_with(cfg()['dsn'], 'sqlite:');
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT * FROM prg_games WHERE id = ?' . ($isSqlite ? '' : ' FOR UPDATE'));
    $st->execute([$gameId]);
    $g = $st->fetch();
    if (!$g) { $pdo->rollBack(); json_out(['error' => 'NO_SUCH_GAME'], 404); }
    if ($g['status'] !== 'active') { $pdo->rollBack(); json_out(['error' => 'NOT_ACTIVE'], 409); }

    $state = json_decode($g['state'], true);
    $seat = seat_of($state, (int)$u['id']);
    if ($seat === null) { $pdo->rollBack(); json_out(['error' => 'NOT_IN_GAME'], 403); }
    if ((int)$g['state_version'] !== $expected) {
        $pdo->rollBack();
        json_out(['error' => 'STALE', 'version' => (int)$g['state_version'],
                  'state' => Engine::filterForViewer($state, $seat)], 409);
    }

    $cards = load_card_set((int)$g['card_set_id']);
    $res = Engine::applyAction($state, $cards, $seat, $action);
    if (!($res['ok'] ?? false)) {
        $pdo->rollBack();
        json_out(['error' => $res['error'], 'detail' => $res['detail'] ?? ''], 422);
    }
    $newState = $res['state'];
    $newVersion = $expected + 1;
    $status = $newState['phase'] === 'finished' ? 'finished' : 'active';

    $upd = $pdo->prepare('UPDATE prg_games SET state=?, state_version=?, status=?, turn_user_id=?, result=?, updated_at=? WHERE id=? AND state_version=?');
    $upd->execute([
        json_encode($newState, JSON_UNESCAPED_SLASHES), $newVersion, $status,
        waiting_on($newState),
        $newState['result'] !== null ? json_encode($newState['result']) : null,
        now(), $gameId, $expected,
    ]);
    if ($upd->rowCount() !== 1) {
        $pdo->rollBack();
        json_out(['error' => 'STALE'], 409);
    }
    $pdo->prepare('INSERT INTO prg_actions (game_id, seq, user_id, action, state_version_after, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$gameId, $newVersion, (int)$u['id'], json_encode($action, JSON_UNESCAPED_SLASHES), $newVersion, now()]);
    $pdo->commit();

    $out = ['version' => $newVersion, 'state' => Engine::filterForViewer($newState, $seat)];
    if (isset($res['private'])) { $out['private'] = $res['private']; }
    json_out($out);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    json_out(['error' => 'ACT_FAILED', 'detail' => $e->getMessage()], 500);
}
