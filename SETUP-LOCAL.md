# Перенос проекта TMG на локальный компьютер (Windows) + VS Code + Claude Code

Инструкция, как развернуть проект на своём ноуте и работать так же, как на сервере: VS Code, Claude Code и 6 MCP (yandex-direct, yandex-webmaster, yandex-metrika, keyso, figma, playwright).

---

## Шаг 0. Что поставить один раз

1. **Node.js LTS** — https://nodejs.org (версия 20+). После установки проверь в PowerShell:
   ```powershell
   node -v
   npm -v
   ```
2. **VS Code** — https://code.visualstudio.com
3. **Git** — https://git-scm.com/download/win (понадобится для GitHub, шаг отдельной инструкции `GITHUB.md`).
4. *(опционально)* **Python 3.12** — только если будешь запускать агента Яндекс.Директа (`agents/yandex-direct/`). Для самой вёрстки не нужен.

---

## Шаг 1. Скачать проект с сервера

Самое простое — **WinSCP** (https://winscp.net):
1. New Site → протокол **SFTP**, Host `157.22.128.170`, порт `22`, User `root`, пароль от сервера.
2. Справа открой `/root/tmg-landing`, слева — твой диск `F:\programms\`.
3. Перетащи папку `tmg-landing` влево. Папку `incoming/` (45 МБ сырья) можно пропустить.

Либо одной командой в PowerShell:
```powershell
scp -r root@157.22.128.170:/root/tmg-landing "F:\programms\tmg-landing"
```

Итог: проект лежит в `F:\programms\tmg-landing`.

---

## Шаг 2. Скачать билды MCP с сервера

4 кастомных MCP — это готовые Node-сборки на сервере. Скопируй их в `F:\programms\mcp\` (тем же WinSCP из `/srv/mcp-servers/`):

| Скопировать с сервера | Куда на компе |
|---|---|
| `/srv/mcp-servers/yandex-direct-mcp` | `F:\programms\mcp\yandex-direct-mcp` |
| `/srv/mcp-servers/yandex-webmaster-mcp` | `F:\programms\mcp\yandex-webmaster-mcp` |
| `/srv/mcp-servers/yandex-metrika-mcp` | `F:\programms\mcp\yandex-metrika-mcp` |
| `/srv/mcp-servers/keyso` | `F:\programms\mcp\keyso` |

Каждая папка ~50 МБ (внутри уже есть `node_modules`).
> Если какой-то MCP потом не стартует (ошибка про модули) — открой его папку в PowerShell и выполни `npm install`. Это пересоберёт зависимости под Windows.

**figma и playwright копировать НЕ надо** — они ставятся автоматически через `npx` при первом запуске. Только для playwright один раз скачай браузер:
```powershell
npx playwright install chromium
```

---

## Шаг 3. Подключить конфиг MCP

В пакете уже лежит готовый конфиг с твоими токенами и путями `F:\programms\mcp\...`:
`local-setup\mcp.windows.json`

1. Скопируй его в корень проекта и **переименуй в `.mcp.json`** (заменив старый серверный):
   ```powershell
   copy "F:\programms\tmg-landing\local-setup\mcp.windows.json" "F:\programms\tmg-landing\.mcp.json"
   ```
2. Если билды положил не в `F:\programms\mcp\`, а в другое место — открой `.mcp.json` и поправь пути в `args`.

> `.mcp.json` содержит API-ключи и в GitHub НЕ попадёт — он в `.gitignore`.

---

## Шаг 4. Развернуть Claude Code (пункт 2.1)

1. Установи Claude Code глобально:
   ```powershell
   npm install -g @anthropic-ai/claude-code
   ```
2. Установи расширение **Claude Code** в VS Code (вкладка Extensions → найди «Claude Code» от Anthropic → Install).
3. В VS Code: **File → Open Folder** → `F:\programms\tmg-landing`.
4. Открой встроенный терминал (`Ctrl+` `) и запусти:
   ```powershell
   claude
   ```
5. При первом запуске:
   - **Войди в аккаунт** (`/login`) — той же учёткой Anthropic, что используешь на сервере.
   - Claude спросит **доверять ли папке** (Trust this folder) → **Yes**. Без этого `.mcp.json` не подхватится.
   - Claude найдёт `.mcp.json` и спросит разрешение на запуск MCP-серверов → разреши нужные.
6. Проверь, что MCP поднялись:
   ```
   /mcp
   ```
   Должны увидеть 6 серверов в статусе connected: yandex-direct, yandex-webmaster, yandex-metrika, keyso, figma, playwright.

> Отличие от сервера: тут нет «managed» enterprise-конфига, поэтому MCP подключаются прямо из `.mcp.json` проекта. Так даже проще — никаких ограничений.

---

## Шаг 5. Деплой на хостинг с компа

Сервер для деплоя больше не нужен — заливаешь прямо с ноута через **WinSCP**:
1. New Site → протокол **FTP**, шифрование **TLS/SSL Explicit**.
2. Host `server273.hosting.reg.ru`, User `u3495982`, пароль (он лежал в `/root/.netrc` на сервере — перепиши себе заранее).
3. Корень сайта — `www/theosmg.ru/`. Перетаскиваешь изменённые файлы (index.html, styles.css и т.д.).

Подробные правила деплоя — в `DEVELOPMENT.md`.

---

## Готово

Теперь весь цикл — правки в VS Code → Claude Code с MCP → деплой на хостинг — работает локально, без рабочего сервера.

**Когда убедишься, что всё работает локально** — можно удалить проект с сервера (`/root/tmg-landing`) и его project-scoped `.mcp.json`, чтобы хобби не висело на рабочей машине.
