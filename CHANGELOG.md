# Changelog

## 1.9.2
- Moved GitHub update-checker initialization to a dedicated include class (`includes/class-krv-max-github-updater.php`).
- Added vendor placeholder doc for updater library path (`lib/plugin-update-checker/README.md`).
- Kept all update safeguards (missing file/class fallback, no-fatal behavior, one-time init, ZIP release assets filter).

## 1.9.1
- Added GitHub-based plugin updates via YahnisElsts/plugin-update-checker.
- Added `Update URI` metadata to the plugin header for external update compatibility.
- Added safe update-checker bootstrap with file/class guards and one-time initialization.
- Enabled GitHub Release assets support with ZIP asset filter (`/\.zip($|[?&#])/i`).
- If updater library is missing, plugin continues to work normally without auto-update checks.

## 1.9.0
- Added multi-target delivery for MAX: one post can now be sent to multiple chat IDs (channels and/or group chats).
- Kept backward compatibility: existing single `chat_id` setting remains the primary target and continues to work unchanged.
- Added `additional_chat_ids` setting (one value per line) with trim/sanitize, duplicate removal, and empty-line filtering.
- Refactored dispatcher flow to iterate targets sequentially and continue after per-target failures.
- Added aggregate delivery outcomes: `success`, `partial_success`, `error`.
- Added per-target delivery result meta (`_krv_max_target_results`) with `chat_id`, `status`, `message_id`, `error`.
- Updated queue UI with target result summary plus `partial_success` filter/counter.
- Updated test-send action to send to all configured targets.
- Updated Help tab text for channel/group/multi-target usage and chat ID list format.

## 1.8.9
- Added support for `define('KRV_MAX_CHAT_ID', '...')` so Chat ID can be stored in `wp-config.php` instead of the database.
- Reused the new helper in test-send and live post sending to ensure one consistent Chat ID source.

## 1.8.8
- Added queue status counters (all/queued/error/sent) on Queue tab.
- Counter cards are clickable and switch Queue filter directly.

## 1.8.7
- Added queue status filters on Queue tab: all / queued / error / sent.
- Improves admin triage for pending and failed items.

## 1.8.6
- Added explicit worker status indicator (OFF/ON) on Queue tab.
- Added dedicated controls to manually enable/disable auto-worker.

## 1.8.5
- Added outgoing text cleanup to remove HTML entities/non-printable artifacts (including `&nbsp;`).
- Successful test-send now arms the worker so scheduled posts can auto-send again.

## 1.8.4
- Added “Оставить звезду на GitHub” button to the Help tab.
- Button opens the GitHub repository page where users can click Star.

## 1.8.3
- Manual queue run now processes exactly one item per click.
- Manual run no longer arms persistent automatic worker mode.

## 1.8.2
- Disabled automatic worker arming on settings save (token/chat id).
- Queue sending now starts only on explicit manual queue run, preventing immediate old-message bursts after entering credentials.

## 1.8.1
- Added safe-start behavior: queue worker is disabled after install/upgrade until valid token/chat id are saved or queue is manually started.
- Prevents immediate burst auto-sends right after fresh install.

## 1.8.0
- Added install-stamp queue isolation: worker now sends only items queued during the current plugin install/upgrade cycle.
- Legacy queued items from previous installs/upgrades are quarantined and cannot auto-send after entering token/chat id.

## 1.7.9
- Added stale-queue quarantine on install/upgrade: legacy queued items are moved to `error` and are not auto-sent.
- Prevents accidental sending of old published posts right after entering token/chat id.

## 1.7.8
- Restored custom post type support reliability: row/bulk hooks are now registered on `init` after CPT registration.
- Removed request-local post-type list caching that could hide CPTs in settings/hook registration.

## 1.7.7
- Refactored repeated normalization logic into dedicated helpers for text limit and image source mode.
- Optimized queue worker query (`no_found_rows`, reduced cache work) and guaranteed lock release via `finally`.
- Added request-local caching for available/supported post types.

## 1.7.6
- Added image source mode setting: post image first with site fallback, post-only (no fallback), or site-only.
- Prevented automatic site-image substitution when post-only mode is selected.

## 1.7.5
- Removed remaining screenshot-oriented guidance from the Help tab flow; help content is text-only with Chat ID discovery table.

## 1.7.4
- Improved text truncation at character limit: now appends an ellipsis instead of hard cut-off.
- Switched length/truncation checks to explicit UTF-8 handling for safer multilingual text processing.

## 1.7.3
- Reduced automatic queue batch size from 5 to 1 to prevent burst sends.
- Made excerpt length dynamic based on configured text limit for more predictable output size.

## 1.7.2
- Removed Help tab screenshots completely; kept text instructions and Chat ID discovery only.

## 1.7.1
- Replaced Help tab screenshots with updated visuals matching user-provided references.

## 1.7.0
- Added configurable MAX post text length limit in admin settings (200..3900).

## 1.6.2
- Removed the first Help screenshot as requested; kept only two guidance images.

## 1.6.1
- Added embedded visual guide screenshots to the Help tab for token, bot/group setup, and Chat ID discovery.
- Included local help assets shown directly in admin panel.

## 1.6.0
- Added dedicated "Help" admin tab with guided setup steps for bot creation, group setup, and Chat ID discovery.
- Added token-based chat discovery to display available Chat IDs directly in admin panel.

## 1.5.2
- Added upgrade queue cutoff to prevent immediate flood of old queued items after plugin update.
- Requeueing now refreshes queue metadata and safely re-enables sending.

## 1.5.1
- Reworked checkboxes to select post types (including custom post types) instead of meta fields.
- Separated support contact block from referral banner in admin UI.

## 1.5.0
- Added checkbox-based custom field selection in admin settings.
- Embedded referral banner widget and support contact block in admin page.

## 1.4.1
- Improved queue auto-start reliability by triggering worker immediately after queueing.
- Added explicit handling for scheduled publications (`future -> publish`).

## 1.4.0
- Improved admin queue UI with quick actions (send now / queue now).
- Added admin action to queue all published content.
- Added support for public post types beyond posts (metabox, queue, row/bulk actions).

## 1.3.0
- Added support for publishing selected custom fields in the post text.
- Added admin settings for custom field mapping (`meta_key|Label`).

## 1.2.1
- Fix: upload step2 payload can be nested (e.g. {"photos":{...}}). Accept and pass full JSON to image.payload.

## 1.2.0
- Added inline button (inline_keyboard).
- Queue worker: cron lock + batch limit + retry/backoff.
- Metabox: disable autopost, override text, send now.
- Logs.
