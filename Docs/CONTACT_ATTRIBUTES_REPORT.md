# Contact Attribute Change API

`GET /api/custom/contact-attributes`, from the Statusi plugin (`MauticStatusiBundle`). Verified on Mautic 7.2.1 with MariaDB 10.11.

It returns the contacts that were **created or updated** in a date range, with their **current attributes**. Optionally it adds the **field-level change history that Mautic recorded in its audit log**.

---

## 1. What Mautic stores (verified on this install)

| Question | Verified answer |
|---|---|
| Where do contact attributes live? | In one table, `leads`. Core and custom fields are both columns of it. Field definitions are in `lead_fields` (`object = 'lead'`) |
| What is `date_added`? | Creation time (UTC) |
| What is `date_modified`? | Set to the creation time when the contact is created. After that Mautic does not move it reliably: an experiment with Mautic's own `LeadModel::saveEntity` showed a later edit left `date_modified` unchanged, and it only changed when it was empty before saving. So **`date_modified` alone is not a dependable "last updated" time.** It is also NULL for contacts created by tracking |
| Is there change history? | **Yes, partly.** The `audit_log` table (`bundle = 'lead'`, `object = 'lead'`) gets one row per saved change: `create` or `update`, the time, the user (or "System"), and the serialized change as `field => [old value, new value]`. Observed in an experiment: first name `Before → After`, position `NULL → Tester`, points `0 → 5` |
| Limits of that history | Written only by saves that go through Mautic's contact model (UI, API, forms, imports, integrations). Direct SQL such as `last_active` refreshes does not write it. `last_active`, `date_modified` and IP lists are excluded on purpose. Mautic's maintenance cleanup (`mautic:maintenance:cleanup`) can delete old audit rows |

So the endpoint can return **both** current attributes and, where the audit log has them, historical old/new values. It never reconstructs history Mautic did not record.

## 2. Endpoint and parameters

| | |
|---|---|
| URL | `GET https://<mautic>/api/custom/contact-attributes` |
| Auth | Mautic API authentication (OAuth2 or HTTP Basic); the API must be enabled |
| Permissions | `lead:leads:viewother` returns all contacts, `lead:leads:viewown` only contacts the user owns; otherwise `403` |
| Timezone | Everything is UTC |

| Parameter | Required | Default | Rules |
|---|---|---|---|
| `dateFrom` | yes | – | `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS` or ISO 8601 with an offset. Without an offset it is read as UTC. Inclusive |
| `dateTo` | yes | – | Same formats. A date-only value means the end of that day. Inclusive. Must be ≥ `dateFrom`; at most 366 days |
| `changeType` | no | `all` | `created`, `updated` or `all` |
| `contactId` | no | – | Only this contact, if it qualifies |
| `search` | no | – | Prefix match on email, first name, last name and company. Max 100 characters; `%` and `_` are literal |
| `fields` | no | all | Comma-separated or repeated field aliases to return in `contact.fields`. Only published contact fields are allowed; anything else is a `400` |
| `page` | no | 1 | |
| `limit` | no | 100 | 1–500 |
| `order` | no | `DESC` | `ASC` or `DESC` |
| `sortBy` | no | `lastChange` | `lastChange` (the matching timestamp), `dateAdded`, `dateModified`, `id`. The contact ID is always the tie-breaker. NULL timestamps sort as the oldest |
| `includeHistory` | no | `false` | Adds the audit-log history of each returned contact |
| `historyLimit` | no | 50 | History entries per contact, 1–500; truncation is reported |

Invalid input returns `400` with one message per parameter.

## 3. Definitions

| Term | Definition |
|---|---|
| **created** | `leads.date_added` is inside the range |
| **updated** | At least one of these is inside the range: (a) an `audit_log` row with `action = 'update'` for the contact; (b) `leads.date_modified` **later than** `date_added`. A `date_modified` equal to `date_added` is the creation stamp and does not count as an update |
| **all** | Created **or** updated, one entry per contact (deduplicated by ID) |

For a contact that is both, `changeType` is `created_and_updated`. `updateEvidence` lists what proved the update (`auditLog`, `dateModified`). A contact created before the range and edited inside it is `updated`.

