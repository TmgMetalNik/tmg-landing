#!/usr/bin/env python3
"""
TMG · Ежедневный отчёт по Яндекс.Директу в Telegram.
Берёт данные за вчера (по МСК), шлёт в @metrica12345_bot.

Запуск: cron 03:00 UTC = 06:00 MSK.
"""

import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

# ---------- Конфиг ----------
BASE_DIR = Path(__file__).resolve().parent
TOKEN_FILE = BASE_DIR / ".token"
LOG_FILE = Path("/var/log/tmg/daily-report.log")

DIRECT_API = "https://api.direct.yandex.com/json/v5/reports"
TG_API = "https://api.telegram.org/bot{token}/{method}"
MSK = timezone(timedelta(hours=3))


def load_secrets() -> dict:
    secrets = {}
    for line in TOKEN_FILE.read_text().splitlines():
        if "=" in line and not line.startswith("#"):
            k, v = line.split("=", 1)
            secrets[k.strip()] = v.strip()
    for k in ("YANDEX_DIRECT_TOKEN", "TG_BOT_TOKEN", "TG_CHAT_ID"):
        if k not in secrets:
            raise RuntimeError(f"Missing {k} in {TOKEN_FILE}")
    return secrets


def log(msg: str) -> None:
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    line = f"[{datetime.now(MSK).strftime('%Y-%m-%d %H:%M:%S')}] {msg}\n"
    LOG_FILE.open("a").write(line)
    print(line, end="")


def http_post(url: str, headers: dict, body: bytes, timeout: int = 60):
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    return urllib.request.urlopen(req, timeout=timeout)


def fetch_report(token: str, report_type: str, fields: list, date_from: str, date_to: str) -> str:
    """
    Вызывает Reports API. Уважает 201/202 (отчёт в очереди) — поллит до готовности.
    Возвращает TSV-строку (без заголовка/summary, только данные).
    """
    name = f"tmg-{report_type}-{date_from}-{int(time.time())}"
    payload = {
        "params": {
            "SelectionCriteria": {"DateFrom": date_from, "DateTo": date_to},
            "FieldNames": fields,
            "ReportName": name,
            "ReportType": report_type,
            "DateRangeType": "CUSTOM_DATE",
            "Format": "TSV",
            "IncludeVAT": "YES",
        }
    }
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept-Language": "ru",
        "Content-Type": "application/json; charset=utf-8",
        "skipReportHeader": "true",
        "skipReportSummary": "true",
        "processingMode": "auto",
        "returnMoneyInMicros": "false",
    }
    body = json.dumps(payload).encode("utf-8")

    for attempt in range(20):
        try:
            resp = http_post(DIRECT_API, headers, body)
        except urllib.error.HTTPError as e:
            err_body = e.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"Direct API HTTP {e.code}: {err_body[:500]}")
        status = resp.status
        if status == 200:
            return resp.read().decode("utf-8")
        if status in (201, 202):
            retry_in = int(resp.headers.get("retryIn", 10))
            log(f"  report {report_type}: queued (HTTP {status}), retryIn={retry_in}s")
            time.sleep(retry_in)
            continue
        raise RuntimeError(f"Unexpected HTTP {status} from Direct API")

    raise RuntimeError(f"Report {report_type} did not finish after 20 attempts")


def parse_tsv(tsv: str, fields: list) -> list[dict]:
    rows = []
    for line in tsv.strip().splitlines():
        if not line or line.startswith(fields[0]):  # пропускаем header, если вдруг просочился
            continue
        cells = line.split("\t")
        if len(cells) != len(fields):
            continue
        rows.append(dict(zip(fields, cells)))
    return rows


def fmt_rub(value) -> str:
    try:
        v = float(value)
    except (TypeError, ValueError):
        return "—"
    return f"{v:,.2f} ₽".replace(",", " ").replace(".", ",")


def fmt_int(value) -> str:
    try:
        v = int(float(value))
    except (TypeError, ValueError):
        return "—"
    return f"{v:,}".replace(",", " ")


def safe_div(num, den):
    try:
        n, d = float(num), float(den)
        return n / d if d > 0 else None
    except (TypeError, ValueError):
        return None


