<?php
// AUTH SHIM with passwords - replaced by The Historians login once I have
// its source. POST {username, password, displayName?} -> {user}
// - new username: creates the account with that password
// - existing username with no password yet (pre-upgrade row): the first
//   password login claims it
// - existing username with a password: must match
require_once __DIR__ . '/bootstrap.php';

$in = json_in();
$username = strtolower(trim((string)($in['username'] ?? '')));
$password = (string)($in['password'] ?? '');
if (!preg_match('/^[a-z0-9_]{2,24}$/', $username) || str_starts_with($username, 'bot_')) {
    json_out(['error' => 'BAD_USERNAME', 'detail' => '2-24 chars: a-z 0-9 _'], 422);
}
if (strlen($password) < 6) {
    json_out(['error' => 'BAD_PASSWORD', 'detail' => 'password must be at least 6 characters'], 422);
}
$display = trim((string)($in['displayName'] ?? '')) ?: $username;

$pdo = db();
$st = $pdo->prepare('SELECT id, pass_hash FROM prg_shim_users WHERE username = ?');
$st->execute([$username]);
$row = $st->fetch();
if ($row) {
    $uid = (int)$row['id'];
    if ($row['pass_hash'] === '') {
        // Claim flow for accounts created before passwords existed.
        $pdo->prepare('UPDATE prg_shim_users SET pass_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
    } elseif (!password_verify($password, $row['pass_hash'])) {
        usleep(400000); // blunt brute-force damper
        json_out(['error' => 'WRONG_PASSWORD'], 403);
    }
} else {
    $pdo->prepare('INSERT INTO prg_shim_users (username, display_name, pass_hash, created_at) VALUES (?,?,?,?)')
        ->execute([$username, mb_substr($display, 0, 40), password_hash($password, PASSWORD_DEFAULT), now()]);
    $uid = (int)$pdo->lastInsertId();
}
session_regenerate_id(true);
$_SESSION['prg_uid'] = $uid;
json_out(['user' => current_user()]);
