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

## Возможности

<p align="center">
  <img src="./assets/readme/features.svg" width="100%" alt="Мультиканал, шаблоны, очередь, обновления с GitHub">
</p>

| | |
| --- | --- |
| **Мультиканал** | Один пост — сразу в несколько `chat_id` (каналы и группы). Ошибка в одном чате не останавливает остальные. |
| **Форматы текста** | `plain_text`, `formatted`, `excerpt_plain`, `title_only`; жирный заголовок; подпись и кнопки «Читать» / «Подписаться». |
| **Очередь** | WP-Cron worker, retry с backoff, lock, фильтры статусов, bulk-действия, безопасный старт после установки. |
| **Надёжность** | Корректный upload image (полный payload step2), fallback formatted→plain, soft-fail картинки → text-only. |
| **Обновления** | Автообновление из GitHub Releases (без WP.org). |
| **Shared-хостинг** | CA Минцифры дописывается к системному/WP bundle — HTTPS к MAX и CDN работает на типичных тарифах. |

---

## Как это работает

<p align="center">
  <img src="./assets/readme/workflow.svg" width="100%" alt="Публикация → очередь → сборка IMAGE/TEXT/BUTTON → доставка в chat_id">
</p>

1. **Публикация** — запись уходит в очередь при publish (сразу, по расписанию или вручную).
2. **Очередь** — воркер WP-Cron забирает задания по одному, с retry и lock.
3. **Сборка** — формируется одно сообщение MAX: `IMAGE` (если есть) + `TEXT` + inline-кнопки.
4. **Доставка** — последовательная отправка по всем target `chat_id`.

Подробности API и админки: **[Wiki](https://github.com/A-Krivoshen/max-autopost/wiki)**.

---

## Установка

### Быстрый путь (ZIP)

1. Скачайте [последний релиз](https://github.com/A-Krivoshen/max-autopost/releases/latest) (`max-autopost-*.zip`).
2. WordPress → **Плагины → Добавить новый → Загрузить плагин**.
3. Активируйте **MAX Autopost (Free)**.
4. **MAX Autopost → Настройки**: Token и Chat ID → **Отправить тест**.

### Из исходников

```bash
# в /wp-content/plugins/
git clone https://github.com/A-Krivoshen/max-autopost.git max-autopost
```

Активируйте плагин в админке и укажите Token / Chat ID.

### Token и Chat ID вне БД (рекомендуется)

В `wp-config.php`:

```php
define('KRV_MAX_TOKEN', 'your-bot-token');
define('KRV_MAX_CHAT_ID', 'your-chat-id');
```

Вкладка **Техпомощь** в плагине подскажет, как создать бота и найти Chat ID.

---

## Что умеет сообщение

Одно сообщение в MAX:

```text
[ IMAGE attachment ]   ← первый, полный payload после upload
[ TEXT ]               ← заголовок / тело / excerpt / title_only + подпись
[ INLINE BUTTON(s) ]   ← «Читать» и/или «Подписаться»
```

- Длина текста настраивается (200–3900 символов, потолок API 4000).
- Режим `formatted` нормализует WordPress HTML (whitelist тегов MAX); при ошибке API — fallback в plain.
- Источник картинки: из записи, только из записи, или всегда изображение сайта.

---

## Автообновление

Плагин обновляется через **GitHub Releases** (библиотека plugin-update-checker).  
Отдельная установка с WP.org не нужна — достаточно скачать ZIP один раз.

---

## Что нового в 1.11.6

- Исправлено склеивание жирного заголовка, URL, текста записи и подписи в MAX HTML.
- Неподдерживаемые MAX-теги `<br>` / `<p>` преобразуются в реальные переводы строк перед отправкой.
- Payload: документированный `format=html` (без `parse_mode`); сохранённые подписи с `<br>` продолжают работать.
- Поддерживаемые MAX-теги и содержимое `<pre>` сохраняются; лимит сообщения проверяется до отправки.

Полная история: **[CHANGELOG.md](./CHANGELOG.md)**.

---

## Требования

| | |
| --- | --- |
| WordPress | 6.0+ |
| PHP | 8.0+ |
| API | `platform-api2.max.ru` |
| Лицензия | [MIT](./LICENSE) |

---

## Документация и поддержка

- [Wiki](https://github.com/A-Krivoshen/max-autopost/wiki) — подробная документация
- [Releases](https://github.com/A-Krivoshen/max-autopost/releases) — ZIP и release notes
- [Issues](https://github.com/A-Krivoshen/max-autopost/issues) — баги и идеи
- Контакт: `aleksey@krivoshein.site`

---

## Для контрибьюторов

В git и PR допускаются **только** исходники, документация и текстовые конфиги.

- Не коммитить архивы, `zip`, `dist/` и build-артефакты.
- Не добавлять бинарные файлы.
- Релизный ZIP собирайте локально после merge, без коммита артефактов.

```
⭐ MIT License · https://github.com/A-Krivoshen/max-autopost
```
