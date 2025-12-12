<?php
declare(strict_types=1);

/**
 * Example config for QBO–PCO Sync.
 *
 * Copy this file to config.php and fill in your real values:
 *
 * cp config.example.php config.php
 */

return [

    'db' => [
        'host' => 'localhost',
        'name' => 'qbo_pco_sync',    // database name
        'user' => 'qbo_pco_sync',    // database user
        'pass' => 'CHANGE_ME',       // database password
        'charset' => 'utf8mb4',
    ],

    // Shared secret(s) for webhook verification (PCO authenticity_secret)
    'webhook_secrets' => [
        'giving.v2.events.batch.created'    => 'CHANGE_ME_BATCH_CREATED',
        'giving.v2.events.batch.updated'    => 'CHANGE_ME_BATCH_UPDATED',
        'giving.v2.events.donation.created' => 'CHANGE_ME_DONATION_CREATED',
        'giving.v2.events.donation.updated' => 'CHANGE_ME_DONATION_UPDATED',
    ],

    'qbo' => [
        // Intuit app credentials (do NOT commit real values to GitHub)
        'client_id'     => 'YOUR_QBO_CLIENT_ID',
        'client_secret' => 'YOUR_QBO_CLIENT_SECRET',

        // 'sandbox' or 'production'
        'environment'   => 'production',

        // QuickBooks company (realm) ID
        'realm_id'      => 'YOUR_REALM_ID',

        // OAuth redirect URL for Intuit (must match the one configured in the Intuit Developer portal)
        // e.g. https://example.org/qbo-pco-sync/public/oauth-callback.php
        'redirect_uri'  => 'https://yourdomain.example/qbo-pco-sync/public/oauth-callback.php',
    ],

    'pco' => [
        /**
         * Planning Center Online credentials.
         *
         * For a Personal Access Token:
         *  - app_id  = the Application ID
         *  - secret  = the Personal Access Token value
         */
        'app_id' => 'YOUR_PCO_APP_ID',
        'secret' => 'YOUR_PCO_PERSONAL_ACCESS_TOKEN',
    ],

    'mail' => [
        // Optional default From: address for notification emails
        'from' => 'notifications@example.org',
    ],

    'app' => [
        // Base URL where the /public folder is served
        // e.g. https://example.org/qbo-pco-sync/public
        'base_url' => 'https://yourdomain.example/qbo-pco-sync/public',
    ],
];
