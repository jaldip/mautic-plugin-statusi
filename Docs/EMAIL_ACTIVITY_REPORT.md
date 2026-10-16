# Email Activity Report API

`GET /api/custom/email-activity-report`, from the Statusi plugin (`MauticStatusiBundle`). Verified on Mautic 7.2.1 with MariaDB 10.11.

It returns everything that happened to emails during a date range, grouped by **email → contacts → activities**. Activities are enriched with campaign, send, tracked-link, IP, location and device details.

---

## 1. Endpoint and parameters

| | |
|---|---|
| URL | `GET https://<mautic>/api/custom/email-activity-report` |
| Auth | Mautic API authentication: OAuth2 or HTTP Basic. The API must be enabled in Settings → Configuration → API Settings |
| Permissions | Contacts: `lead:leads:viewother` gives all contacts, `lead:leads:viewown` only contacts the user owns. Emails: `email:emails:viewother` gives all emails, `email:emails:viewown` only emails the user created. Without a contact permission **and** an email permission the response is `403` |
| Timezone | Everything is UTC |

| Parameter | Required | Default | Rules |
|---|---|---|---|
| `dateFrom` | yes | – | `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS` or ISO 8601 with an offset (`2026-10-09T00:00:00+05:30`). Values without an offset are read as UTC. Converted to UTC |
| `dateTo` | yes | – | Same formats. A date-only value means the end of that day (`23:59:59`). Bounds are **inclusive**. Must be ≥ `dateFrom`; at most 366 days |
| `includeEvents` | no | all | Repeat it (`includeEvents[]=email.read&includeEvents[]=email.click`) or comma-separate it. Values: `email.sent`, `email.failed`, `email.read`, `email.click`, `email.unsubscribe`, `email.bounce`, `email.dnc_manual`, `campaign.event`. Mautic aliases also work: `email.open`, `page.hit`, `lead.donotcontact` |
| `excludeEvents` | no | none | Same values; removed from the include list |
| `contactId` | no | – | Only this contact's activities |
| `emailId` | no | – | Only this email |
| `campaignId` | no | – | Only activities attributable to this campaign (section 6) |
| `search` | no | – | Matches email name or subject (contains) and contact email (starts with). Max 100 characters; `%` and `_` are matched literally |
| `page` | no | 1 | Page of **email groups** |
| `limit` | no | 25 | Email groups per page, 1–100 |
| `order` | no | `DESC` | `ASC` or `DESC`. Applies to email groups, to contacts inside a group and to activities inside a contact |
| `sortBy` | no | `latestActivity` | `latestActivity`, `totalActivities`, `emailId`, `emailName`. The email ID is always the tie-breaker |
| `contactPage` | no | 1 | Page of contacts **inside each email group** |
| `contactLimit` | no | 50 | Contacts per email group, 1–500 |
| `activityLimit` | no | 100 | Activities per contact, 1–1000. Truncation is reported, never silent |
| `includeContacts` | no | `true` | `false` returns emails, campaigns and summaries only (fastest) |
| `includeActivities` | no | `true` | `false` returns contacts without their activity lists |

Invalid input returns `400` with one message per parameter:

```json
{ "errors": [ { "code": 400, "message": "Invalid request parameters: ...",
  "details": { "dateFrom": "dateFrom must be YYYY-MM-DD, YYYY-MM-DD HH:MM:SS or ISO 8601 with an offset." }, "type": null } ] }
```

## 2. Sample request

```bash
curl -u 'api-user:password' -G 'https://mautic.example.com/api/custom/email-activity-report' \
  --data-urlencode 'dateFrom=2026-10-09 00:00:00' \
  --data-urlencode 'dateTo=2026-10-09 23:59:59' \
  --data-urlencode 'page=1' --data-urlencode 'limit=25' --data-urlencode 'order=DESC'
```

## 3. Sample response

Below is real output of the implementation for a small fixture; the full JSON is in [`email-activity-report.sample.json`](email-activity-report.sample.json).

In that fixture, Jane was sent "Welcome email" by the Onboarding campaign on **Oct 8**, then opened it and clicked it on **Oct 9**. For Raj, the campaign step failed on Oct 9 and the address bounced.

