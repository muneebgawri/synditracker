# WPeMatico test harness

Scripts for standing up a **simulated partner site** — a WordPress install that scrapes the
Pinion feed via WPeMatico, so the agent's detection and the agent→Hub reporting path can be
exercised end to end.

They were written on 2026-02-11 while building Synditracker, and lived on the
`st-agent-wpe` VPS install until it was decommissioned on 2026-07-31. Preserved here
because [`docs/REMAINING-WORK.md`](../../docs/REMAINING-WORK.md) item 1 — agent aggregator
detection dead since 2026-05-06 — cannot be diagnosed without a rig like this.

## What each script does

| Script | Purpose |
|---|---|
| `create_wpe_campaign.php` | Creates a `wpematico` campaign post pulling `https://pinionnewswire.com/feed/`, then writes its `campaign_data` meta |
| `run_wpe_job.php` | Fires a campaign manually via `WPeMatico_functions::wpematico_dojob()` and dumps the campaign log |
| `update_wpe_campaign.php` | Patches `campaign_data` keys whose absence causes fatals (`campaign_feed_order_date`, `campaign_max`, …) |
| `update_wpe_campaign_dupes.php` | Adjusts the campaign's duplicate-handling settings |

All four are run from the WordPress root (they `require 'wp-load.php'`), e.g.
`sudo -u www-data php create_wpe_campaign.php`.

## Notes for whoever rebuilds this

- **Campaign IDs are hardcoded.** `run_wpe_job.php` and `update_wpe_campaign.php` both assume
  campaign ID `4`. Change them to whatever `create_wpe_campaign.php` prints.
- **`run_wpe_job.php` spoofs a browser User-Agent** via the
  `wpematico_simplepie_user_agent` filter. Cloudflare blocks the default SimplePie UA, so
  without it the fetch fails — see the `.htaccess` UA blacklist on the Pinion origin.
- **WPeMatico needs several `campaign_data` keys present** or it fatals mid-run. That is what
  the two `update_*` scripts exist to work around; expect to need more of the same on a newer
  WPeMatico version.
- **The rig produces many posts with identical content**, which is precisely the condition
  that caused the alert storms. That is a feature for testing — it is the realistic partner
  behaviour — but point it at a Hub with Discord disabled, or you will spam the live channel.

## Rebuilding the rig

1. Fresh WordPress install, any host that can reach the Hub.
2. Install WPeMatico and `synditracker-agent`.
3. Configure the agent: set `synditracker_agent_hub_url`, and register the site on the Hub as
   a `partner_site` post with `_synditracker_client_id` / `_synditracker_client_secret`.
4. Run `create_wpe_campaign.php`, then `run_wpe_job.php`.
5. Dump the meta of an imported post and compare against the keys
   `class-synditracker-agent-detector.php` looks for — that comparison is the actual
   diagnostic for the detection regression.
