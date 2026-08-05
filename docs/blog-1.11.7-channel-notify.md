# MAX Autopost 1.11.7: почему тест писал «0/1 target» и как мы это починили

**Дата:** 5 августа 2026  
**Плагин:** [MAX Autopost (Free)](https://github.com/A-Krivoshen/max-autopost)  
**Релиз:** [v1.11.7](https://github.com/A-Krivoshen/max-autopost/releases/tag/v1.11.7)

---

## Симптом

После настройки Token и Chat ID кнопка **«Отправить тест»** показывала:

> Тест: Не удалось отправить ни в один target (0/1).

В логах плагина — одно и то же:

```text
HTTP 400: {"code":"proto.payload","message":"errors.send-message.channel-notify"}
```

Картинка, формат текста, кнопки «Читать» / «Подписаться» тут ни при чём: падал и plain text без вложений.

---

## Что на самом деле ломалось

Chat ID вида `-77027…` — это **канал** MAX, не личный диалог.

В настройках есть галочка **«Отправлять с notify (пуш подписчикам)»**.  
Если её снять, плагин отправляет в API:

```json
{ "text": "...", "notify": false }
```

Официально `notify=false` значит «отправить без пуша».  
На **каналах** MAX так делать нельзя: API отвечает `errors.send-message.channel-notify` и сообщение не публикуется.

Для групп и личных чатов silent-режим обычно работает. Ограничение — именно у каналов.

---

## Что изменилось в 1.11.7

### 1. Авто-retry (главное)

Если API вернул `channel-notify`, а в запросе был `notify=false`, плагин **один раз** повторяет отправку **без** поля `notify`.  
По документации MAX дефолт — `true` (с уведомлением). Такой запрос каналы принимают.

В логах появится шаг:

```text
notify_channel_retry … channel rejected notify=false; retry without notify field
```

Тест и обычная публикация перестают «молча» умирать на 0/1.

### 2. Понятная ошибка

Если после retry всё равно не удалось, notice больше не обрывается на «0/1».  
Пишем причину: канал отклонил silent, что проверить (галочка notify, права бота-админа).

### 3. Подсказка в настройках

Под галочкой notify — короткое описание ограничения каналов, чтобы не гадать по сырому JSON.

---

## Что сделать вам

1. Обновите плагин до **1.11.7** (автообновление из GitHub Releases или [скачать ZIP](https://github.com/A-Krivoshen/max-autopost/releases/latest)).
2. Для канала удобнее **оставить notify включённым** — пуш подписчикам как раз нужен.
3. Убедитесь, что бот — **администратор канала** с правом публиковать.
4. Нажмите **«Отправить тест»**.

Если silent на канале был принципиален: у MAX это сейчас не поддерживается API — обойти на стороне бота нельзя, только на стороне платформы.

---

## Для тех, кто копает глубже

| | |
| --- | --- |
| Endpoint | `POST https://platform-api2.max.ru/messages?chat_id=…` |
| Ошибка | `errors.send-message.channel-notify` |
| Триггер | body с `"notify": false` в **канал** |
| Фикс | retry без поля `notify` |
| Changelog | [CHANGELOG.md](https://github.com/A-Krivoshen/max-autopost/blob/main/CHANGELOG.md) |

Поддержка: `aleksey@krivoshein.site` · [Issues на GitHub](https://github.com/A-Krivoshen/max-autopost/issues)

---

*MAX Autopost (Free) — автопостинг записей WordPress в мессенджер MAX. MIT.*
