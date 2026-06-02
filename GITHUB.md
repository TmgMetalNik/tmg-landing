# GitHub для проекта TMG — бэкап и история версий

Зачем: после переезда на ноут сервер перестаёт быть твоим бэкапом. Приватный репозиторий = копия в облаке + откат любой правки + синк между компами.

---

## ЧАСТЬ A. Что сделать на стороне GitHub (НЕ на локальном компе)

Это делается один раз в браузере на github.com.

1. **Аккаунт.** Если нет — зарегистрируйся на https://github.com (бесплатного тарифа хватает, приватные репозитории на нём разрешены).

2. **Создать пустой приватный репозиторий:**
   - Справа вверху `+` → **New repository**.
   - Repository name: `tmg-landing`.
   - Видимость: **Private** ✅ (обязательно — в проекте бывают чувствительные данные).
   - **НЕ ставь** галочки «Add a README», «Add .gitignore», «license» — репозиторий должен быть пустой (у нас свои файлы).
   - **Create repository**.
   - Скопируй URL вида `https://github.com/ТВОЙ_ЛОГИН/tmg-landing.git` — пригодится на компе.

3. **Доступ для пуша с компа.** GitHub не пускает по паролю — нужен один из вариантов:
   - **Проще:** Personal Access Token. Settings (профиль) → Developer settings → Personal access tokens → **Tokens (classic)** → Generate new token → отметь scope **`repo`** → создай → **скопируй токен** (показывается один раз). При первом `git push` Windows спросит логин/пароль — вместо пароля вставь этот токен.
   - **Либо:** SSH-ключ (Settings → SSH and GPG keys → New SSH key), если предпочитаешь ключи.

На этом «не локальная» часть закончена.

---

## ЧАСТЬ B. Что сделать на компе (один раз)

В PowerShell в папке проекта:

```powershell
cd F:\programms\tmg-landing

# представиться git (один раз на компе)
git config --global user.name  "Твоё Имя"
git config --global user.email "твой@email"

# инициализировать репозиторий
git init
git branch -M main

# ВАЖНО: .gitignore уже в проекте — проверь, что секреты исключены
git status        # .mcp.json, local-setup/mcp.windows.json, .netrc, *.token быть НЕ должно

# первый коммит
git add .
git commit -m "Перенос проекта TMG на локальную разработку"

# привязать к GitHub и запушить
git remote add origin https://github.com/ТВОЙ_ЛОГИН/tmg-landing.git
git push -u origin main
```

При `git push` введи логин GitHub и вместо пароля — **Personal Access Token** из части A.

> ⚠️ Перед первым `git add .` ОБЯЗАТЕЛЬНО проверь `git status`: токены (`.mcp.json`, `mcp.windows.json`, `.token`, `.netrc`) НЕ должны попадать в список. Они уже в `.gitignore`, но лучше убедиться — однажды залитый в GitHub токен считается скомпрометированным.

---

## Дальше — обычный цикл работы

```powershell
# после правок
git add .
git commit -m "что изменил"
git push
```

- **Откат** к прошлой версии: `git log` (найти коммит) → `git checkout <хеш> -- файл`.
- **На другом компе**: `git clone https://github.com/ТВОЙ_ЛОГИН/tmg-landing.git`, потом доложить локально `.mcp.json` и билды MCP (их в репозитории нет — это секреты/тяжёлое).

---

## Что НЕ лежит в репозитории (специально)

Из-за `.gitignore` в GitHub не попадают: `.mcp.json` и `local-setup/mcp.windows.json` (токены), `.netrc`, `*.token`, `incoming/` (45 МБ сырья), `node_modules/`, `.playwright-mcp/`, бэкапы.
Это значит: GitHub хранит **код и контент сайта**, но не секреты и не тяжёлое сырьё. Билды MCP и токены при переезде на новый комп переносишь отдельно (см. `SETUP-LOCAL.md`).
