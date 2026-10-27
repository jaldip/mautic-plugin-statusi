# Statusi plugin for Mautic

Connects Mautic to a Statusi account: Statusi reports inside Mautic, and a daily read-only sync of
Mautic contacts and email activity to Statusi.

## Requirements

- Mautic 7 (installed with Composer) and PHP 8.2 or newer.
- For the daily sync, Statusi's servers must reach this Mautic over **https**.
- `config/local.php` writable, so connecting can turn on Mautic's API (otherwise turn it on yourself
  under **Settings > Configuration > API Settings**).

## Install

From the Mautic root directory:

```bash
composer require statusi/mautic-plugin-statusi
php bin/console mautic:plugins:reload
php bin/console cache:clear
```

Composer puts the plugin in `plugins/MauticStatusiBundle`. In production (`APP_ENV=prod`) also rebuild
Mautic's assets, so the report pages get the plugin's JavaScript and CSS:

```bash
php bin/console mautic:assets:generate
```

Then open **Settings > Plugins > Statusi** and click **Connect to Statusi**.

**Upgrade:** `composer update statusi/mautic-plugin-statusi`, then the same three `bin/console` commands.

**Without Composer:** download a release from GitHub, copy its contents to `plugins/MauticStatusiBundle`,
then run the same commands (or use **Settings > Plugins > Install/Upgrade Plugins**).

**Uninstall:** disconnect first (this revokes Statusi's access and deletes its API client), then
`composer remove statusi/mautic-plugin-statusi`, `php bin/console mautic:plugins:reload` and
`php bin/console cache:clear`.

## Statusi environment (development only)

The plugin talks to `https://app.statusi.io` and `https://api.statusi.io`. To use another Statusi
environment, set environment variables (e.g. in `.env.local`; they take precedence) or `config/local.php`:

```dotenv
MAUTIC_STATUSI_APP_URL=https://app.staging.example.com
MAUTIC_STATUSI_API_URL=https://api.staging.example.com
```

```php
'statusi_app_url' => 'https://app.statusi.io', // Statusi web app (login popup)
'statusi_api_url' => 'https://api.statusi.io', // Statusi API
```

Run `php bin/console cache:clear` after changing them.

## Connect

**Settings > Plugins > Statusi > Connect to Statusi** opens the Statusi sign-in popup (email and
password only). After choosing an account the popup closes, the plugin shows
**Connected to Statusi**, and a **Statusi** section appears in the sidebar after the modal closes.
**Disconnect** revokes the token in Statusi and removes it from Mautic.

## Reports

The **Statusi reports** sidebar section lists **All reports** plus one item per report of the connected
account (from `/report/get-reports`). The list is cached for 5 minutes (a failed fetch for 1 minute,
with a 5 second timeout), so Mautic pages do not call Statusi on every request. Every Statusi page
shows the Statusi logo in its header.

## Integrations

The **Statusi integrations** sidebar item (separate from **Statusi reports**) lists the connected account's integrations like Statusi's own Integrations
page (platform, task, status) and lets every Mautic user who can see the Statusi reports:

- **Activate / Deactivate** an integration (Statusi's DAGs only pull active ones).
- **Create integration** for any Statusi platform, and **Edit** existing ones, all inside Mautic:
  - **Ongage**: API key and task. Contact Manager integrations get the field mapping page (default mapping from
    the default Ongage list, plus list specific mappings).
  - **Click Go** and **ChatGPT Ads**: API key forms. ChatGPT Ads can manage several ad accounts (status,
    display name, remove, replace key).
  - **Google Ads / AdSense / Analytics, Meta Ads, Mailchimp**: "Sign in with Google / Facebook / Mailchimp"
    starts the provider sign-in from Mautic. The provider returns to Statusi, which stores the tokens and sends
    the browser back to Mautic (`/s/statusi/integrations/oauth/<platform>`), where you pick the accounts or
    properties (Mailchimp: the tracking days).
- The **Mautic** integration created by Connect is shown read-only; it is managed with Connect / Disconnect.

## Security

- Authorization code + PKCE (S256) + `state`; the code is exchanged server-to-server, so the
  API token never passes through the browser.
- Every call sends `X-Statusi-Client: mautic`. Statusi only accepts the token with that flag,
  and only on the report endpoints (`/report/dynamic_query`, `/report/get-reports`) and the integration
  endpoints Statusi > Integrations uses (`/integration/get_lists`, `/integration/platforms`,
  `/integration/integration-status`, Click Go and ChatGPT Ads). Each request names the account in the
  `Account-Id` header and Statusi refuses any other account than the token's.
- The token is locked to one Statusi account. It stops working when it is revoked, or when the
  user who connected it leaves the account.
- Mautic stores the token encrypted in the integration's API keys. Statusi stores only a
  SHA-256 hash of it.
- http is accepted only for localhost. Every other Mautic URL must use https.
- Statusi's API client for the daily pulls can only read contacts and emails (writes get 403). Its secret travels
  only in the server-to-server token request; Statusi stores it encrypted. Disconnect deletes the client.

## Email activity report API

`GET /api/custom/email-activity-report?dateFrom=…&dateTo=…` returns every email activity in a date range,
grouped email → contacts → activities (sends, opens, clicks, failures, unsubscribes, bounces, campaign steps),
using Mautic's API authentication. Parameters, response, field sources, performance and limitations:
[Docs/EMAIL_ACTIVITY_REPORT.md](Docs/EMAIL_ACTIVITY_REPORT.md).

## Contact attribute change API

`GET /api/custom/contact-attributes?dateFrom=…&dateTo=…` returns the contacts created or updated in a date range
with their current attributes, and (with `includeHistory=true`) the field-level history Mautic recorded in its audit log.
Definitions, parameters, response, limits and measurements:
[Docs/CONTACT_ATTRIBUTES_REPORT.md](Docs/CONTACT_ATTRIBUTES_REPORT.md).

## Daily data sync to Statusi

Connecting also gives Statusi read-only access to Mautic's API (a dedicated OAuth2 client-credentials client
with a view-only role), and Statusi's daily DAGs pull contacts and email activity through two flat,
cursor-paged exports: `GET /api/custom/email-activity-export` and `GET /api/custom/contact-attribute-export`.
Field mapping, the Statusi side and the rollout checklist: [Docs/STATUSI_DATA_SYNC.md](Docs/STATUSI_DATA_SYNC.md).

## Reading reports

```php
$data = $statusiClient->dynamicQuery([
    'rows'   => [...],
    'values' => [...],
    // columns, filters, pagination, base, ... as in Statusi's dynamic_query
]);
```

`account_id` is filled in automatically from the connection.
