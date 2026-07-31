# Remaining work

Status as of 2026-07-31, after core v1.1.0. Ordered by priority.

---

## 1. Agent aggregator detection has been broken since 2026-05-06 — HIGH

**Symptom.** Reports tagged `WPeMatico` and `Feedzy` stop dead on 2026-05-06.
`Domain Scan (Content Match)` — the *fallback* branch — continues to this day.

```
aggregator_name              valid  dup   last report
Domain Scan (Content Match)   1566  340   2026-07-31  ← still running
WPeMatico                     2034  177   2026-05-06  ← stopped
Feedzy                         721   80   2026-05-06  ← stopped
```

Content is still being syndicated and still detected, but only by the crude fallback.

**Why it matters.** The fallback produces markedly worse data. It scrapes the first link
containing the hub domain out of post content, so **84% of its reports carry
`source_url = https://pinionnewswire.com`** — the bare site root, with no `source_post_id`
and no `source_guid`. That leaves `content_hash` as the only usable dedup key, and makes the
records nearly useless for tracing a specific pickup.

**Likely cause.** `detect_syndication()` keys off aggregator-specific post meta:

| Branch | Meta key |
|---|---|
| Feedzy | `feedzy_item_url` |
| WPeMatico | `wpe_campaignid`, `wpe_sourcepermalink` |
| WP RSS Aggregator | `wprss_item_permalink` |

A plugin update on the partner sites around 2026-05-06 most likely renamed or stopped
writing these. Note `class-synditracker-agent-detector.php` already carries a
`// Fixed key name` comment on `wpe_sourcepermalink` — this has bitten before.

**To do.** On a partner site (or `st-agent-wpe`), dump the meta of a freshly imported post
and compare against the keys above, then update the branches. Consider asserting the keys in
a health check so the next rename surfaces immediately instead of silently degrading to the
fallback.

**Blocked on:** access to a partner site, or reviving `st-agent-wpe` as the harness.

---

## 2. The agent ships every post meta to the Hub — MEDIUM (privacy / size)

`report_syndication()` builds `debug_log` with:

```php
'meta' => get_post_meta( $post_id ),
```

That is **all** post meta from a third-party site, transmitted to the Hub and stored in
`wp_synditracker_reports.debug_log` (`longtext`) on every report. It may contain data the
partner never intended to share, and it bloats both the payload and the table.

**To do.** Send an explicit allowlist of the keys actually used for debugging, or drop the
meta dump entirely and gate it behind a `synditracker_debug` option.

---

## 3. `synditracker_spike_threshold` is still `1` in production — LOW

The v1.1.0 throttle caps this at one alert per partner per hour, so it is no longer a spam
risk. But `1` means "alert on any repeat at all", which is not a spike. Recommend `5`:

```bash
wp option update synditracker_spike_threshold 5 --path=/var/www/html
```

---

## 4. Agent retry queue has a read–modify–write race — LOW

`queue_retry()` and `process_retry_queue()` both do
`get_option()` → mutate array → `update_option()`. Two concurrent detections can lose an
entry. Low impact (reports are best-effort and re-reported on the next save), but it is the
classic WordPress option-array race.

Also: on a *successful* retry, `process_retry_queue()` never sets `_synditracker_reported`,
so that post stays eligible for re-reporting.

---

## 5. `wp_synditracker_keys` is orphaned — LOW (cleanup)

The table holds 2 rows ("BusinessNewsRelease") and is read by **no code**. Authentication
runs through the `partner_site` CPT plus transient tokens. Its `last_seen` column is also
where the timezone bug was most visible — row 2 has `last_seen` five hours *earlier* than its
own `created_at`.

**To do.** Either wire it up as a real "last agent contact" signal (genuinely useful — it
would have surfaced item 1 in May) or drop the table.

---

## 6. Alert content is thin — LOW

The spike alert reports counts, a partner and a window. It does not link to the affected
items. Once item 1 is fixed and `source_url` is trustworthy again, include the two or three
most recent offending URLs.

---

## Test environment — do not delete yet

`st-agent` and `st-agent-wpe` on the Pinion VPS look abandoned (untouched since
2026-02-11, no DNS records) and were briefly slated for decommissioning. They are the only
environment that can exercise the agent→Hub path, which is exactly what items 1, 2 and 4
require. Retire them **after** that work lands.

Two caveats if you do bring them back up:

- `st-agent-wpe`'s vhost declares `extprocessor lsapi:lsphp83` while its `scripthandler`
  references `lsphp83`. The names do not match, so LiteSpeed falls back to the **static file
  handler for `.php`** — it would serve PHP source, including `wp-config.php`, as plain text.
  Harmless while the host has no DNS record; fix it before exposing the site. The simplest
  fix is deleting both blocks so the vhost inherits the working server-level handler.
- Both installs share the `st_agent_db` database.
