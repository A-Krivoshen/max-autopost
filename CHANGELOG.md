# Changelog
## 1.11.9
- Fix: image.payload больше не сырой JSON step2 (`{"photos":{...}}`) — API2 отвечал HTTP 400 proto.payload «Can't deserialize body», в MAX уходил только текст или отправка падала целиком.
- image.payload теперь только `{token}` (токен из `photos.*.token`) или `{url}`.
- Fallback: сначала повтор без `format=html`, но С картинкой и кнопками; text-only — второй запасной путь. Срабатывает и для plain+вложения, не только для HTML.
- Жирный заголовок в MAX уходит как `<b>`, не `<strong>`.
- UX: плагин больше не вызывает удалённый GET /chats; плашка в «Техпомощи» не маскируется под ошибку SSL.
## 1.11.8
- Fix: process_queue больше не подхватывает sticky_posts (ignore_sticky_posts + guard status=queued)
- Perf: dedupe по sent_hash выполняется до upload картинки
- Append text: разрешены <b>/<strong> (в т.ч. внутри <a>)
