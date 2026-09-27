<p align="center">
  <img src="./assets/readme/hero.svg" width="100%" alt="MAX Autopost — автопостинг из WordPress в MAX: картинка, текст и кнопка">
</p>

<p align="center">
  <a href="https://github.com/A-Krivoshen/max-autopost/releases/latest"><img src="https://img.shields.io/github/v/release/A-Krivoshen/max-autopost?style=flat-square&color=6D5EF7" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-21759B?style=flat-square" alt="WordPress 6.0+">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/license-MIT-3ECF8E?style=flat-square" alt="MIT License">
</p>

**MAX Autopost (Free)** — WordPress-плагин для автоматической отправки постов в [MAX](https://max.ru): одно сообщение с картинкой, текстом и кнопкой, очередь на WP-Cron и поддержка нескольких каналов.

---

## Что нового в 1.11.9

| | |
| --- | --- |
| **Проблема** | MAX API2 отвечает `400 proto.payload Can't deserialize body` на сырой JSON картинки → в канал уходит только текст или отправка падает. |
| **Фикс** | `image.payload` = `{token}` / `{url}`; fallback сначала с картинкой и кнопками; жирный заголовок через `<b>`. |
| **UX** | Больше не дергаем удалённый `GET /chats`, плашка не выглядит как ошибка SSL. |

Полная история: **[CHANGELOG.md](./CHANGELOG.md)** · релиз: **[v1.11.9](https://github.com/A-Krivoshen/max-autopost/releases/tag/v1.11.9)**.

---

## Что нового в 1.11.7

<p align="center">
  <img src="./assets/readme/release-1.11.7.svg" width="100%" alt="1.11.7: auto-retry при channel-notify, когда канал отклоняет notify=false">
</p>

| | |
| --- | --- |
| **Проблема** | Каналы MAX отклоняют silent (`notify=false`) с `errors.send-message.channel-notify` → тест «0/1 target». |
| **Фикс** | Авто-retry: один повтор **без** поля `notify` (дефолт API = уведомлять). |
| **UX** | Подсказка у галочки notify + понятный текст ошибки в notice. |

Полная история: **[CHANGELOG.md](./CHANGELOG.md)** · релиз: **[v1.11.7](https://github.com/A-Krivoshen/max-autopost/releases/tag/v1.11.7)**.
