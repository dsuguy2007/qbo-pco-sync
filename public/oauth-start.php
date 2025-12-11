<?php
// /qbo-pco-sync/public/oauth-start.php

session_start();

$config = require __DIR__ . '/../config/config.php';

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/QboOAuth.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();
$db  = Db::getInstance($config['db'])->getConnection();
$qbo = new QboOAuth($config['qbo'], $db);

// Generate a CSRF state token and store in session
$state = bin2hex(random_bytes(16));
$_SESSION['qbo_oauth_state'] = $state;

// Build the Intuit authorization URL and redirect
$authUrl = $qbo->getAuthorizationUrl($state);

header('Location: ' . $authUrl);
exit;
