# Changelog

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
