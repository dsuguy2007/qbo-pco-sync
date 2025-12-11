<?php
// src/QboClient.php

declare(strict_types=1);

class QboClient
{
    private PDO $pdo;
    private array $config;
    private string $baseUrl;
    private string $realmId;
    private string $accessToken;
    private ?DateTimeImmutable $expiresAt = null;

    private array $accountCache = [];
    private array $classCache   = [];
    private array $deptCache    = [];

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo    = $pdo;
        $this->config = $config;
        $this->baseUrl = rtrim($config['qbo']['base_url'] ?? 'https://sandbox-quickbooks.api.intuit.com', '/');

        $row = $this->loadLatestTokenRow();
        if (!$row) {
            throw new RuntimeException('No QuickBooks tokens found. Please connect QuickBooks first.');
        }

        $this->realmId     = (string)$row['realm_id'];
        $this->accessToken = (string)$row['access_token'];

        if (!empty($row['expires_at'])) {
            try {
                $this->expiresAt = new DateTimeImmutable($row['expires_at']);
            } catch (Throwable $e) {
                $this->expiresAt = null;
            }
        }
    }

    private function loadLatestTokenRow(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM qbo_tokens ORDER BY id DESC LIMIT 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function ensureAccessToken(): void
    {
        $now = new DateTimeImmutable('now');
        if ($this->expiresAt && $this->expiresAt > $now->add(new DateInterval('PT60S'))) {
            // Token is still valid for at least 60 seconds
            return;
        }

        $this->refreshAccessToken();
    }

    private function refreshAccessToken(): void
    {
        $row = $this->loadLatestTokenRow();
        if (!$row) {
            throw new RuntimeException('Cannot refresh QuickBooks token: no existing token row.');
        }

        $refreshToken = $row['refresh_token'] ?? null;
        if (!$refreshToken) {
            throw new RuntimeException('Cannot refresh QuickBooks token: missing refresh_token.');
        }

        $clientId     = $this->config['qbo']['client_id']     ?? null;
        $clientSecret = $this->config['qbo']['client_secret'] ?? null;

        if (!$clientId || !$clientSecret) {
            throw new RuntimeException('QBO client_id or client_secret not configured.');
        }

        $tokenUrl = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

        $postFields = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
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
            throw new RuntimeException('Error calling Intuit token endpoint (refresh): ' . $err);
        }

        $decoded = json_decode($body, true);
        if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Could not parse refresh token response: ' . json_last_error_msg());
        }

        if ($status >= 400) {
            throw new RuntimeException('Intuit token endpoint (refresh) returned HTTP ' . $status . ': ' . $body);
        }

        $newAccessToken  = $decoded['access_token']  ?? null;
        $newRefreshToken = $decoded['refresh_token'] ?? $refreshToken; // sometimes they rotate
        $expiresIn       = (int)($decoded['expires_in'] ?? 3600);
        $tokenType       = $decoded['token_type']    ?? ($row['token_type'] ?? null);

        if (!$newAccessToken) {
            throw new RuntimeException('Missing access_token in refresh response.');
        }

        $now       = new DateTimeImmutable('now');
        $expiresAt = $now->add(new DateInterval('PT' . $expiresIn . 'S'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('
            UPDATE qbo_tokens
               SET access_token = :access_token,
                   refresh_token = :refresh_token,
                   token_type = :token_type,
                   expires_at = :expires_at,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
        ');

        $stmt->execute([
            ':access_token'  => $newAccessToken,
            ':refresh_token' => $newRefreshToken,
            ':token_type'    => $tokenType,
            ':expires_at'    => $expiresAt,
            ':id'            => $row['id'],
        ]);

        $this->accessToken = $newAccessToken;
        try {
            $this->expiresAt = new DateTimeImmutable($expiresAt);
        } catch (Throwable $e) {
            $this->expiresAt = null;
        }
    }

    private function apiRequest(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $this->ensureAccessToken();

        $url = $this->baseUrl . '/v3/company/' . rawurlencode($this->realmId) . $path;

        if (!empty($query)) {
            $queryString = http_build_query($query);
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ];

        if ($body !== null) {
            $json = json_encode($body);
            if ($json === false) {
                throw new RuntimeException('Failed to encode JSON body for QBO request.');
            }
            $opts[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $status       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err          = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('QBO cURL error: ' . $err);
        }

        // Handle 401 by refreshing token once and retrying
        if ($status === 401) {
            $this->refreshAccessToken();
            return $this->apiRequest($method, $path, $query, $body);
        }

        $decoded = json_decode($responseBody, true);
        if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('QBO JSON decode error: ' . json_last_error_msg() . ' | Raw: ' . substr($responseBody, 0, 500));
        }

        if ($status >= 400) {
            throw new RuntimeException('QBO API error HTTP ' . $status . ': ' . $responseBody);
        }

        return $decoded;
    }

    private function query(string $sql): array
    {
        $path = '/query';
        $query = [
            'minorversion' => 65,
            'query'        => $sql,
        ];

        return $this->apiRequest('GET', $path, $query, null);
    }

    public function getAccountByName(string $name, bool $useFullyQualified = false): ?array
    {
        $cacheKey = ($useFullyQualified ? 'fq:' : 'n:') . $name;
        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $nameEscaped = str_replace("'", "''", $name);
        if ($useFullyQualified) {
            $sql = "select * from Account where FullyQualifiedName = '{$nameEscaped}'";
        } else {
            $sql = "select * from Account where Name = '{$nameEscaped}'";
        }

        $resp = $this->query($sql);
        $qr = $resp['QueryResponse'] ?? [];
        $accounts = $qr['Account'] ?? [];
        if (!$accounts) {
            $this->accountCache[$cacheKey] = null;
            return null;
        }
        $account = $accounts[0];
        $this->accountCache[$cacheKey] = $account;
        return $account;
    }

    public function getClassByName(string $name): ?array
    {
        if (isset($this->classCache[$name])) {
            return $this->classCache[$name];
        }
        $nameEscaped = str_replace("'", "''", $name);
        $sql = "select * from Class where Name = '{$nameEscaped}'";
        $resp = $this->query($sql);
        $qr   = $resp['QueryResponse'] ?? [];
        $items = $qr['Class'] ?? [];
        if (!$items) {
            $this->classCache[$name] = null;
            return null;
        }
        $class = $items[0];
        $this->classCache[$name] = $class;
        return $class;
    }

    public function getDepartmentByName(string $name): ?array
    {
        if (isset($this->deptCache[$name])) {
            return $this->deptCache[$name];
        }
        $nameEscaped = str_replace("'", "''", $name);
        $sql = "select * from Department where Name = '{$nameEscaped}'";
        $resp = $this->query($sql);
        $qr   = $resp['QueryResponse'] ?? [];
        $items = $qr['Department'] ?? [];
        if (!$items) {
            $this->deptCache[$name] = null;
            return null;
        }
        $dept = $items[0];
        $this->deptCache[$name] = $dept;
        return $dept;
    }

    public function createDeposit(array $deposit): array
    {
        $path = '/deposit';
        $query = ['minorversion' => 65];
        return $this->apiRequest('POST', $path, $query, $deposit);
    }
}
