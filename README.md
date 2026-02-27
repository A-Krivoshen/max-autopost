# MAX Autopost (Free) v1.2.1

Fix релиз: MAX upload step2 иногда возвращает payload не как `{token,url,type}`, а как вложенный объект, например `{"photos":{...}}`.  
Плагин теперь принимает **любой валидный JSON** и передаёт его целиком в `image.payload`.

## Features
- One message: image (optional) + text + button (optional)
- Attachments order: image first, button second
- WP-Cron queue + retry/backoff + cron lock
- Logs tab

## Install
Upload folder `max-autopost` into `/wp-content/plugins/`, activate.
