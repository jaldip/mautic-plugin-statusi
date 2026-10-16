# Mautic → Statusi daily data sync

How a connected Mautic's contacts and email activity reach Statusi, so Statusi can report on Mautic like its other email platforms (Ongage, Mailchimp, …). Statusi platform id: **12**.

## 1. Connect sets up read access

When an admin clicks **Settings → Plugins → Statusi → Connect to Statusi**, the callback (`ConnectController::callbackAction`):

1. Turns on Mautic's API (`api_enabled`), if needed. Basic auth is not changed.
2. Creates a dedicated role **Statusi API (read only)** on first use: `lead:leads` view other + `email:emails` view other. Nothing else.
3. Creates an OAuth2 **client-credentials** API client named `Statusi` with that role (`Service/StatusiApiAccess`).
4. Sends `mautic_api = {base_url, client_id, client_secret}` to Statusi inside the existing server-to-server `POST /mautic/token`. The secret never goes through the browser; Statusi stores it Fernet-encrypted.
5. Statusi creates (or reactivates) one `account_integrations` row (`platform_id = 12`) and two `integration_config` rows (`contact_manager` 0 = activity, 1 = attributes), keyed by the Mautic site URL.

If the token exchange fails, the new API client is deleted again. **Reconnect** replaces the client; **Disconnect** deletes it and Statusi sets the integration rows to `status = 0`, which stops the pulls.

A client-credentials token acts as a pseudo user with the client's role, so Statusi can only read: writes get `403`.

## 2. Export endpoints

Both need the API, full contact access (and, for activity, full email access): "own only" permissions get `403`, because a partial export would silently drop data in Statusi. UTC, `dateFrom`/`dateTo` (max 31 days), `limit` 1–5000 (default 1000), keyset paging with `cursor` = `pagination.nextCursor` of the previous page.

### `GET /api/custom/email-activity-export`

One row per activity, ordered by time; rows added while paging never shift pages.

| Field | Meaning |
|---|---|
| `id` | stable row id (`send-12`, `open-5`, `click-9`, `dnc-3`) |
| `activity_type` | `send`, `open`, `click`, `unsubscribe`, `complaint`, `hard_bounce`, `soft_bounce` |
| `activity_date_time` | UTC `YYYY-MM-DD HH:MM:SS` |
| `email`, `contact_id` | the contact |
| `ip`, `country_code` (ISO 3166-1 alpha-2), `os`, `browser` | from the open / click |
| `days_passed` | whole days since the send (null for sends) |
| `campaign_id`, `campaign_name`, `mailing_type`, `mailing_schedule` | the Mautic email (`template` or `list`), its publish-up time |
| `email_message_id`, `email_message_subject` | the same email |
| `data_value` | the clicked URL for clicks, otherwise the subject |
| `link_id` | tracked link (`page_redirects.id`) |
| `segment_id`, `segment_name` | segments a segment (`list`) email is sent to |

Mapping: sent → `send`, failed send → `soft_bounce`, read → `open`, click → `click`, do-not-contact *unsubscribed* → `unsubscribe` (or `complaint` when the comment mentions spam / complaint / abuse / feedback loop), *bounced* → `hard_bounce`. Manual do-not-contact entries are not exported.

### `GET /api/custom/contact-attribute-export`

One row per contact created, updated (audit log or `date_modified`) or given an email do-not-contact entry in the range, ordered by contact id.

| Field | Meaning |
|---|---|
| `contact_id`, `email`, `created_date`, `last_modified_date` | `last_modified_date` = latest change inside the range |
| `platform_status` | `subscribed`, `unsubscribed`, `cleaned` (bounced) — same values as Mailchimp |
| `unsubscribe_date`, `complaint_date`, `bounce_date` | current do-not-contact entries |
| `ip`, `country`, `language` | last IP, `country`, `preferred_locale` |
| `fields` | every published contact field (`alias => value`) |

## 3. Statusi DAGs

`statusi_backend/Integration`:

| File | What it does |
|---|---|
| `common/mautic_api.py` | integrations from `integration_config`, client-credentials token, paging with retries |
| `dags/mautic_contact_attribute.py` | contacts → `contacts`, `contact_list_attribute`, `custom_field_*` (via `fields_mapping`) |
| `dags/mautic_contact_activity.py` | activity → `contact_activity` + `campaign`, `message`, `link`, `segment`, `isps`, `os`, `browser`, first/last send/open/click |

Chain: `mailchimp_contact_activity` → `mautic_contact_attribute` → `mautic_contact_activity` → `google_ads_dag`. Each Mautic instance is one Statusi list (`list.list_id_str` = site host/path). Statusi campaign = message = the Mautic email. Reruns are safe (day's rows are soft-deleted and replaced, like Ongage).

Report DAGs with a Mautic step: `delivery_metrix_dag`, `delivery_metrix_clickhouse_dag` (`process_mautic`), `list_metrix_dag` (reuses the platform-four calculation), `global_retention_report_dag` (`platform_id IN (1, 2, 4, 12)`), `migrate_dimentions_to_clickhouse` (Mautic lists use `list_id_str`).

Custom fields: map them in the integration's `fields_mapping` (JSON string `{"field_mapping": {...}}`), Ongage format: `"custom_field_<table>.<column>": "<mautic field alias>"`; `"contact_list_attribute.<column>": "<alias>"` overrides `country`, `ip`, `language`, `os` or `platform_contact_id`.

## 4. Rollout checklist

1. Insert the Statusi platform row (adjust `image_url` / `path` / `navigation_path` / `platform_type` to match your other platforms):
   ```sql
   INSERT INTO platform (id, name, platform_type, image_url, path, navigation_path, is_active, is_deleted, created_at)
   VALUES (12, 'Mautic', 'esp', NULL, NULL, NULL, 1, 0, NOW());
   ```
2. Deploy the Statusi API changes and **restart the backend** (the old `/mautic/token` rejects `mautic_api`).
3. Deploy the DAG files (incl. `common/mautic_api.py`) to Airflow.
4. In Mautic production, run `mautic:assets:generate` after deploying the plugin (prod bundles the plugin JS/CSS).
5. Reconnect each Mautic in **Settings → Plugins → Statusi** so Statusi receives the API client.
6. Mautic must be reachable from Airflow at its site URL (`https`; plain `http` is only accepted for local hosts).
