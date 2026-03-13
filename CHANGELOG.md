# Changelog

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