`matchedTimestamp` is the latest of the signals that apply. With `changeType=created` or `updated` only that kind of signal is evaluated.

Not counted as updates: changes made by direct SQL that bypass Mautic's contact model (for example `last_active` refreshes), related records such as tags, segments or campaign membership, and changes made in external systems.

## 4. Sample request

```bash
curl -u 'api-user:password' -G 'https://mautic.example.com/api/custom/contact-attributes' \
  --data-urlencode 'dateFrom=2026-10-01 00:00:00' \
  --data-urlencode 'dateTo=2026-10-09 23:59:59' \
  --data-urlencode 'changeType=all' --data-urlencode 'page=1' --data-urlencode 'limit=100' \
  --data-urlencode 'order=DESC' --data-urlencode 'includeHistory=true' \
  --data-urlencode 'fields=email,firstname,lastname,company,phone,city,state,country,points,loyalty_tier'
```

## 5. Sample response

Real output of the implementation for a small fixture; the full JSON is in [`contact-attributes.sample.json`](contact-attributes.sample.json).

In it, contact 55 was created in September and updated twice in the range (a form, then a user). Contact 61 was created in the range.

```json
{
  "data": [
    {
      "contact": {
        "id": 55, "email": "jane@example.com", "firstName": "Jane", "lastName": "Doe", "company": "Example Inc",
        "phone": "+91 20 5555 0101", "mobile": null, "country": "India", "region": "Maharashtra", "city": "Mumbai",
        "owner": { "id": 1, "name": "Asha Patel" }, "stage": { "id": 2, "name": "Lead" },
        "dateAdded": "2026-09-20T09:00:00+00:00", "dateModified": "2026-09-20T09:00:00+00:00",
        "dateIdentified": "2026-09-20T09:00:00+00:00", "lastActive": "2026-10-09T05:16:03+00:00",
        "fields": { "email": "jane@example.com", "firstname": "Jane", "lastname": "Doe", "company": "Example Inc",
                    "phone": "+91 20 5555 0101", "city": "Mumbai", "state": "Maharashtra", "country": "India",
                    "points": 15, "loyalty_tier": "gold" }
      },
      "changeInfo": {
        "changeType": "updated", "createdInRange": false, "updatedInRange": true, "createdAt": null,
        "lastUpdatedAt": "2026-10-08T10:30:00+00:00", "matchedTimestamp": "2026-10-08T10:30:00+00:00",
        "updateEvidence": ["auditLog"]
      },
      "history": {
        "totalEntries": 2, "entriesReturned": 2, "truncated": false,
        "entries": [
          { "id": 502, "action": "update", "timestamp": "2026-10-08T10:30:00+00:00",
            "actor": { "userId": 1, "userName": "Asha Patel" }, "source": { "manipulatedBy": null, "manipulatorKey": null },
            "changes": [ { "field": "loyalty_tier", "oldValue": "silver", "newValue": "gold" },
                         { "field": "points", "oldValue": 10, "newValue": 15 } ] },
          { "id": 501, "action": "update", "timestamp": "2026-10-05T14:02:11+00:00",
            "actor": { "userId": null, "userName": "System" }, "source": { "manipulatedBy": "form", "manipulatorKey": "form:7" },
            "changes": [ { "field": "city", "oldValue": "Pune", "newValue": "Mumbai" },
                         { "field": "state", "oldValue": null, "newValue": "Maharashtra" } ] }
        ]
      }
    },
    { "contact": { "id": 61, "email": "raj@example.com", "...": "see the JSON file" },
      "changeInfo": { "changeType": "created", "createdInRange": true, "updatedInRange": false,
                      "createdAt": "2026-10-07T16:45:00+00:00", "lastUpdatedAt": null,
                      "matchedTimestamp": "2026-10-07T16:45:00+00:00", "updateEvidence": [] },
      "history": { "totalEntries": 1, "entriesReturned": 1, "truncated": false, "entries": [ "..." ] } }
  ],
  "pagination": { "page": 1, "limit": 100, "totalContacts": 2, "totalPages": 1, "hasNextPage": false, "hasPreviousPage": false },
  "filters": { "dateFrom": "2026-10-01T00:00:00+00:00", "dateTo": "2026-10-09T23:59:59+00:00", "changeType": "all",
               "contactId": null, "search": null, "fields": ["email", "..."], "order": "DESC", "sortBy": "lastChange",
               "includeHistory": true, "historyLimit": 50 },
  "meta": { "timezone": "UTC", "attributes": "current values",
            "history": "audit log entries (create/update) inside the range", "generatedAt": "...", "queryCount": 5, "dbTimeMs": 12 }
}
```

