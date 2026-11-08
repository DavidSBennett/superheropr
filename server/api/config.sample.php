<?php
// Copy to config.local.php and fill in. config.local.php is gitignored.
// On GreenGeeks, use the cPanel MySQL database + user. For local dev a
// sqlite DSN works out of the box: 'sqlite:' . __DIR__ . '/../dev.sqlite'
return [
    'dsn' => 'mysql:host=localhost;dbname=CPANELUSER_superheropr;charset=utf8mb4',
    'user' => 'CPANELUSER_prg',
    'pass' => 'CHANGE_ME',
    // Usernames (auth shim) allowed to use the card importer.
    'admins' => ['david'],
];
