# Synditracker

Synditracker detects when Pinion Newswire content is republished on partner sites and
records it centrally, so syndication coverage can be tracked and unexpected duplication
can be spotted.

It is two WordPress plugins that talk to each other over REST:

| Component | Runs on | Role |
|---|---|---|
| [`synditracker-agent`](./synditracker-agent) | **Partner sites** (BetaNews, ABC39, TEDPress, Fox80, BusinessNewsRelease…) | Detects imported content and reports it to the Hub |
| [`synditracker-core`](./synditracker-core) | **The Hub** (pinionnewswire.com) | Ingests reports, deduplicates, stores, alerts |

A common source of confusion: **"Domain Scan (Content Match)" is an agent feature, not a Hub
feature.** The Hub does not crawl anything. Everything in the reports table arrived because
an agent on a partner site posted it.

---

## How it works

```
Partner site                                   Hub (pinionnewswire.com)
────────────                                   ────────────────────────
post published
   │
   ├─ save_post → schedule_detection()
   │     guards: not autosave/revision, post type
   │     'post', status 'publish', not already
   │     reported. Defers 15s so aggregator
   │     plugins finish writing their meta.
   │
   ├─ wp_schedule_single_event → run_deferred_detection()
   │     detect_syndication() tries, in order:
   │       1. Feedzy            (feedzy_item_url)
   │       2. WPeMatico         (wpe_campaignid)
   │       3. WP RSS Aggregator (wprss_item_permalink)
   │       4. Generic importer  (syndication_permalink)
   │       5. Domain scan       (hub domain appears in content) ← fallback
   │
   └─ report_syndication()
         POST /oauth/token   (client_credentials) ──────────►  1h transient Bearer token
         POST /log           (Bearer token)       ──────────►  ingest_report()
              on failure → exponential-backoff retry queue        │
                                                                  ▼
                                                       record_observation()
                                                       ├─ known content? bump seen_count
                                                       └─ new? insert row
                                                                  │
                                                        repeat ──►│
                                                                  ▼
                                                       Synditracker_Alerts::maybe_alert()
                                                       count repeats in window
                                                         ≥ threshold AND not already
                                                         alerted this window → Discord
```

### Deduplication

The Hub stores **one row per distinct piece of content, per partner site**. A re-observation
updates `seen_count` and `updated_at` on the existing row rather than inserting another.

Matching runs strongest-first, and stops at the first hit:

1. `source_post_id`
2. `content_hash`
3. `source_guid`
4. `source_title` — deliberately **last**; it is the weakest signal and matching on it
   earlier caused unrelated posts sharing a headline to be treated as the same content.

Override the list with the `synditracker_duplicate_matchers` filter.

### Alert policy

Repeats do **not** alert individually. `Synditracker_Alerts::maybe_alert()` counts how many
distinct items were re-observed for a partner inside a rolling window, and alerts only when
that reaches the threshold. The `wp_synditracker_alerts` table doubles as a throttle ledger:
one alert per partner per window, regardless of how many repeats arrive.

This matters — see [`docs/ALERT-STORM-POSTMORTEM.md`](./docs/ALERT-STORM-POSTMORTEM.md).
Before v1.1.0 every repeat fired a Discord message unconditionally, and a single article once
produced **112 alerts in three and a half hours**.

---

## REST API

Namespace `synditracker/v1`, served by the Hub.

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `/health` | GET | none | Liveness probe |
| `/oauth/token` | POST | `client_id` + `client_secret` | Issues a 1-hour Bearer token |
| `/log` | POST | Bearer token | Ingests a report |
| `/reports` | GET | `manage_options` | Last 50 reports |

Partner credentials live on the `partner_site` CPT as `_synditracker_client_id` and
`_synditracker_client_secret`. Tokens are transients (`synditracker_token_<token>`), so a
Hub object-cache flush invalidates them; agents simply re-authenticate.

`/log` accepts a base64 `payload` field as a WAF-bypass wrapper; if present it is decoded and
replaces the request body.

**Response** — note `status` is `duplicate` for a repeat even though no new row was created:

```json
{ "success": true, "report_id": 4733, "status": "duplicate", "seen_count": 2 }
```

---

## Data model

| Table | Purpose |
|---|---|
| `wp_synditracker_reports` | One row per distinct content item per partner. `seen_count` counts observations. |
| `wp_synditracker_alerts` | Alert ledger **and** throttle state. |
| `wp_synditracker_logs` | `audit` / `access` / `error` events. |
| `wp_synditracker_keys` | **Orphaned.** Not read by any code — auth goes through the CPT + transients. |

### Schema versioning

`Synditracker_Core_DB::maybe_upgrade()` runs on `init` and no-ops once
`synditracker_db_version` matches `DB_VERSION`.

It applies **additive, existence-checked `ALTER`s only** and deliberately does *not* run
`dbDelta` against an existing reports table. The production schema was created by a version
of this plugin that no longer exists — the live site sat at `db_version 1.0.7` carrying
columns no code in this repo ever created — so `dbDelta` would try to "correct" columns it
did not author. `create_table()` is for fresh installs; `maybe_upgrade()` is for live ones.

---

## Configuration

| Option | Default | Meaning |
|---|---|---|
| `synditracker_spike_threshold` | `5` | Distinct repeated items in the window before alerting |
| `synditracker_alert_settings['scanning_window']` | `1` | Window, in hours |
| `synditracker_alert_settings['discord_enabled']` | on | Master switch for Discord delivery |
| `synditracker_discord_webhook_url` | — | Canonical webhook. Two legacy options are read as fallbacks |
| `synditracker_agent_hub_url` | — | *(agent-side)* Hub URL; also the domain the fallback scanner looks for |

Filters: `synditracker_duplicate_matchers`, `synditracker_spike_threshold`,
`synditracker_alert_window_hours`, `synditracker_discord_enabled`,
`synditracker_discord_webhook_url`.

> The webhook URL is a secret and is **not** stored in this repo. It lives in `wp_options`.
> Historically it accumulated across three separate options; `Synditracker_Discord::get_webhook_url()`
> resolves them in precedence order so old values keep working.

---

## Install / deploy

The Hub plugin is deployed by copying the directory into `wp-content/plugins/` and
recycling PHP:

```bash
tar czf - synditracker-core | ssh <host> 'sudo tar xzf - -C /var/www/html/wp-content/plugins/ \
  && sudo chown -R www-data:www-data /var/www/html/wp-content/plugins/synditracker-core \
  && sudo killall lsphp'
```

`killall lsphp` is **required** on the Pinion LiteSpeed host: its opcache does not revalidate
timestamps, so without it the old bytecode keeps serving. Then let `init` run the migration,
or force it:

```bash
sudo -u www-data HOME=/tmp wp eval \
  'require_once WP_PLUGIN_DIR."/synditracker-core/includes/class-synditracker-core-db.php"; \
   Synditracker_Core_DB::maybe_upgrade();' --path=/var/www/html
```

Always rehearse on `/var/www/staging` first, and **disable Discord there** or staging will
post test alerts into the live channel:

```bash
wp eval '$s=get_option("synditracker_alert_settings",array()); $s["discord_enabled"]=0; \
  update_option("synditracker_alert_settings",$s);' --path=/var/www/staging
```

## Test environment

`st-agent` and `st-agent-wpe` on the Pinion VPS are WordPress installs carrying the agent
plugin, the second with WPeMatico configured to pull the Pinion feed. They are the only
harness for exercising the agent→Hub path. See
[`docs/REMAINING-WORK.md`](./docs/REMAINING-WORK.md) before retiring them.

---

*Built for Pinion Partners.*