## 6. Current attributes versus history

| | Returned |
|---|---|
| **`contact`** | **Current** values, read at request time. Not a snapshot of the moment of the change |
| **`history`** (only with `includeHistory=true`) | The audit-log entries (`create` / `update`) of the contact **inside the date range**, newest first: time, action, actor (`userId`/`userName`; "System" for automated changes), source (`manipulatedBy` / `manipulatorKey`, e.g. `form` / `form:7`, when Mautic recorded them) and, per field, `oldValue` → `newValue` |

- Only published contact fields appear in history, plus `owner`, `stage`, `dateIdentified`, `tags` and `utmtags`. Other audit keys (IP lists, internal data) are never exposed.
- `changes` is empty for entries that changed nothing visible, for example when only unpublished fields changed.
- Contacts changed only by paths that skip the audit log have no history, even though their current values are returned.
- **No complete future history is guaranteed.** Mautic does not record changes made by direct SQL, and a cleanup job can purge old audit rows. If a guaranteed, never-purged history is required, a separate audit mechanism would be needed: an event subscriber in this plugin writing to its own table. It would add a table and storage cost, so it is **not built**; it needs your confirmation first.

## 7. Field sources

| Response field | Source |
|---|---|
| `contact.id` | `leads.id` |
| `contact.email`, `firstName`, `lastName`, `company`, `phone`, `mobile`, `country`, `region`, `city` | `leads.email`, `firstname`, `lastname`, `company`, `phone`, `mobile`, `country`, `state`, `city`. These are returned only when published in `lead_fields` |
| `contact.fields.*` | Every published contact field of `lead_fields` (`object = 'lead'`) whose column exists on `leads`: core fields and custom fields. The values are typed using the field type, so a `number` field is returned as a number |
| `contact.owner` | `leads.owner_id`, with the name from `users` (`first_name last_name`, else `username`) |
| `contact.stage` | `leads.stage_id`, with the name from `stages` |
| `contact.dateAdded`, `dateModified`, `dateIdentified`, `lastActive` | `leads.date_added`, `date_modified`, `date_identified`, `last_active` |
| `changeInfo.*` | See section 3: `leads.date_added`, `leads.date_modified`, `audit_log.date_added` (`action = 'update'`) |
| `history.entries[]` | `audit_log`: `id`, `action`, `date_added`, `user_id`, `user_name`, `details` (unserialized without object instantiation) |
| `unavailable values` | `null` |

Never exposed: unpublished fields, `internal`, `social_cache`, IP addresses, owner credentials or any `users` column other than the name.

## 8. Query design and security

- **Candidates:** a `UNION ALL` of three index-friendly signals: `leads.date_added` (`mautic_lead_date_added`), `audit_log.date_added` (`mautic_date_added_index`) and `leads.date_modified` (`mautic_lead_date_modified`). They are grouped per contact, so a contact that matches several signals appears once.
- **Paging:** `LIMIT`/`OFFSET` in the same query. `COUNT(*) OVER ()` gives an exact total without a second query. Ordering is deterministic (timestamp, then contact ID).
- **Per request:** at most 5 report queries (page, contacts, owners, stages, history; a 6th, the exact total, only when the requested page is past the end) plus 2 field-catalog lookups cached for the request: no N+1.
- **Field allowlist:** `fields` is validated against the published `lead_fields` aliases that exist as columns, and every column name is quoted. No arbitrary column is ever selected.
- **Injection:** every user value is a bound parameter; the `search` wildcards are escaped; IDs and limits are integers.
- **Core endpoints are untouched:** `/api/contacts`, `/api/contacts/{id}` and `/api/contacts/activity` are unchanged. A test checks that the plugin does not shadow them.
- **MariaDB:** the page query runs with a per-statement `tmp_table_size = 64M` hint. On MySQL it runs without it.

