# MAX Autopost (Free) v1.4.1

Fix релиз: MAX upload step2 иногда возвращает payload не как `{token,url,type}`, а как вложенный объект, например `{"photos":{...}}`.  
Плагин теперь принимает **любой валидный JSON** и передаёт его целиком в `image.payload`.

## Что нового в 1.4.1
- Улучшена надежность авто-отправки: при постановке в очередь теперь принудительно триггерится worker (single event + loopback cron).
- Добавлен явный перехват перехода `future -> publish` для запланированных публикаций.

## Что нового в 1.4.0
- Обновлена админка очереди: быстрые действия «Отправить» и «В очередь» прямо в таблице.
- Добавлена массовая постановка в очередь всех опубликованных материалов.
- Поддержка страниц и других публичных типов записей (метабокс, queue, row/bulk actions).

## Что нового в 1.3.0
- Добавлена публикация кастомных полей в конце текста поста.
- Настройка маппинга через список `meta_key|Подпись` (или только `meta_key`).

## Features
- One message: image (optional) + text + button (optional)
- Attachments order: image first, button second
- WP-Cron queue + retry/backoff + cron lock
- Logs tab

## Install
Upload folder `max-autopost` into `/wp-content/plugins/`, activate.
