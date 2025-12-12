# QuickBooks ↔ Planning Center Sync

Bridge Planning Center Giving data (Stripe payouts and committed batches) into QuickBooks Online. Includes a small PHP dashboard to connect credentials, preview data, run syncs, and review logs.

## What it does
- Connects to QuickBooks Online via OAuth and stores refresh/access tokens.
- Pulls Stripe-based online donations from PCO and books them as QBO Deposits (income + fee lines).
- Syncs committed PCO Giving batches (cash/check) to QBO Deposits.
- Manages fund → class/location mappings, notification email, and recent sync logs.

## Requirements
- PHP 8.1+ with PDO MySQL, cURL, and OpenSSL extensions.
- MySQL 8 (or compatible) database.
- An Intuit Developer account with a QuickBooks app (OAuth 2.0).
- A Planning Center personal access token (client id + secret).

## Setup
1) **Clone and install dependencies**
   - This app is plain PHP; no extra composer/npm deps are required.

2) **Create and configure the database**
   - Create a MySQL schema and user with read/write privileges.
   - Run `sql/schema.sql` against your database:
     ```sh
     mysql -u YOURUSER -p YOURDB < sql/schema.sql
     ```

3) **Configure environment**
   - Copy `config/config.php` (or edit directly) and set:
     - `db`: host, name, user, pass.
     - `qbo`: `client_id`, `client_secret`, `redirect_uri`, and account names (deposit, income, fee). Redirect URI should point to `https://your-host/public/oauth-callback.php`.
     - `pco`: `app_id` and `secret` (your PCO personal access token client id/secret).
     - `mail.from` (optional): default “from” address for notification emails.

4) **Set up Intuit (QuickBooks) OAuth**
   - In <https://developer.intuit.com>, create an app (QuickBooks Online).
   - Add an OAuth redirect URI pointing to your deployment of `public/oauth-callback.php`.
   - Grab the client ID/secret and place them in `config/config.php`.
   - In production, use the production keys and redirect URIs; in dev/sandbox, use the sandbox keys.

5) **Set up Planning Center access**
   - Create a personal access token in Planning Center (Developer Tools).
   - Use the client id as `pco.app_id` and the client secret as `pco.secret` in `config/config.php`.

6) **Create an admin user**
   - Visit `public/create_admin.php` in your browser, create a user, then delete the file for safety.

7) **Run the app**
   - Point your web server (or `php -S localhost:8000 -t public`) to the `public` directory.
   - Login, connect QuickBooks, and verify PCO configuration.

## Usage highlights
- **Connect QuickBooks**: `oauth-start.php` handles the OAuth flow and stores tokens.
- **Preview online donations**: `run-sync-preview.php` shows Stripe payouts windowed by `completed_at`.
- **Run online sync**: `run-sync.php` pushes Stripe payouts to QBO deposits with class/location mapping.
- **Batch preview**: `run-batch-preview.php` shows committed batches (cash/check) and fund totals.
- **Run batch sync**: `run-batch-sync.php` books committed batches into QBO deposits.
- **Fund mappings**: `fund-mapping.php` maps PCO funds to QBO Class/Location.
- **Settings**: `settings.php` manages account names and notification email.
- **Logs**: `logs.php` lists recent sync runs and supports cleanup.

## License

This project is source-available and **non-commercial**.

- Churches, ministries, and non-profit organizations may use and modify it
  for their own internal, non-commercial use, under the terms of the
  **Non-Commercial Church Use License** in the `LICENSE` file.
- You may not sell this software or offer it as a paid hosted service
  without my written permission.

If you are interested in commercial use, please contact me via email at tdsheppard77@gmail.com
