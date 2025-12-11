<?php
declare(strict_types=1);



$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';

function redirect_with_error(string $msg): void {
    $safe = urlencode($msg);
    header("Location: index.php?qbo_error={$safe}");
    exit;
}

try {
    if (empty($_GET['code']) || empty($_GET['realmId'])) {
        redirect_with_error('Missing code or realmId from QuickBooks callback.');
    }

    $authCode = $_GET['code'];
    $realmId  = $_GET['realmId'];

    if (empty($config['qbo']['client_id']) || empty($config['qbo']['client_secret']) || empty($config['qbo']['redirect_uri'])) {
        redirect_with_error('QBO client_id / client_secret / redirect_uri are not configured.');
    }

    $clientId     = $config['qbo']['client_id'];
    $clientSecret = $config['qbo']['client_secret'];
    $redirectUri  = $config['qbo']['redirect_uri'];

    // ---------------------------------------------------------
    // Exchange auth code for tokens with Intuit
    // ---------------------------------------------------------
    $tokenUrl = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    $postFields = http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $authCode,
        'redirect_uri' => $redirectUri,
    ]);

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ],
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        redirect_with_error('Error calling Intuit token endpoint: ' . $err);
    }

    $decoded = json_decode($body, true);
    if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
        redirect_with_error('Could not parse token response from Intuit: ' . json_last_error_msg());
    }

    if ($status >= 400) {
        redirect_with_error('Intuit token endpoint returned HTTP ' . $status . ' response: ' . $body);
    }

    $accessToken  = $decoded['access_token']  ?? null;
    $refreshToken = $decoded['refresh_token'] ?? null;
    $tokenType    = $decoded['token_type']    ?? null;
    $expiresIn    = (int)($decoded['expires_in'] ?? 3600);

    if (!$accessToken || !$refreshToken) {
        redirect_with_error('Missing access_token or refresh_token in Intuit response.');
    }

    $now       = new DateTimeImmutable('now');
    $expiresAt = $now->add(new DateInterval('PT' . $expiresIn . 'S'))->format('Y-m-d H:i:s');

    // ---------------------------------------------------------
    // Store tokens in qbo_tokens table ONLY
    // ---------------------------------------------------------
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();

    $stmt = $pdo->prepare("
        INSERT INTO qbo_tokens (realm_id, access_token, refresh_token, token_type, expires_at)
        VALUES (:realm_id, :access_token, :refresh_token, :token_type, :expires_at)
        ON DUPLICATE KEY UPDATE
          access_token  = VALUES(access_token),
          refresh_token = VALUES(refresh_token),
          token_type    = VALUES(token_type),
          expires_at    = VALUES(expires_at),
          updated_at    = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':realm_id'      => $realmId,
        ':access_token'  => $accessToken,
        ':refresh_token' => $refreshToken,
        ':token_type'    => $tokenType,
        ':expires_at'    => $expiresAt,
    ]);

    // Done: go back to dashboard with success flag
    header('Location: index.php?qbo_connected=1');
    exit;

} catch (Throwable $e) {
    redirect_with_error('Unexpected error in oauth-callback: ' . $e->getMessage());
}
