# Changelog
## 1.11.9
- Fix: image.payload больше не сырой JSON step2 (`{"photos":{...}}`) — API2 отвечал HTTP 400 proto.payload «Can't deserialize body», в MAX уходил только текст или отправка падала целиком.
- image.payload теперь только `{token}` (токен из `photos.*.token`) или `{url}`.
- Fallback: сначала повтор без `format=html`, но С картинкой и кнопками; text-only — второй запасной путь. Срабатывает и для plain+вложения, не только для HTML.
- Жирный заголовок в MAX уходит как `<b>`, не `<strong>`.
- UX: плагин больше не вызывает удалённый GET /chats; плашка в «Техпомощи» не маскируется под ошибку SSL.
