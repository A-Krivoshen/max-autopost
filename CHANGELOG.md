# Changelog

## 1.2.1
- Fix: upload step2 payload can be nested (e.g. {"photos":{...}}). Accept and pass full JSON to image.payload.

## 1.2.0
- Added inline button (inline_keyboard).
- Queue worker: cron lock + batch limit + retry/backoff.
- Metabox: disable autopost, override text, send now.
- Logs.
