<?php
// /qbo-pco-sync/src/PcoClient.php

declare(strict_types=1);

class PcoClient
{
    private string $baseUrl;
    private string $appId;
    private string $secret;
    private string $retryLog;

    public function __construct(array $config)
    {
        if (empty($config['pco']['app_id']) || empty($config['pco']['secret'])) {
            throw new RuntimeException(
                'PCO credentials are not configured. Please set pco.app_id and pco.secret in config/config.php'
            );
        }

        $this->baseUrl = rtrim($config['pco']['base_url'] ?? 'https://api.planningcenteronline.com', '/');
        $this->appId   = $config['pco']['app_id'];
        $this->secret  = $config['pco']['secret'];
        $this->retryLog = dirname(__DIR__) . '/logs/api-retries.log';
        $this->ensureLogDir($this->retryLog);
    }

    /**
     * Low-level HTTP request wrapper.
     */
    private function request(string $method, string $path, array $query = [], array $headers = []): array
    {
        $attempts   = 0;
        $maxTries   = 3;
        $delay      = 1; // seconds
        $lastErrMsg = null;
        $expectedRegVersion = '2024-10-01';

        while ($attempts < $maxTries) {
            $attempts++;

            // If $path is a full URL (from "links.next"), don't double-prefix
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                $url = $path;
            } else {
                $url = $this->baseUrl . $path;
            }

            if (!empty($query)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
            }

            $respHeaders = [];
            $ch = curl_init($url);

            $httpHeaders = ['Accept: application/json'];
            // Registrations endpoints require an older version header; default is now missing payments.
            if (str_contains($path, '/registrations/')) {
                $httpHeaders[] = 'X-PCO-API-Version: ' . $expectedRegVersion;
            }
            foreach ($headers as $h) {
                $httpHeaders[] = $h;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_USERPWD        => $this->appId . ':' . $this->secret,
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_HTTPHEADER     => $httpHeaders,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$respHeaders) {
                    $len = strlen($header);
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $name = strtolower(trim($parts[0]));
                        $value = trim($parts[1]);
                        $respHeaders[$name] = $value;
                    }
                    return $len;
                },
            ]);

            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                $lastErrMsg = 'PCO cURL error: ' . $err;
                // retry if we can
            } else {
                $decoded = json_decode($body, true);

                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $lastErrMsg = 'PCO JSON decode error: ' . json_last_error_msg() . ' | Raw body: ' . substr($body, 0, 500);
                } elseif ($status >= 400) {
                    $msg = "PCO API error HTTP {$status}";
                    if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                        $msg .= ' | ' . json_encode($decoded['errors']);
                    }
                    $lastErrMsg = $msg;
                } else {
                    // Version drift warning for Registrations
                    if (str_contains($path, '/registrations/')) {
                        $seenVer = $respHeaders['x-pco-api-processed-as-version'] ?? null;
                        if ($seenVer && $seenVer !== $expectedRegVersion) {
                            error_log("[pco] Registrations API version drift: expected {$expectedRegVersion}, got {$seenVer}");
                        }
                    }
                    return $decoded;
                }
            }

            // Retry on transient statuses/cURL failures
            if ($status === 429 || ($status >= 500 && $status < 600) || $body === false) {
                if ($attempts < $maxTries) {
                    sleep($delay);
                    $delay *= 2;
                    $this->logRetry('pco', $status, $attempts, $url);
                    continue;
                }
            }

            break;
        }

        throw new RuntimeException($lastErrMsg ?? 'Unknown PCO error');
    }

    private function logRetry(string $service, int $status, int $attempt, string $url): void
    {
        $line = sprintf(
            "[%s] service=%s status=%d attempt=%d url=%s\n",
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            $service,
            $status,
            $attempt,
            $url
        );
        @file_put_contents($this->retryLog, $line, FILE_APPEND | LOCK_EX);
    }

    private function ensureLogDir(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * List all Giving funds (used by fund-mapping UI).
     */
    public function listFunds(int $perPage = 100): array
    {
        $funds  = [];
        $path   = '/giving/v2/funds';
        $params = ['per_page' => $perPage];

        while ($path !== null) {
            $resp = $this->request('GET', $path, $params);

            if (isset($resp['data']) && is_array($resp['data'])) {
                $funds = array_merge($funds, $resp['data']);
            }

            $links = $resp['links'] ?? [];
            if (!empty($links['next'])) {
                $path   = $links['next']; // full URL, request() can handle it
                $params = [];             // "next" already includes query string
            } else {
                $path = null;
            }
        }

        return $funds;
    }

    /**
     * Fetch donations with optional query parameters (for sync and debugging).
     */
    public function listDonations(array $query = []): array
    {
        $donations = [];
        $included  = [];
        $path      = '/giving/v2/donations';
        $params    = $query;

        while ($path !== null) {
            $resp = $this->request('GET', $path, $params);

            if (isset($resp['data']) && is_array($resp['data'])) {
                $donations = array_merge($donations, $resp['data']);
            }
            if (isset($resp['included']) && is_array($resp['included'])) {
                $included = array_merge($included, $resp['included']);
            }

            $links = $resp['links'] ?? [];
            if (!empty($links['next'])) {
                $path   = $links['next']; // full URL handled by request()
                $params = [];             // next already carries query
            } else {
                $path = null;
            }
        }

        return ['data' => $donations, 'included' => $included];
    }

    /**
     * List payments from PCO Registrations API (supports include/pagination).
     *
     * @param array $query Optional query params (e.g., include, per_page)
     */
    public function listRegistrationPayments(array $query = []): array
    {
        $payments = [];
        $included = [];
        $path     = '/registrations/v2/payments';
        $params   = $query;

        while ($path !== null) {
            $resp = $this->request('GET', $path, $params);

            if (isset($resp['data']) && is_array($resp['data'])) {
                $payments = array_merge($payments, $resp['data']);
            }
            if (isset($resp['included']) && is_array($resp['included'])) {
                $included = array_merge($included, $resp['included']);
            }

            $links = $resp['links'] ?? [];
            if (!empty($links['next'])) {
                $path   = $links['next'];
                $params = [];
            } else {
                $path = null;
            }
        }

        return ['data' => $payments, 'included' => $included];
    }

    /**
     * Fetch a single registration by id (Registrations API).
     */
    public function getRegistration(string $id): array
    {
        $resp = $this->request('GET', '/registrations/v2/registrations/' . urlencode($id));
        return $resp['data'] ?? [];
    }
}
