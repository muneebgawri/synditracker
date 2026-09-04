# Postmortem — Discord alert storms (Feb–Jun 2026, fixed 2026-07-31)

## Symptom

The `⛔-synditracker-alerts` Discord channel received a stream of
"⚠️ Duplicate Syndication Detected" messages that read as spam and as false positives.
Nobody acted on them, so nobody trusted the channel.

## What was actually happening

Partner sites routinely create **many distinct posts holding identical content** — an
aggregator re-importing the same feed items produces a genuinely new post each time. Each of
those was a legitimate new report from the agent. The Hub then:

1. classified each as `duplicate` (correct — the *content* was already known), and
2. fired a Discord message for it, **unconditionally and unthrottled**.

So alert volume scaled with re-import volume, not with anything a human could act on.

### Evidence

Repeats of a single content hash, from `wp_synditracker_reports`:

| `content_hash` | Times reported | Window |
|---|---|---|
| `539957b4…` | **112×** | 2026-02-11, 3.5 hours |
| `95cbacff…` | **99×** | 2026-02-11, 3.5 hours |
| `78d49375…` | 4× | 2026-06-01, 86 seconds |
| `d1bd39ca…` | 4× | 2026-06-08, 3 minutes |

The February figures come from the original test session on `st-agent-wpe`, where WPeMatico
was run repeatedly against the same feed.

From the last 100 Discord messages (2026-05-04 → 2026-06-23), retrieved via
`pinion-discord-observer`:

- **68 unique titles in 100 messages** — a third were re-alerts of already-alerted content;
  one title appeared 6 times.
- **73% were BetaNews**, which is Pinion's own syndication bridge. All the partner sites are
  registered and sanctioned, so content appearing there is the system working correctly.
- **84% carried `Source URL: https://pinionnewswire.com`** — the bare site root, so the alert
  did not even point at the offending content.
- At least one alert fired on a `/wp-content/uploads/…` image rather than an article.

### The design gap

`wp_synditracker_alerts` and the `synditracker_spike_threshold` option both existed. The
option was set to `1` in production. Neither was referenced by a single line of code — the
throttle was designed and never built.

More fundamentally: "duplicate" is an internal bookkeeping condition meaning *the scanner saw
the same thing twice*. There is no human action attached to it, so it should never have been
a realtime page.

## Fix (v1.1.0)

- **Idempotent ingestion.** `Synditracker_Core_DB::record_observation()` bumps `seen_count`
  and `updated_at` on the existing row instead of inserting a duplicate row.
- **Spike threshold implemented.** `Synditracker_Alerts` counts distinct items re-observed
  per partner over a rolling window and alerts only at the threshold.
- **Throttle ledger.** `wp_synditracker_alerts` is checked before sending, so at most one
  alert fires per partner per window — even with the threshold at 1.
- **Title demoted** to the last dedup matcher, removing a false-positive source.

## Incidental bugs found and fixed alongside

| Bug | Effect |
|---|---|
| `insert_report()` returned `$wpdb->insert()`'s row count, not `insert_id` | API answered `report_id: 1` for every report |
| `create_table()` omitted the `debug_log` column that `insert_report()` writes | Any **fresh install** had a table inserts failed against |
| No migration path; `synditracker_db_version` never read or written | `alerts`/`keys` tables were never created by this code |
| `current_time('mysql')` mixed with `CURRENT_TIMESTAMP` defaults | `wp_synditracker_keys.last_seen` was ~5h *earlier* than its own `created_at` |
| Webhook URL stored in three options, one read | Silent drift between them |
| Discord audit log wrote full payload + partially-masked webhook URL on every send | Log noise and a mild secret leak (fixed in prod by hand first, backported here) |

## Verification

Exercised on staging with Discord disabled, at both the DB and REST layers:

- 6 observations of 3 contents → **3 rows**, total `seen_count` **6**
- Alert fired **once** on reaching threshold; 5 further attempts suppressed by the throttle
- REST returned a real `report_id`, reused the same row on repeat, incremented `seen_count`,
  wrote only one row, and 401'd an invalid token

Production migration applied with all 4,918 existing rows intact.

## Lessons

1. **Alert on conditions a human can act on.** Duplicate-detection bookkeeping is not one.
2. **A configured option that no code reads is worse than no option** — production sat at
   `spike_threshold = 1` for months, looking like a deliberate and functioning setting.
3. **Idempotency belongs at the recording layer.** Trying to suppress noise at the
   notification layer alone would have left the reports table just as inflated.