**Indexes:** **no index was added and no Mautic table was changed.** The three signals use existing indexes. The audit log's date index is not always used by MariaDB's optimizer (it scans the table when the range is wide), and forcing it saved only about 10%, so no hint or index was added.

## 9. Files

All in `plugins/MauticStatusiBundle/`; no core file was changed.

| File | |
|---|---|
| `Config/config.php` | **Modified:** route `mautic_statusi_api_contact_attributes` |
| `Controller/Api/ContactAttributesApiController.php` | **New:** the endpoint (nulls kept in the response) |
| `ContactChanges/ContactChangeCriteriaFactory.php` | **New:** parameter validation |
| `ContactChanges/ContactChangeRepository.php` | **New:** page query and batched lookups |
| `ContactChanges/ContactChangeReportBuilder.php` | **New:** response, `changeInfo`, history |
| `ContactChanges/ContactFieldCatalog.php` | **New:** the field allowlist |
| `DTO/ContactChanges/ContactChangeCriteria.php` | **New** |
| `Api/QueryParameters.php` | **New:** parsing shared by both API endpoints |
| `EmailActivity/ReportCriteriaFactory.php` | **Modified:** now uses `QueryParameters` (behaviour unchanged; its tests pass) |
| `EmailActivity/ReportAccessResolver.php` | **Modified:** `resolveContacts()` added |
| `Tests/Unit/*`, `Tests/Integration/*`, `Tests/E2E/ContactAttributesApiTest.php`, `Tests/Performance/contact_benchmark.php` | **New:** tests and benchmark |
| `Docs/CONTACT_ATTRIBUTES_REPORT.md`, `Docs/contact-attributes.sample.json` | **New** |

**Setup:** no database migration and no configuration change. Stop the web server, clear Mautic's cache so the new route is loaded, start the web server, and enable the API.

## 10. Tests and measurements

PHPUnit 11.5, PHP 8.2, MariaDB 10.11. The integration tests run on a copy of the Mautic tables (`<mautic db>_statusi_test`), including two test custom fields (one published, one unpublished).

| Your case | Test |
|---|---|
| 1 created in range | `testContactCreatedInRange` |
| 2 created before, updated in range | `testContactCreatedBeforeAndUpdatedInRange` |
| 3 created and updated, returned once | `testCreatedAndUpdatedContactIsReturnedOnce` |
| 4 outside the range | `testContactOutsideRangeIsExcluded` |
| 5–7 `created` / `updated` / `all` | `testChangeTypeFilters` |
| 8 boundaries and timezone | `testBoundariesAndTimezone` |
| 9–10 pagination and stable sort | `testPaginationIsCompleteAndStable`, `testSortByDateAddedAndDateModified` |
| 11 search and contact ID | `testSearchAndContactIdFilters` |
| 12 custom fields | `testCustomFieldsAndFieldSelection` |
| 13 null timestamps | `testNullTimestamps` |
| 14 invalid input | `ContactChangeCriteriaFactoryTest` (17 invalid cases) |
| 15 authentication and authorization | `ReportAccessResolverTest` (permission matrix), `ContactAttributesApiTest` (HTTP) |
| 16 existing contact endpoints unchanged | `RouteRegressionTest`, `testCoreContactEndpointsAreUnchanged` (HTTP) |
| History, audit data only | `testHistoryIsVerifiedAuditDataOnly` |
| No N+1 | `testQueryCountIsConstant` |

**Results:** 94 unit and integration tests pass (453 assertions). HTTP tests are in section 11.

### Performance

Measured locally (Windows, XAMPP, MariaDB 10.11 with a 2 GB buffer pool, warm cache) with `Tests/Performance/contact_benchmark.php`.

Synthetic data: **1,000,000 contacts** created over a year (10% with a later `date_modified`) and **1.8 million audit rows**: 1M creates, 500k updates in the last 30 days and 300k other rows.

