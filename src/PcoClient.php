<?php
// /qbo-pco-sync/src/PcoClient.php

declare(strict_types=1);

class PcoClient
{
    private string $baseUrl;
    private string $appId;
    private string $secret;

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
    }

    /**
     * Low-level HTTP request wrapper.
     */
    private function request(string $method, string $path, array $query = []): array
    {
        // If $path is a full URL (from "links.next"), don't double-prefix
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $url = $path;
        } else {
            $url = $this->baseUrl . $path;
        }

        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_USERPWD        => $this->appId . ':' . $this->secret,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('PCO cURL error: ' . $err);
        }

        $decoded = json_decode($body, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'PCO JSON decode error: ' . json_last_error_msg() .
                ' | Raw body: ' . substr($body, 0, 500)
            );
        }

        if ($status >= 400) {
            $msg = "PCO API error HTTP {$status}";
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $msg .= ' | ' . json_encode($decoded['errors']);
            }
            throw new RuntimeException($msg);
        }

        return $decoded;
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
        $path = '/giving/v2/donations';
        return $this->request('GET', $path, $query);
    }
}
