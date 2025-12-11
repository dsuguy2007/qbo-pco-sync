<?php
// /qbo-pco-sync/src/QboOAuth.php

class QboOAuth
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $environment;
    private \PDO $pdo;

    private string $authBaseUrl = 'https://appcenter.intuit.com/connect/oauth2';
    private string $tokenUrl    = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public function __construct(array $qboConfig, \PDO $pdo)
    {
        $this->clientId     = $qboConfig['client_id'];
        $this->clientSecret = $qboConfig['client_secret'];
        $this->redirectUri  = $qboConfig['redirect_uri'];
        $this->environment  = $qboConfig['environment'] ?? 'sandbox';
        $this->pdo          = $pdo;
    }

    /**
     * Build the Intuit OAuth authorization URL.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'state'         => $state,
        ];

        return $this->authBaseUrl . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access/refresh tokens and store them.
     */
    public function exchangeCodeForTokens(string $code, string $realmId): void
    {
        $response = $this->sendTokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $this->storeTokens($realmId, $response);
    }

    /**
     * Get a valid access token (refreshing if needed).
     */
    public function getValidAccessToken(string $realmId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT * FROM qbo_tokens WHERE realm_id = :realm_id LIMIT 1");
        $stmt->execute(['realm_id' => $realmId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $expiresAt = new \DateTime($row['expires_at']);
        $now       = new \DateTime();

        // If token is still valid (with a small buffer), just return it
        $buffer = new \DateInterval('PT300S'); // 5 minutes
        $expiresMinusBuffer = (clone $expiresAt)->sub($buffer);

        if ($expiresMinusBuffer > $now) {
            return $row['access_token'];
        }

        // Otherwise refresh
        $response = $this->sendTokenRequest([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
        ]);

        $this->storeTokens($realmId, $response);
        return $response['access_token'] ?? null;
    }

    /**
     * Send POST request to Intuit token endpoint.
     */
    private function sendTokenRequest(array $params): array
    {
        $ch = curl_init($this->tokenUrl);
        $authHeader = base64_encode($this->clientId . ':' . $this->clientSecret);

        $postFields = http_build_query($params);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $authHeader,
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Curl error during token request: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($rawResponse, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = isset($data['error']) ? $data['error'] : 'Unknown error from Intuit';
            throw new \RuntimeException('Intuit token endpoint error (' . $httpCode . '): ' . $msg);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON from Intuit token endpoint.');
        }

        return $data;
    }

    /**
     * Store or update tokens in the qbo_tokens table.
     */
    private function storeTokens(string $realmId, array $tokenResponse): void
    {
        if (!isset($tokenResponse['access_token'], $tokenResponse['refresh_token'], $tokenResponse['expires_in'])) {
            throw new \InvalidArgumentException('Token response missing fields.');
        }

        $accessToken  = $tokenResponse['access_token'];
        $refreshToken = $tokenResponse['refresh_token'];
        $expiresIn    = (int)$tokenResponse['expires_in'];

        // Intuit gives expires_in in seconds; compute absolute expiry
        $expiresAt = (new \DateTime())->add(new \DateInterval('PT' . $expiresIn . 'S'))
                                      ->format('Y-m-d H:i:s');

        // Upsert logic
        $sql = "
            INSERT INTO qbo_tokens (realm_id, access_token, refresh_token, expires_at, created_at, updated_at)
            VALUES (:realm_id, :access_token, :refresh_token, :expires_at, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'realm_id'      => $realmId,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at'    => $expiresAt,
        ]);
    }
}
