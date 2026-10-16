# Changelog

All notable changes to the Statusi plugin for Mautic. Versions follow [Semantic Versioning](https://semver.org/).

## 1.1.0 – 2026-10-15

First public release.

### Connection
- **Connect to Statusi** from Settings → Plugins → Statusi: Statusi sign-in popup, authorization code + PKCE, server-to-server token exchange.
- Connect gives Statusi read-only access to Mautic's API for the daily sync: it turns the API on, creates a view-only role and an OAuth2 client-credentials client. Disconnect deletes the client.

### Statusi reports in Mautic
- **Statusi** sidebar menu listing the connected account's reports (in Statusi's order, cached for 5 minutes).
- Read-only report table, as in Statusi: configured filters (shown as a summary), totals in headers, nested columns, drill-down with breadcrumbs, infinite scroll.

### APIs (Mautic API authentication)
- `GET /api/custom/email-activity-export` and `GET /api/custom/contact-attribute-export`: flat, cursor-paged exports in Statusi's format, read by Statusi's daily Mautic DAGs.
- `GET /api/custom/email-activity-report` and `GET /api/custom/contact-attributes`: email activity and contact change reports.

### Requirements
- Mautic 7, PHP 8.2+. Mautic must be reachable over https by Statusi for the daily sync.
