<?php
require_once __DIR__ . '/bootstrap.php';
json_out(['user' => current_user()]);
