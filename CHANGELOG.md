# Changelog

## synditracker-core 1.1.0 — 2026-07-31

Stops the Discord alert storms and fixes the bugs found while tracing them.
Background: [`docs/ALERT-STORM-POSTMORTEM.md`](./docs/ALERT-STORM-POSTMORTEM.md).

### Fixed

- **Alert storms.** Every duplicate observation fired an unthrottled Discord message; one
  article once produced 112 alerts in 3.5 hours. Repeats no longer alert individually.
- **`insert_report()` returned the wrong value** — `$wpdb->insert()`'s affected-row count
  rather than `$wpdb->insert_id`, so the REST API answered `report_id: 1` for every report.
- **`create_table()` was missing the `debug_log` column** that `insert_report()` writes, so
  a fresh install produced a table every insert failed against.
- **No schema migration path.** `synditracker_db_version` was never read or written, and the
  `alerts` and `keys` tables were never created by this code.
- **Timezone inconsistency** between `current_time('mysql')` and `CURRENT_TIMESTAMP` column
  defaults. Window arithmetic now happens in SQL via `DATE_SUB`.
- **Dedup false positives.** `source_title` was matched before `content_hash`, so unrelated
  posts sharing a headline were treated as the same content. Title is now the last matcher.
- **Expired token handling.** If the transient lapsed between the permission callback and the
  handler, the report was filed against `partner_site_id` 0. Now returns 401.

### Added

- `Synditracker_Alerts` — spike detection with a rolling window, using
  `wp_synditracker_alerts` as a throttle ledger so at most one alert fires per partner per
  window even at threshold 1.
- `Synditracker_Core_DB::record_observation()` — idempotent ingestion; a repeat bumps
  `seen_count` / `updated_at` instead of inserting a row.
- `Synditracker_Core_DB::maybe_upgrade()` — additive, existence-checked migrations on `init`.
- `seen_count` column, plus `updated_at` and `partner_seen` indexes.
- `Synditracker_Discord::get_webhook_url()` — resolves the three webhook options production
  had accumulated, in precedence order.
- Filters: `synditracker_duplicate_matchers`, `synditracker_spike_threshold`,
  `synditracker_alert_window_hours`, `synditracker_discord_enabled`,
  `synditracker_discord_webhook_url`.
- `seen_count` in the `/log` response.

### Changed

- Discord sends no longer write an audit log entry containing the full payload and a
  partially-masked webhook URL; timeout reduced 10s → 5s. (Backported from a hand-edit that
  was live on production but never committed.)
- `get_reports()` orders by `updated_at` and accepts a `limit` argument.

### Removed

- `check_duplicate()` and `check_duplicate_extended()`, superseded by `find_existing()`.

### Upgrade notes

- The migration is additive and preserves existing rows; all 4,918 production rows survived.
- It deliberately does **not** run `dbDelta` on an existing reports table — production's
  schema was created by a plugin version no longer in this repo.
- On the LiteSpeed host, `sudo killall lsphp` after deploying or stale bytecode keeps serving.
- Existing rows all start at `seen_count = 1`; historical duplicate rows were **not**
  collapsed, so pre-1.1.0 data still contains repeat rows.

---

## 1.0.0 — 2026-02-11

Initial agent + core.