```json
{
  "data": [{
    "email": { "id": 3, "exists": true, "name": "Welcome email", "subject": "Welcome to Acme", "description": null,
               "emailType": "template", "language": "", "isPublished": true, "publishUp": null, "publishDown": null,
               "createdAt": "2026-06-01T09:00:00+00:00", "modifiedAt": null, "fromAddress": "hello@acme.test",
               "fromName": "Acme", "lifetimeSentCount": 1200, "lifetimeReadCount": 450 },
    "latestActivityAt": "2026-10-09T06:01:22+00:00",
    "campaigns": [{ "id": 2, "name": "Onboarding", "isPublished": true, "createdAt": "2026-05-20T10:00:00+00:00",
                    "steps": [{ "id": 10, "name": "Send welcome email", "type": "email.send", "stepType": "action", "periodActivities": 3 }] }],
    "activitySummary": { "totalActivities": 5, "totalContacts": 2, "sent": 0, "failed": 1, "opened": 1, "clicked": 1,
                         "unsubscribed": 0, "bounced": 1, "dncManual": 0, "campaignSteps": 1 },
    "contactsPagination": { "page": 1, "limit": 50, "totalContacts": 2, "totalPages": 1, "hasNextPage": false },
    "contacts": [{
      "contact": { "id": 55, "exists": true, "email": "jane@example.com", "firstName": "Jane", "lastName": "Doe",
                   "company": "Example Inc", "country": "India", "region": "Gujarat", "city": "Ahmedabad" },
      "latestActivityAt": "2026-10-09T05:16:03+00:00",
      "activityCount": 2,
      "campaignMemberships": [{ "campaignId": 2, "campaignName": "Onboarding", "isMember": true,
                                "enteredAt": "2026-10-08T21:59:00+00:00", "manuallyAdded": false,
                                "manuallyRemoved": false, "exitedAt": null, "rotation": 1 }],
      "activitiesReturned": 2,
      "activitiesTruncated": false,
      "activities": [{
        "event": "email.click", "eventId": "email.click5120", "eventType": "Email link clicked", "activityType": "clicked",
        "timestamp": "2026-10-09T05:16:03+00:00", "messageId": 3, "subject": "Welcome to Acme", "statId": 8812,
        "campaignId": 2, "campaignName": "Onboarding", "campaignActionId": 10, "campaignActionName": "Send welcome email",
        "campaignActionTriggeredAt": "2026-10-08T22:00:10+00:00", "campaignAttribution": "trackedLink",
        "sentAt": "2026-10-08T22:00:12+00:00", "openedAt": null, "clickedAt": "2026-10-09T05:16:03+00:00",
        "clickedUrl": "https://acme.test/offer", "trackedLinkId": "a1b2c3d4e5", "sendOpenCountTotal": 2,
        "ipAddress": "203.0.113.7", "country": "India", "region": "Gujarat", "city": "Ahmedabad", "isp": "ExampleNet",
        "browser": "Chrome 129.0", "operatingSystem": "Windows 10", "device": "desktop", "deviceBrand": null,
        "deviceModel": null, "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0", "referer": null,
        "source": "email", "failureReason": null, "dncReason": null, "dncComments": null,
        "rawDetails": { "table": "page_hits", "id": 5120 }
      }, { "event": "email.read", "eventId": "email.read301", "...": "see the JSON file" }]
    }, { "contact": { "id": 61, "...": "failed send, failed campaign step, bounce" } }]
  }],
  "pagination": { "page": 1, "limit": 25, "totalEmails": 1, "totalPages": 1, "hasNextPage": false, "hasPreviousPage": false },
  "filters": { "dateFrom": "2026-10-09T00:00:00+00:00", "dateTo": "2026-10-09T23:59:59+00:00", "includeEvents": ["..."],
               "contactId": null, "emailId": null, "campaignId": null, "search": null, "order": "DESC",
               "sortBy": "latestActivity", "contactPage": 1, "contactLimit": 50, "activityLimit": 100,
               "includeContacts": true, "includeActivities": true },
  "unassignedActivities": { "sendsWithoutEmail": 0, "dncWithoutEmail": 0,
                            "note": "Activities in the period that cannot be tied to an email are not part of the email groups." },
  "meta": { "timezone": "UTC", "generatedAt": "2026-10-12T05:40:35+00:00", "queryCount": 16, "dbTimeMs": 12 }
}
```

The example shows the context rule from section 6: Jane's Oct 8 send is not an Oct 9 activity (`sent: 0`), but it is attached to her open and click as `sentAt`.

## 4. Hierarchy