def build_message(date: str, campaigns: list[dict], queries: list[dict]) -> tuple[str, str | None]:
    """
    Возвращает (text, attachment_text):
    - text — основное сообщение для Telegram (HTML)
    - attachment_text — содержимое .txt файла для sendDocument, если запросов много (>40)
    """
    lines = []
    lines.append(f"📊 <b>Я.Директ — отчёт за {date}</b>")
    lines.append("")
    lines.append("<b>КАМПАНИИ</b>")

    if not campaigns:
        lines.append("  <i>нет данных</i>")
    else:
        total_cost = total_clicks = 0.0
        for c in campaigns:
            cost = float(c.get("Cost", 0) or 0)
            clicks = int(float(c.get("Clicks", 0) or 0))
            cpc = safe_div(cost, clicks)
            total_cost += cost
            total_clicks += clicks
            lines.append("")
            lines.append(f"• <b>{c.get('CampaignName','—')}</b>")
            lines.append(f"  💸 Расход: {fmt_rub(cost)}")
            lines.append(f"  🖱 Клики: {fmt_int(clicks)}")
            lines.append(f"  📐 Cpc: {fmt_rub(cpc) if cpc is not None else '—'}")
        if len(campaigns) > 1:
            avg_cpc = safe_div(total_cost, total_clicks)
            lines.append("")
            lines.append(f"<b>Итого:</b> {fmt_rub(total_cost)} · {fmt_int(total_clicks)} кл · Cpc {fmt_rub(avg_cpc) if avg_cpc is not None else '—'}")

    # ---- Поисковые запросы с кликами (по кампаниям) ----
    queries_with_clicks = [q for q in queries if int(float(q.get("Clicks", 0) or 0)) > 0]
    by_campaign: dict[str, list[dict]] = {}
    for q in queries_with_clicks:
        by_campaign.setdefault(q.get("CampaignName", "—"), []).append(q)

    lines.append("")
    lines.append("<b>ПОИСКОВЫЕ ЗАПРОСЫ С КЛИКАМИ</b>")
    if not queries_with_clicks:
        lines.append("  <i>нет кликов</i>")
        attachment = None
    else:
        attachment_lines = []
        compact_lines = []
        for camp, qs in by_campaign.items():
            qs.sort(key=lambda x: int(float(x.get("Clicks", 0) or 0)), reverse=True)
            attachment_lines.append(f"\n=== {camp} ===")
            compact_lines.append("")
            compact_lines.append(f"<i>{camp}:</i>")
            for q in qs:
                clicks = int(float(q.get("Clicks", 0) or 0))
                query = q.get("Query", "—")
                cost = float(q.get("Cost", 0) or 0)
                attachment_lines.append(f"  {clicks}× клик · {fmt_rub(cost)} · {query}")
                compact_lines.append(f"  • {query} ({clicks})")
        # Если запросов много, текст пихаем в файл, в сообщении — только сводка по числу
        if len(queries_with_clicks) > 40:
            lines.append(f"  всего: {len(queries_with_clicks)} запросов — см. вложение")
            attachment = "\n".join(attachment_lines).strip()
        else:
            lines.extend(compact_lines)
            attachment = None

    lines.append("")
    lines.append(f"🕐 Сформировано: {datetime.now(MSK).strftime('%Y-%m-%d %H:%M МСК')}")
    return "\n".join(lines), attachment


def tg_send_message(bot_token: str, chat_id: str, text: str) -> None:
    url = TG_API.format(token=bot_token, method="sendMessage")
    body = urllib.parse.urlencode({
        "chat_id": chat_id,
        "text": text,
        "parse_mode": "HTML",
        "disable_web_page_preview": "true",
    }).encode("utf-8")
    headers = {"Content-Type": "application/x-www-form-urlencoded"}
    resp = http_post(url, headers, body, timeout=15)
    if resp.status >= 300:
        raise RuntimeError(f"Telegram sendMessage HTTP {resp.status}")


def tg_send_document(bot_token: str, chat_id: str, filename: str, content: str, caption: str | None = None) -> None:
    """multipart/form-data, чтобы не тащить requests."""
    boundary = "----tmgreport" + str(int(time.time()))
    url = TG_API.format(token=bot_token, method="sendDocument")
    parts = []
    def field(name, value):
        parts.append(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode("utf-8"))
    field("chat_id", chat_id)
    if caption:
        field("caption", caption)
        field("parse_mode", "HTML")
    parts.append(
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"document\"; filename=\"{filename}\"\r\n"
        f"Content-Type: text/plain; charset=utf-8\r\n\r\n".encode("utf-8")
        + content.encode("utf-8")
        + f"\r\n--{boundary}--\r\n".encode("utf-8")
    )
    body = b"".join(parts)
    headers = {"Content-Type": f"multipart/form-data; boundary={boundary}"}
    resp = http_post(url, headers, body, timeout=30)
    if resp.status >= 300:
        raise RuntimeError(f"Telegram sendDocument HTTP {resp.status}")


def main() -> int:
    secrets = load_secrets()
    yesterday = (datetime.now(MSK) - timedelta(days=1)).strftime("%Y-%m-%d")
    log(f"Building daily report for {yesterday}")

    camp_fields = ["CampaignName", "CampaignId", "Impressions", "Clicks", "Cost"]
    q_fields = ["CampaignName", "CampaignId", "Query", "Impressions", "Clicks", "Cost"]

    try:
        camp_tsv = fetch_report(
            secrets["YANDEX_DIRECT_TOKEN"], "CAMPAIGN_PERFORMANCE_REPORT",
            camp_fields, yesterday, yesterday,
        )
        q_tsv = fetch_report(
            secrets["YANDEX_DIRECT_TOKEN"], "SEARCH_QUERY_PERFORMANCE_REPORT",
            q_fields, yesterday, yesterday,
        )
    except Exception as e:
        log(f"ERROR fetching reports: {e}")
        try:
            tg_send_message(
                secrets["TG_BOT_TOKEN"], secrets["TG_CHAT_ID"],
                f"⚠️ <b>Ошибка отчёта Я.Директ за {yesterday}</b>\n\n<code>{str(e)[:500]}</code>",
            )
        except Exception as tg_e:
            log(f"ERROR sending TG error notice: {tg_e}")
        return 1

    campaigns = parse_tsv(camp_tsv, camp_fields)
    queries = parse_tsv(q_tsv, q_fields)
    log(f"Parsed {len(campaigns)} campaign rows, {len(queries)} query rows")

    text, attachment = build_message(yesterday, campaigns, queries)

    try:
        tg_send_message(secrets["TG_BOT_TOKEN"], secrets["TG_CHAT_ID"], text)
        if attachment:
            tg_send_document(
                secrets["TG_BOT_TOKEN"], secrets["TG_CHAT_ID"],
                f"queries-{yesterday}.txt", attachment,
                caption=f"Поисковые запросы с кликами за {yesterday}",
            )
        log("Sent OK")
        return 0
    except Exception as e:
        log(f"ERROR sending to Telegram: {e}")
        return 2


if __name__ == "__main__":
    sys.exit(main())
