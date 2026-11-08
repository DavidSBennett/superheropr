<?php
// Server-side bot turns for solo play. Shared hosting has no background
// workers, so bots move inside ordinary requests: act.php calls this after a
// human action commits, and state.php calls it when a poll finds the game
// waiting on a bot. Each bot action is its own transaction with the same
// optimistic-concurrency guard as a human action, and is appended to
// prg_actions under the bot's shim user id, so replays treat bots and humans
// identically. Concurrent pollers serialize on the row lock; the loser simply
// finds it is no longer a bot's turn.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../engine/Bot.php';

const PRG_BOTS = [ // username => display name (platform fixtures, not cards)
    'bot_alpha' => 'Spin Cycle LLC (bot)',
    'bot_beta' => 'Quiet Favor Group (bot)',
    'bot_gamma' => 'Second Draft Agency (bot)',
    'bot_delta' => 'Beige Umbrella PR (bot)',
];

/** Get-or-create the fixed bot shim users. @return int[] user ids in order */
function bot_user_ids(PDO $pdo, int $count): array
{
    $ids = [];
    foreach (array_slice(array_keys(PRG_BOTS), 0, $count) as $username) {
        $st = $pdo->prepare('SELECT id FROM prg_shim_users WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch();
        if ($row) {
            $ids[] = (int)$row['id'];
        } else {
            $pdo->prepare("INSERT INTO prg_shim_users (username, display_name, pass_hash, created_at) VALUES (?,?,'!',?)")
                ->execute([$username, PRG_BOTS[$username], now()]);
            $ids[] = (int)$pdo->lastInsertId();
        }
    }
    return $ids;
}

/** Play bot turns until a human is up, the game ends, or the cap is hit. */
function advance_bots(int $gameId): void
{
    $pdo = db();
    $isSqlite = str_starts_with(cfg()['dsn'], 'sqlite:');
    $botSeats = null; // seat => user_id, lazily loaded

    for ($i = 0; $i < 300; $i++) {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM prg_games WHERE id = ?' . ($isSqlite ? '' : ' FOR UPDATE'));
            $st->execute([$gameId]);
            $g = $st->fetch();
            if (!$g || $g['status'] !== 'active') {
                $pdo->rollBack();
                return;
            }
            if ($botSeats === null) {
                $ps = $pdo->prepare('SELECT seat, user_id FROM prg_game_players WHERE game_id = ? AND is_bot = 1');
                $ps->execute([$gameId]);
                $botSeats = [];
                foreach ($ps as $p) { $botSeats[(int)$p['seat']] = (int)$p['user_id']; }
                if (!$botSeats) { $pdo->rollBack(); return; }
            }
            $state = json_decode($g['state'], true);
            $seat = $state['pending']['seat'] ?? $state['activeSeat'];
            if ($state['phase'] === 'finished' || !isset($botSeats[$seat])) {
                $pdo->rollBack();
                return;
            }
            $cards = load_card_set((int)$g['card_set_id']);
            // Seed per decision from the game seed + version: deterministic for
            // a given spot in the game, and the action log is authoritative.
            $rng = Rng::seedState((int)(((int)$g['rng_seed'] ^ ((int)$g['state_version'] * 2654435761)) & 0x7FFFFFFF));
            $action = Bot::action('defensive', $state, $cards, $seat, $rng);
            $res = Engine::applyAction($state, $cards, $seat, $action);
            if (!($res['ok'] ?? false)) {
                // A bot must never wedge a live game: log and stop advancing.
                error_log("prg bot illegal move game=$gameId seat=$seat {$res['error']} " . json_encode($action));
                $pdo->rollBack();
                return;
            }
            $newState = $res['state'];
            $newVersion = (int)$g['state_version'] + 1;
            $status = $newState['phase'] === 'finished' ? 'finished' : 'active';
            $upd = $pdo->prepare('UPDATE prg_games SET state=?, state_version=?, status=?, turn_user_id=?, result=?, updated_at=? WHERE id=? AND state_version=?');
            $upd->execute([
                json_encode($newState, JSON_UNESCAPED_SLASHES), $newVersion, $status,
                waiting_on($newState),
                $newState['result'] !== null ? json_encode($newState['result']) : null,
                now(), $gameId, (int)$g['state_version'],
            ]);
            if ($upd->rowCount() !== 1) {
                $pdo->rollBack();
                return; // someone else moved first; they will advance the bots
            }
            $pdo->prepare('INSERT INTO prg_actions (game_id, seq, user_id, action, state_version_after, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$gameId, $newVersion, $botSeats[$seat], json_encode($action, JSON_UNESCAPED_SLASHES), $newVersion, now()]);
            $pdo->commit();
            if ($status === 'finished') {
                return;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("prg bot error game=$gameId: " . $e->getMessage());
            return;
        }
    }
}