```
data[]                         one entry per email with at least one qualifying activity in the period
├── email                      the email (metadata only; it never qualifies an entry on its own)
├── campaigns[]                campaigns whose steps period activities are attributed to
│   └── steps[]                the steps and how many period activities point at each
├── activitySummary            counts over ALL qualifying activities of this email (not just this page)
├── contactsPagination         paging of the contacts inside this email
└── contacts[]                 one page of contacts with qualifying activity for this email
    ├── contact                contact details
    ├── campaignMemberships[]  this contact's membership of the email's campaigns
    └── activities[]           this contact's qualifying activities for this email, each with its own
                               timestamp and IDs (every open and every click is a separate entry)
```

## 5. Field sources

All tables carry the Mautic prefix (`mautic_` locally).

| Field | Source |
|---|---|
| `email.*` | `emails`: `id`, `name`, `subject`, `description`, `email_type`, `lang`, `is_published`, `publish_up`, `publish_down`, `date_added` → `createdAt`, `date_modified`, `from_address`, `from_name`, `sent_count`/`read_count` (Mautic's lifetime totals, not period counts). `exists: false` when the email was deleted |
| `campaigns[]` | `campaign_events` (`id`, `name`, `type`, `event_type` → `stepType`) joined with `campaigns` (`id`, `name`, `is_published`, `date_added`) |
| `campaigns[].steps[].periodActivities` | Period activities whose send is from that step (sends, failures, opens) plus that step's own `campaign.event` logs |
| `contact.*` | `leads`: `id`, `email`, `firstname`, `lastname`, `company`, `country`, `state` → `region`, `city` |
| `campaignMemberships[]` | `campaign_leads`: `date_added` → `enteredAt`, `manually_added`, `manually_removed`, `date_last_exited` → `exitedAt`, `rotation` |
| `email.sent` / `email.failed` | `email_stats` row (`date_sent`, `is_failed`) → `statId`, `sentAt` |
| `email.read` | `email_stats_devices` row (one per open: `date_opened`, `ip_id`, `device_id`), linked to its send by `stat_id` |
| `email.click` | `page_hits` row with `email_id` set (`date_hit`, `url`, `redirect_id`, `user_agent`, `referer`, `country`/`region`/`city`/`isp`, `source`) |
| `email.unsubscribe` / `.bounce` / `.dnc_manual` | `lead_donotcontact` with `channel = 'email'`: `reason` 1 / 2 / 3, `channel_id` = email, `comments` → `dncComments` |
| `campaign.event` | `campaign_lead_event_log` with `channel = 'email'` and `is_scheduled = 0`; `event_id` → step; failure from `campaign_lead_event_failed_log.reason` |
| `eventId` | type + source row ID (e.g. `email.read301`), unique per activity |
| `timestamp` | The source row's own time column (listed above) |
| `messageId`, `subject` | `email_id` of the activity; the email's subject |
| `statId`, `sentAt`, `sendOpenCountTotal` | The related send in `email_stats` (`id`, `date_sent`, `open_count`). `sendOpenCountTotal` is Mautic's **lifetime aggregate** for that send, not a period count |
| `campaignId`, `campaignName`, `campaignActionId`, `campaignActionName` | From the send's `source = 'campaign.event'` and `source_id` (the step), or from the campaign log itself |
| `campaignActionTriggeredAt` | `campaign_lead_event_log.date_triggered` of the contact's execution of that step closest to the send |
| `campaignAttribution` | How the campaign was found: `sendSource` (the send's source), `trackedLink` (click → `ct.stat` tracking hash → send), `onlySendToContact` (click without a hash, contact got this email once), `campaignLog`, or `null` |
| `clickedUrl`, `trackedLinkId` | `page_hits.url`; `page_redirects.redirect_id` (the tracked link's public ID) |
| `ipAddress`, `country`, `region`, `city`, `isp` | Clicks: `page_hits` geo columns first. Otherwise, and as a fallback, `ip_addresses.ip_address` and its serialized `ip_details` |
| `browser`, `operatingSystem`, `device`, `deviceBrand`, `deviceModel` | `lead_devices`: serialized `client_info` (name + version), `device_os_name` + `device_os_version`, `device`, `device_brand`, `device_model` |
| `userAgent`, `referer` | Clicks only (`page_hits`) |
| `source` | `page_hits.source` for clicks, `email_stats.source` for sends and opens, the step type for campaign logs |
| `failureReason` | `campaign.event`: that log's failure reason. `email.failed`: the failure reason of the campaign step that sent it, when there is one |
| `dncReason`, `dncComments` | `lead_donotcontact.reason` (as text) and `comments` |
| `rawDetails` | Source table and row ID, for tracing an activity back to the database |
| `unassignedActivities` | Counts of sends without an email (`email_stats.email_id IS NULL`) and email do-not-contact entries without an email (`channel_id IS NULL`) in the period |
| `meta.queryCount`, `meta.dbTimeMs` | Number of queries and database time of this request |

## 6. Filtering and counting rules

- **Period activities** are activities whose own timestamp is between `dateFrom` and `dateTo`, inclusive. Only they are listed and counted.
- **Historical context** is attached to period activities but never counted. For example, an open on Oct 9 of an email sent on Oct 8 shows `sentAt: "2026-10-08..."`, and the Oct 8 send is not an Oct 9 `sent`.
- An email appears **only** if it has at least one period activity. Its creation date is metadata only.
- **Counts are event records.** One send opened three times is `sent: 1, opened: 3` (each open is a row in `email_stats_devices`). Unique opens or clicks are not inferred.
- `totalContacts` is the number of distinct contacts with at least one qualifying activity for the email.
- `activitySummary` and `contactsPagination.totalContacts` cover **all** qualifying activities of the email, not just the returned page of contacts. They always use the same filters as the activities.
- `contactId`, `emailId`, `search` and the event filters restrict the qualifying set before anything is grouped or counted.
- **Campaign filter (`campaignId`):**
  - Sends, failures and opens count when the send came from a step of that campaign.
  - Campaign logs count when they belong to that campaign.
  - Clicks count when the contact was sent this email by that campaign.
  - Do-not-contact entries carry no campaign reference, so they are excluded under a campaign filter.
- **Activities that can't be tied to an email are left out of the groups and counted in `unassignedActivities`.** These are sends without an `email_id` (e.g. one-off emails) and email do-not-contact entries without a `channel_id`. Page visits without an email (website traffic) are not email activity and are not included.
- Scheduled, not yet executed, campaign steps (`is_scheduled = 1`) are not activity and are excluded.

## 7. How the queries work

**Qualifying activities** are built as one `UNION ALL` with a branch per source. Each branch filters on its own **indexed** time column:

| Branch | Time index used |
|---|---|
| Sends | `email_stats.date_sent` |
| Opens | `email_stats_devices.date_opened` |
| Clicks | `page_hits.date_hit` |
| Do-not-contact | `lead_donotcontact.date_added` |
| Campaign logs | `campaign_lead_event_log.date_triggered` |

Each query asks only for the columns it needs; carrying fewer columns is what keeps the large aggregations fast.

**Per request:**
1. **Email page:** `GROUP BY email_id` over (`email_id`, `ts`), sorted by `sortBy` with the email ID as tie-breaker. `LIMIT`/`OFFSET` picks the page and `COUNT(*) OVER ()` gives `totalEmails`. Pagination is on **distinct emails**, never on activity rows, so one email's activities are never split across pages.
2. **Summary:** for the page's emails, `GROUP BY email, type, step WITH ROLLUP` gives per-type counts, per-step counts and distinct contacts. Rows without a campaign step use step 0, so they can't be confused with rollup rows.
3. **Contact page:** `ROW_NUMBER() OVER (PARTITION BY email ORDER BY latest activity, contact)` keeps contacts `contactPage`/`contactLimit` of each email.
4. **Activities:** only the selected (email, contact) pairs, at most `activityLimit` each (`ROW_NUMBER`), with `COUNT(*) OVER` per pair so truncation is visible.
5. **Batched lookups** (one query each, by ID list): emails, contacts, the sends of the selected pairs, clicks, do-not-contact rows, campaign steps with campaigns, campaign logs with failure reasons, memberships, IPs, devices, tracked links.

That makes **at most 17 queries per request, whatever the data volume** (no N+1). The 17th is a second campaign-step lookup, needed only when a summary step does not appear in the page's activities. The test `testQueryCountDoesNotGrowWithData` guards this.

**Two measured optimisations:**
- **Unscoped paging query:** almost every campaign-log row has `channel = 'email'`, so MariaDB was reading the whole table through the `(channel, channel_id, lead_id)` index. Writing the condition as `TRIM(l.channel) = 'email'` in the unscoped query only lets it use the `date_triggered` range instead (7 days: 2.8 s → 1.1 s). Queries scoped to specific emails keep the channel index, which suits them.
- **MariaDB only:** the four large aggregations run with `SET STATEMENT tmp_table_size = 64M, max_heap_table_size = 64M FOR …`. With the 16 MB default, a 30-day range's internal temporary table spilled to disk (30-day paging query: 14.3 s → 4.7 s). On MySQL the statements run without this hint.

**Indexes:** **no indexes were added and no Mautic table was changed.** Every query uses Mautic's existing indexes. Adding covering indexes to core tables was not justified by the plans, and would collide with Mautic's own schema management.

## 8. Files

Every change is in `plugins/MauticStatusiBundle/`; no Mautic core file was changed.

| File | |
|---|---|
| `Config/config.php` | **Modified:** new `api` route `mautic_statusi_api_email_activity_report`; route `methods` corrected to Mautic's `method` key; version 1.1.0 |
| `Controller/Api/EmailActivityReportApiController.php` | **New:** the endpoint. It serializes with nulls kept, because Mautic's API drops `null` fields by default |
| `EmailActivity/ActivityType.php` | **New:** activity types, aliases, labels |
| `EmailActivity/ReportCriteriaFactory.php` | **New:** parameter parsing and validation |
| `EmailActivity/ReportAccessResolver.php` | **New:** permissions to restrictions |
| `EmailActivity/ActivitySqlBuilder.php` | **New:** the activity `UNION ALL` |
| `EmailActivity/EmailActivityRepository.php` | **New:** all queries, query count and timing |
| `EmailActivity/EmailActivityReportBuilder.php` | **New:** grouping, enrichment, response |
| `EmailActivity/ReportContext.php` | **New:** per-request lookup data |
| `EmailActivity/ValueParser.php` | **New:** safe reading of Mautic's serialized columns (no object instantiation) |
| `DTO/EmailActivity/ReportCriteria.php` | **New:** validated input |
| `Exception/InvalidReportCriteriaException.php` | **New** |
| `Tests/bootstrap.php`, `Tests/phpunit.xml.dist` | **New:** test setup |
| `Tests/Unit/*` | **New:** validation, permissions, parser, route regression |
| `Tests/Integration/*` | **New:** report behaviour against a real database |
| `Tests/E2E/EmailActivityApiTest.php` | **New:** HTTP tests against a running Mautic |
| `Tests/Performance/benchmark.php` | **New:** data generator and benchmark |
| `Docs/EMAIL_ACTIVITY_REPORT.md`, `Docs/email-activity-report.sample.json` | **New:** this document and the real sample |

## 9. Tests and measurements

### Automated tests (PHPUnit 11.5.57, PHP 8.2.12, MariaDB 10.11.16)

| Suite | Result |
|---|---|
| Unit (36) | ✅ pass: dates and timezones, every validation rule, event aliases, permission matrix, serialized-data safety, core route unchanged |
| Integration (21) | ✅ pass: the 16 data scenarios from the specification plus search, ownership restrictions, sorting, summary-only mode and constant query count |
| E2E (5) | ✅ 4 pass, 1 skipped, over real HTTP against the local Mautic (API + basic auth enabled, admin user): unauthenticated call refused, report returns 200 with nulls kept, bad dates return 400, `/api/contacts/activity` unchanged. The "user without permission → 403" test is skipped until a limited user is configured (`STATUSI_E2E_LIMITED_USER`) |

The integration tests run against a separate database, `<mautic db>_statusi_test`, whose tables are structure copies of the Mautic database. The Mautic data itself is only read.

Additional checks:
- **Container:** Mautic's production container, compiled into a temporary cache folder, autowires all new services and registers `GET /api/custom/email-activity-report`.
- **Core route:** `/api/contacts/activity` still maps to `LeadApiController::getAllActivityAction`.
- **Security:** an unauthenticated call is rejected by the API firewall (locally: "API disabled").

### Performance

Measured locally with `Tests/Performance/benchmark.php`: Windows, XAMPP, MariaDB 10.11 with a 2 GB buffer pool, warm cache.

Synthetic data: 200 emails (5,000 recipients each), 20 campaigns, 100,000 contacts, **1,000,000 sends, 600,000 opens, 200,000 clicks, 20,000 do-not-contacts and 700,000 campaign logs** over 30 days.

Times are wall time for three consecutive runs, including JSON encoding.

| Scenario | Wall time | DB time | Queries | Peak MB | JSON KB | Emails / contacts / activities |
|---|---|---|---|---|---|---|
| 1 day, defaults | 1168 / 1197 / 1203 ms | 1185 ms | 11 | 4.8 | 670 | 8 / 351 / 595 |
| 1 day, 100 emails per page | 1276 / 2103 / 2363 ms | 2328 ms | 11 | 4.8 | 670 | 8 / 351 / 595 |
| 1 day, summary only | 1036 / 1041 / 1039 ms | 1038 ms | 5 | 0.1 | 7 | 8 / 0 / 0 |
| 7 days, defaults | 4553 / 3637 / 4199 ms | 4144 ms | 11 | 17.1 | 2390 | 25 / 1250 / 2124 |
| 30 days, defaults (whole dataset) | 8551 / 9723 / 7657 ms | 7604 ms | 11 | 16.8 | 2391 | 25 / 1250 / 2124 |
| 30 days, one email, 500 contacts | 392 / 385 / 387 ms | 339 ms | 16 | 11.8 | 1722 | 1 / 500 / 1728 |
| 30 days, one contact | 19 / 19 / 20 ms | 18 ms | 15 | 0.3 | 38 | 10 / 10 / 28 |
| 7 days, one campaign | 475 / 493 / 500 ms | 490 ms | 11 | 2.2 | 329 | 3 / 150 / 300 |
| 1 day, search by email name | 298 / 272 / 258 ms | 258 ms | 2 | 0.0 | 1 | 0 / 0 / 0 |

Before the two optimisations above (same data), the same scenarios took **2.0–2.2 s for 1 day, 12.7–15.4 s for 7 days and 36–84 s for 30 days.** Almost all of the remaining time is the database aggregating every activity row in the range (about 2.5 million rows for 30 days).

## 10. Known limitations

- **Wide date ranges on large installs take seconds.** That is about 4 s for 7 days and 8–10 s for 30 days at 1M sends. Narrow ranges, `emailId`, `contactId` and `includeContacts=false` are fast. For sub-second reports over months, a pre-aggregated daily table filled by a cron job would be the next step (not built).
- **Opens have no user agent:** Mautic does not store it per open. IP, location and device per open are returned.
- **Click attribution:** a click is tied to its exact send through the tracked link's hash. Without a hash it is tied only when the contact received that email once; otherwise its campaign is `null`. Under `campaignId`, a click counts when the contact was sent that email by the campaign.
- **`failureReason` for `email.failed`** is only known when the failed send came from a campaign step that logged a reason. Mautic stores no other send-failure text.
- **Bounces and spam complaints** exist only if bounce handling is configured in Mautic (provider webhook or bounce mailbox). Hard and soft bounces are not distinguished (one reason, "bounced"; details may be in `dncComments`).
- **The email provider's (ESP) message ID is not available:** Mautic does not store it.
- **Contact-email search** uses a prefix match on `leads.email`, which has no index in Mautic. It scans the contacts table, so for large installs prefer `contactId`. Campaign names are not searched; use `campaignId`.
- **Removed records:** activities of deleted emails still appear with `email.exists = false`; steps deleted since are left out of `campaigns`.
- **MySQL:** the queries are standard SQL (window functions require MySQL 8 / MariaDB 10.2+). The temporary-table tuning is MariaDB only, and timings were measured on MariaDB only.

## 11. Running it locally

1. **Install the plugin changes:** stop Apache, run `php bin/console cache:clear`, start Apache. Clearing the cache with Apache running breaks the cache on Windows.
2. **Enable the API:** Settings → Configuration → API Settings → *API enabled* = Yes and *Enable HTTP basic auth* = Yes. Then call the endpoint as in section 2 with a Mautic user that may view contacts and emails.
3. **Tests**, from the Mautic root (with Mautic's dev dependencies, or a PHPUnit 11.5 phar instead of `vendor/bin/phpunit`):
   ```bash
   php vendor/bin/phpunit -c plugins/MauticStatusiBundle/Tests/phpunit.xml.dist --testsuite unit,integration
   ```
   The database comes from `config/local.php`; override it with `STATUSI_TEST_DB`, `STATUSI_TEST_DB_HOST`/`_PORT`/`_USER`/`_PASSWORD`.
   For the HTTP tests, also set `STATUSI_E2E_BASE_URL`, `STATUSI_E2E_USER` and `STATUSI_E2E_PASSWORD`, and optionally `STATUSI_E2E_LIMITED_USER` and `STATUSI_E2E_LIMITED_PASSWORD`. Then add `--testsuite e2e`.
4. **Benchmark** (creates and then drops `<mautic db>_statusi_perf`):
   ```bash
   php -d memory_limit=1G plugins/MauticStatusiBundle/Tests/Performance/benchmark.php --sends=1000000
   ```
