<?php
/**
 * TMG · обработчик форм с сайта theosmg.ru
 * Принимает POST, валидирует, отправляет email через Я.Почту (SMTP) и в Telegram,
 * возвращает JSON. Содержит honeypot, rate-limit и persistent-лог всех попыток.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------- CORS ----------
$allowed_origins = ['https://theosmg.ru', 'https://www.theosmg.ru'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// ---------- Принимаем JSON или form-data ----------
$raw = file_get_contents('php://input');
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $data = json_decode($raw, true) ?: [];
} else {
    $data = $_POST;
}

// ---------- Извлекаем поля ДО honeypot — чтобы залогировать контакт,
//             даже если сработает honeypot (lead не теряется) ----------
$source  = trim((string)($data['source']  ?? ''));
$name    = trim((string)($data['name']    ?? ''));
$phone   = trim((string)($data['phone']   ?? ''));
$email   = trim((string)($data['email']   ?? ''));
$message = trim((string)($data['message'] ?? ''));
$product = trim((string)($data['product'] ?? ''));

$max = ['name' => 200, 'phone' => 50, 'email' => 200, 'message' => 4000, 'product' => 100, 'source' => 50];
foreach ($max as $k => $lim) {
    if (isset($$k) && strlen($$k) > $lim) { $$k = mb_substr($$k, 0, $lim); }
}

// Берём ТОЛЬКО REMOTE_ADDR — заголовку X-Forwarded-For не доверяем,
// его легко подменить и обойти rate-limit.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Honeypot-поля (имена обфусцированы — чтобы автозаполнятели браузеров не трогали).
// Старые имена hp_url / hp_company провоцировали autofill на мобильниках и
// блокировали реальных пользователей.
$hp_a = (string)($data['__tk_a'] ?? '');
$hp_b = (string)($data['__tk_b'] ?? '');

// Время от загрузки страницы до сабмита формы (миллисекунды).
// Передаётся клиентским JS. Боты, бьющие напрямую в POST /send.php
// без рендера страницы, либо вообще его не присылают, либо присылают близкое к 0.
$open_ms = (int)($data['_t_open_ms'] ?? 0);

// ---------- Persistent-лог: пишем КАЖДУЮ попытку (включая honeypot) в JSONL.
//             Файл в _lib/ закрыт .htaccess (Require all denied), наружу не светится. ----------
$LOG_FILE = __DIR__ . '/_lib/form-log.jsonl';
function tmg_log_form(string $outcome, array $extra = []): void {
    global $LOG_FILE, $source, $name, $phone, $email, $product, $message, $ip, $hp_a, $hp_b;
    try {
        $entry = array_merge([
            'ts'      => date('Y-m-d H:i:s'),
            'outcome' => $outcome,
            'source'  => $source,
            'name'    => $name,
            'phone'   => $phone,
            'email'   => $email,
            'product' => $product,
            'message' => $message,
            'ip'      => $ip,
            'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'hp_a'    => $hp_a,
            'hp_b'    => $hp_b,
        ], $extra);
        @file_put_contents($LOG_FILE, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $log_e) {
        // лог не должен ломать форму
    }
}

// ---------- Honeypot ----------
if ($hp_a !== '' || $hp_b !== '') {
    tmg_log_form('honeypot');
    // отвечаем "успех", чтобы бот не повторял (и чтобы UX реального юзера не сломать,
    // если это автозаполнятель — заявку поднимем из лога)
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Time-based bot check ----------
// Человек не успевает заполнить и отправить форму быстрее 2 сек.
// Если _t_open_ms отсутствует (прямой POST без рендера страницы) — тоже бот.
if ($open_ms < 2000) {
    tmg_log_form('time_too_fast', ['open_ms' => $open_ms]);
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Валидация ----------
if ($phone === '') {
    tmg_log_form('validation_failed', ['error' => 'phone_required']);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'phone_required']);
    exit;
}
// engineer-форма не имеет email
$require_email = $source !== 'engineer';
if ($require_email && $email === '') {
    tmg_log_form('validation_failed', ['error' => 'email_required']);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'email_required']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    tmg_log_form('validation_failed', ['error' => 'email_invalid']);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'email_invalid']);
    exit;
}

// ---------- Rate-limit: max 5 заявок с одного IP в минуту ----------
// Храним счётчик в _lib/ (закрыто .htaccess, наружу не светится).
// Раньше использовали sys_get_temp_dir() — на shared-хостинге /tmp
// нестабильно очищается, rate-limit не считался.
$rate_dir = __DIR__ . '/_lib/rate';
if (!is_dir($rate_dir)) { @mkdir($rate_dir, 0700, true); }
$rate_file = $rate_dir . '/' . md5($ip);
$now = time();
$rate = ['count' => 0, 'reset' => $now + 60];
if (is_file($rate_file)) {
    $stored = @json_decode((string)@file_get_contents($rate_file), true);
    if (is_array($stored) && isset($stored['reset']) && $stored['reset'] > $now) {
        $rate = $stored;
    }
}
$rate['count']++;
@file_put_contents($rate_file, json_encode($rate));
if ($rate['count'] > 5) {
    tmg_log_form('rate_limit');
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limit']);
    exit;
}

// ---------- Отправка ----------
$config = require __DIR__ . '/_lib/config.php';
require __DIR__ . '/_lib/PHPMailer/Exception.php';
require __DIR__ . '/_lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/_lib/PHPMailer/SMTP.php';

$form_labels = [
    'popup-1'     => 'Получить консультацию',
    'popup-2'     => 'Спросить инженера (header)',
    'popup-3'     => 'Заявка по товару',
    'popup-order' => 'Заказ СОЖ',
    'lead'        => 'Полная форма «Подберём СОЖ»',
    'engineer'    => 'Инженер подберёт',
];
$form_label = $form_labels[$source] ?? ('Форма: ' . ($source ?: 'unknown'));

try {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $config['smtp_host'];
    $mail->Port       = $config['smtp_port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp_user'];
    $mail->Password   = $config['smtp_pass'];
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;

    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($config['to_email']);
    if ($email !== '') {
        $mail->addReplyTo($email, $name !== '' ? $name : $email);
    }

    $mail->Subject = 'Заявка с theosmg.ru — ' . $form_label;
    $mail->isHTML(true);

    // ---- Главные поля заявки ----
    $main_rows = [];
    $main_rows[] = ['Источник', $form_label];
    $main_rows[] = ['Имя',      $name !== '' ? $name : '—'];
    $main_rows[] = ['Телефон',  $phone];
    if ($email   !== '') $main_rows[] = ['Почта', $email];
    if ($product !== '') $main_rows[] = ['Товар', $product];
    if ($message !== '') $main_rows[] = ['Сообщение', $message];

    // ---- Технический блок ----
    $tech_rows = [];
    $tech_rows[] = ['Время',       date('Y-m-d H:i:s')];
    $tech_rows[] = ['IP',          $ip];
    $tech_rows[] = ['User-Agent',  $_SERVER['HTTP_USER_AGENT'] ?? '—'];
    $tech_rows[] = ['Referer',     $_SERVER['HTTP_REFERER']    ?? '—'];

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $build_table = function($rows, $label_color, $value_color, $font_size) use ($esc) {
        $html = '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">';
        foreach ($rows as [$k, $v]) {
            $html .= '<tr>'
                  . '<td style="padding:6px 18px 6px 0;vertical-align:top;color:' . $label_color . ';font-size:' . $font_size . 'px;white-space:nowrap;">' . $esc($k) . ':</td>'
                  . '<td style="padding:6px 0;color:' . $value_color . ';font-size:' . $font_size . 'px;line-height:1.5;">' . nl2br($esc($v)) . '</td>'
                  . '</tr>';
        }
        $html .= '</table>';
        return $html;
    };

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
    $html .= '<body style="margin:0;padding:24px;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">';
    $html .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;border:1px solid #e8e8e8;">';
    $html .= '<h2 style="margin:0 0 24px;font-size:20px;color:#B92D38;">Новая заявка с theosmg.ru</h2>';
    $html .= $build_table($main_rows, '#888', '#222', 15);
    $html .= '<hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">';
    $html .= '<div style="font-size:12px;color:#999;margin-bottom:8px;">Технические данные:</div>';
    $html .= $build_table($tech_rows, '#aaa', '#666', 12);
    $html .= '</div></body></html>';

    // Plain-text fallback
    $alt_lines = [];
    foreach ($main_rows as [$k, $v]) $alt_lines[] = $k . ': ' . $v;
    $alt_lines[] = '';
    $alt_lines[] = '--- Технические данные ---';
    foreach ($tech_rows as [$k, $v]) $alt_lines[] = $k . ': ' . $v;

    $mail->Body    = $html;
    $mail->AltBody = implode("\r\n", $alt_lines);

    $mail->send();

    // ---- Telegram, параллельный канал (не блокирует ответ) ----
    $tg_ok = false;
    try {
        $tg_ok = tmg_send_telegram($form_label, $name, $phone, $email, $product, $message, $_SERVER['HTTP_REFERER'] ?? '');
    } catch (\Throwable $tg_e) {
        error_log('[TMG-form] telegram failed: ' . $tg_e->getMessage());
    }

    tmg_log_form('ok', ['telegram_ok' => $tg_ok]);

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    tmg_log_form('send_failed', ['error' => $e->getMessage()]);
    error_log('[TMG-form] send failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'send_failed',
    ]);
}

function tmg_send_telegram(
    string $form_label,
    string $name,
    string $phone,
    string $email,
    string $product,
    string $message,
    string $referer
): bool {
    if (!defined('TG_BOT_TOKEN') || !defined('TG_CHAT_ID')) return false;
    $token = TG_BOT_TOKEN;
    $chat  = TG_CHAT_ID;
    if ($token === '' || $chat === '') return false;

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $lines = [];
    $lines[] = '🔔 <b>Новая заявка с сайта</b>';
    $lines[] = '';
    $lines[] = '📋 <b>Форма:</b> ' . $esc($form_label);
    $lines[] = '👤 <b>Имя:</b> '    . $esc($name !== '' ? $name : '—');
    $lines[] = '📞 <b>Телефон:</b> '. $esc($phone);
    if ($email   !== '') $lines[] = '📧 <b>Email:</b> '    . $esc($email);
    if ($product !== '') $lines[] = '📦 <b>Продукт:</b> '  . $esc($product);
    if ($message !== '') $lines[] = '💬 <b>Сообщение:</b> '. $esc($message);
    $lines[] = '';
    $lines[] = '🕐 ' . $esc(date('Y-m-d H:i:s'));
    $lines[] = '🌐 ' . $esc($referer !== '' ? $referer : '—');

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = [
        'chat_id'                  => $chat,
        'text'                     => implode("\n", $lines),
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 300) {
        error_log('[TMG-form] telegram api failed: code=' . $code
            . ' err=' . $err
            . ' resp=' . substr((string)$resp, 0, 500));
        return false;
    }
    return true;
}
