# Changelog

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
