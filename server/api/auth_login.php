<?php
// AUTH SHIM - replaced by The Historians login once I have its source.
// POST {username, displayName?} -> {user}
require_once __DIR__ . '/bootstrap.php';

$in = json_in();
$username = strtolower(trim((string)($in['username'] ?? '')));
if (!preg_match('/^[a-z0-9_]{2,24}$/', $username)) {
    json_out(['error' => 'BAD_USERNAME', 'detail' => '2-24 chars: a-z 0-9 _'], 422);
}
$display = trim((string)($in['displayName'] ?? '')) ?: $username;

$pdo = db();
$st = $pdo->prepare('SELECT id FROM prg_shim_users WHERE username = ?');
$st->execute([$username]);
$row = $st->fetch();
if ($row) {
    $uid = (int)$row['id'];
} else {
    $pdo->prepare('INSERT INTO prg_shim_users (username, display_name, created_at) VALUES (?,?,?)')
        ->execute([$username, mb_substr($display, 0, 40), now()]);
    $uid = (int)$pdo->lastInsertId();
}
session_regenerate_id(true);
$_SESSION['prg_uid'] = $uid;
json_out(['user' => current_user()]);
