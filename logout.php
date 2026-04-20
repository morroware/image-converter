<?php
require_once __DIR__ . '/bootstrap.php';

\Castle\Auth::logout();

header('Location: login.php');
exit;
