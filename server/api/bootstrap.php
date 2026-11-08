<?php
// Shared plumbing for every endpoint: config, PDO, session auth, JSON helpers.
// AUTH SHIM: prg_shim_users + a session cookie stands in for The Historians'
// AuthContext/RequireAuth until I have that source; every endpoint goes
// through require_user() so the swap is one function.

declare(strict_types=1);

require_once __DIR__ . '/../engine/Engine.php';
require_once __DIR__ . '/../engine/Validate.php';

session_name('prgsid');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']),
    'path' => '/',
]);
session_start();

function cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $local = __DIR__ . '/config.local.php';
        $cfg = is_file($local) ? require $local : require __DIR__ . '/config.sample.php';
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    $c = cfg();
    $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (str_starts_with($c['dsn'], 'sqlite:')) {
        $pdo->exec('PRAGMA foreign_keys = ON');
        ensure_sqlite_schema($pdo); // local dev convenience; MySQL uses migrations/
    }
    return $pdo;
}

function ensure_sqlite_schema(PDO $pdo): void
{
    $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='prg_games'")->fetch();
    if ($has) {
        // Dev-convenience upgrades, mirroring migrations 002+.
        $cols = array_column($pdo->query('PRAGMA table_info(prg_shim_users)')->fetchAll(), 'name');
        if (!in_array('pass_hash', $cols, true)) {
            $pdo->exec("ALTER TABLE prg_shim_users ADD COLUMN pass_hash TEXT NOT NULL DEFAULT ''");
        }
        $cols = array_column($pdo->query('PRAGMA table_info(prg_game_players)')->fetchAll(), 'name');
        if (!in_array('is_bot', $cols, true)) {
            $pdo->exec('ALTER TABLE prg_game_players ADD COLUMN is_bot INTEGER NOT NULL DEFAULT 0');
        }
        return;
    }
    $pdo->exec(<<<SQL
CREATE TABLE prg_shim_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  pass_hash TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE TABLE prg_card_sets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version TEXT NOT NULL UNIQUE,
  imported_by INTEGER NOT NULL,
  imported_at TEXT NOT NULL,
  notes TEXT NOT NULL DEFAULT ''
);
CREATE TABLE prg_cards (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  card_set_id INTEGER NOT NULL REFERENCES prg_card_sets(id),
  kind TEXT NOT NULL,
  card_id TEXT NOT NULL,
  data TEXT NOT NULL,
  UNIQUE (card_set_id, kind, card_id)
);
CREATE TABLE prg_games (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  join_code TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL,
  player_count INTEGER NOT NULL,
  card_set_id INTEGER NOT NULL REFERENCES prg_card_sets(id),
  rng_seed INTEGER NOT NULL,
  state_version INTEGER NOT NULL DEFAULT 0,
  state TEXT NOT NULL DEFAULT '',
  turn_user_id INTEGER,
  result TEXT,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE TABLE prg_game_players (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  game_id INTEGER NOT NULL REFERENCES prg_games(id),
  seat INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  is_bot INTEGER NOT NULL DEFAULT 0,
  UNIQUE (game_id, seat),
  UNIQUE (game_id, user_id)
);
CREATE TABLE prg_actions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  game_id INTEGER NOT NULL REFERENCES prg_games(id),
  seq INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  action TEXT NOT NULL,
  state_version_after INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (game_id, seq)
);
SQL);
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_in(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === '' ? '{}' : $raw, true);
    if (!is_array($data)) { json_out(['error' => 'BAD_JSON'], 400); }
    return $data;
}

function current_user(): ?array
{
    $uid = $_SESSION['prg_uid'] ?? null;
    if ($uid === null) { return null; }
    $st = db()->prepare('SELECT id, username, display_name FROM prg_shim_users WHERE id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

function require_user(): array
{
    $u = current_user();
    if ($u === null) { json_out(['error' => 'NOT_LOGGED_IN'], 401); }
    return $u;
}

function require_admin(): array
{
    $u = require_user();
    if (!in_array($u['username'], cfg()['admins'] ?? [], true)) {
        json_out(['error' => 'NOT_ADMIN'], 403);
    }
    return $u;
}

function latest_card_set_id(): int
{
    $row = db()->query('SELECT id FROM prg_card_sets ORDER BY id DESC LIMIT 1')->fetch();
    if (!$row) { json_out(['error' => 'NO_CARD_SET', 'detail' => 'import a card set first'], 500); }
    return (int)$row['id'];
}

function load_card_set(int $cardSetId): CardSet
{
    $st = db()->prepare('SELECT kind, card_id, data FROM prg_cards WHERE card_set_id = ?');
    $st->execute([$cardSetId]);
    $rows = [];
    foreach ($st as $r) {
        $rows[] = ['kind' => $r['kind'], 'card_id' => $r['card_id'], 'data' => json_decode($r['data'], true)];
    }
    if (!$rows) { json_out(['error' => 'NO_CARD_SET'], 500); }
    return CardSet::fromRows($rows);
}

/** seat of $userId in decoded $state, or null */
function seat_of(array $state, int $userId): ?int
{
    foreach ($state['players'] as $p) {
        if ((int)$p['userId'] === $userId) { return $p['seat']; }
    }
    return null;
}

/** user id whose input the game is waiting on, or null */
function waiting_on(array $state): ?int
{
    if ($state['phase'] === 'finished') { return null; }
    $seat = $state['pending']['seat'] ?? $state['activeSeat'];
    return (int)$state['players'][$seat]['userId'];
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}