Wall time of three runs, including JSON encoding:

| Scenario | Wall time | DB ms | Queries | Peak MB | JSON KB | Returned / matching |
|---|---|---|---|---|---|---|
| 1 day, all | 263 / 232 / 286 ms | 265 | 5 | 0.8 | 108 | 100 / 19,630 |
| 1 day, all, with history | 276 / 265 / 261 ms | 250 | 6 | 1.2 | 139 | 100 / 19,630 |
| 1 day, created only | 30 / 32 / 32 ms | 20 | 5 | 0.8 | 107 | 100 / 2,740 |
| 1 day, updated only | 226 / 230 / 219 ms | 208 | 5 | 0.8 | 108 | 100 / 16,935 |
| 7 days, all | 1981 / 1938 / 2059 ms | 2041 | 5 | 0.8 | 108 | 100 / 134,576 |
| 30 days, all | 8237 / 8415 / 8120 ms | 8096 | 5 | 0.8 | 108 | 100 / 541,924 |
| 30 days, 500 per page, page 20 | 8525 / 8456 / 8753 ms | 8715 | 5 | 4.1 | 540 | 500 / 541,924 |
| 1 year, created only | 10.7 / 10.9 / 10.6 s | 10594 | 5 | 0.8 | 107 | 100 / 999,999 |
| 30 days, one contact + history | 39 / 25 / 19 ms | 12 | 6 | 0.0 | 2 | 1 / 1 |
| 7 days, search company prefix | 1432 / 1519 / 1445 ms | 1417 | 5 | 0.8 | 108 | 100 / 1,476 |
| 7 days, 3 fields only | 1985 / 2004 / 1909 ms | 1882 | 5 | 0.6 | 66 | 100 / 134,576 |

Queries include the 2 field-catalog queries. Before removing a `GROUP BY` from the audit branch (which made MariaDB scan an index over 1.65M audit rows), the same scenarios took 6.7 s for 7 days and 16 s for 30 days. **Response size and memory stay small whatever the volume**, because only one page is built.

Time grows with the number of matching contacts, since the exact total and the ordering need all of them: about 2 s for 135k matches and 8 s for 540k.

## 11. Known limitations

- **Exact totals cost time on wide ranges.** A range with hundreds of thousands of matching contacts takes seconds. Narrow ranges, `contactId`, `changeType=created` and small result sets are fast. There is no cheaper exact count; an estimated total was deliberately not used.
- **Updates are detected through the audit log and `date_modified`.** Anything that bypasses Mautic's contact model, or that was purged from the audit log, is invisible.
- **`date_modified` is not dependable** (section 1), so audit rows are the primary update evidence.
- **History needs the audit log.** A contact updated through a path that does not log has no history entries. A permanent audit trail would be a separate, opt-in addition (section 6).
- **Company, segment and tag membership changes** are not contact attribute updates.
- **Search** is a prefix match on a few fields without dedicated indexes: it scans, and is best combined with a narrow range.
- **Not verified over HTTP yet:** the HTTP tests need the API enabled and the route loaded into Mautic's cache (see below).

## 12. Running it locally

1. Stop Apache. Run `php bin/console cache:clear`. Start Apache. (The new route needs the cache cleared.)
2. Enable the API and basic auth in Settings → Configuration → API Settings.
3. Call the endpoint as in section 4, or run the tests, from the Mautic root:
   ```bash
   php vendor/bin/phpunit -c plugins/MauticStatusiBundle/Tests/phpunit.xml.dist --testsuite unit,integration
   # HTTP tests (STATUSI_E2E_BASE_URL, STATUSI_E2E_USER, STATUSI_E2E_PASSWORD set):
   php vendor/bin/phpunit -c plugins/MauticStatusiBundle/Tests/phpunit.xml.dist --testsuite e2e
   ```
4. Benchmark (creates and drops `<mautic db>_statusi_contact_perf`):
   ```bash
   php -d memory_limit=1G plugins/MauticStatusiBundle/Tests/Performance/contact_benchmark.php --contacts=1000000
   ```
