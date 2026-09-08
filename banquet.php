<?php
declare(strict_types=1);

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function textLength(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

function textSlice(string $text, int $maxLength): string {
    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
}

function cleanText(mixed $value, int $maxLength, bool $preserveLines = false): string {
    $text = trim((string) $value);
    if ($preserveLines) {
        $text = preg_replace('/[^\S\r\n]+/u', ' ', $text) ?? '';
        $text = preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $text) ?? '';
    } else {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    }
    return textSlice($text, $maxLength);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}

$raw = file_get_contents('php://input');
$request = json_decode($raw ?: '', true);
if (!is_array($request)) {
    respond(400, ['ok' => false, 'message' => 'Проверьте данные заявки.']);
}

$eventTypes = [
    'corporate' => 'Корпоратив',
    'birthday' => 'День рождения / юбилей',
    'family' => 'Семейный банкет',
    'wedding' => 'Свадьба',
    'other' => 'Другое',
];

$eventType = cleanText($request['event_type'] ?? '', 30);
$eventDateValue = cleanText($request['event_date'] ?? '', 10);
$eventTime = cleanText($request['event_time'] ?? '', 5);
$name = cleanText($request['name'] ?? '', 80);
$phone = cleanText($request['phone'] ?? '', 32);
$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
$comment = cleanText($request['comment'] ?? '', 1000, true);

if (!isset($eventTypes[$eventType])) {
    respond(422, ['ok' => false, 'message' => 'Выберите тип мероприятия.']);
}

$eventDate = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDateValue);
$dateErrors = DateTimeImmutable::getLastErrors();
if (
    !$eventDate ||
    ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
    $eventDate->format('Y-m-d') !== $eventDateValue ||
    $eventDate < new DateTimeImmutable('today')
) {
    respond(422, ['ok' => false, 'message' => 'Выберите сегодняшнюю или будущую дату мероприятия.']);
}

if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $eventTime)) {
    respond(422, ['ok' => false, 'message' => 'Укажите время начала мероприятия.']);
}

$guests = filter_var($request['guests'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 2, 'max_range' => 300],
]);
if ($guests === false) {
    respond(422, ['ok' => false, 'message' => 'Укажите количество гостей от 2 до 300.']);
}

if (textLength($name) < 2) {
    respond(422, ['ok' => false, 'message' => 'Укажите ваше имя.']);
}

$validPhone = strlen($phoneDigits) === 10 || (
    strlen($phoneDigits) === 11 && in_array($phoneDigits[0], ['7', '8'], true)
);
if (!$validPhone) {
    respond(422, ['ok' => false, 'message' => 'Введите корректный номер телефона.']);
}

$budgetValue = $request['budget'] ?? '';
$budget = null;
if ($budgetValue !== '' && $budgetValue !== null) {
    $budget = filter_var($budgetValue, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 500, 'max_range' => 50000],
    ]);
    if ($budget === false) {
        respond(422, ['ok' => false, 'message' => 'Укажите бюджет на человека от 500 до 50 000 ₽ или оставьте поле пустым.']);
    }
}

$configPath = '/home/t/tiukhl2z/tiukhl2z.beget.tech/private/telegram_config.php';
if (!is_file($configPath)) {
    error_log('Banquet Telegram config file is unavailable.');
    respond(500, ['ok' => false, 'message' => 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните в ресторан.']);
}

try {
    $config = require $configPath;
} catch (Throwable $error) {
    error_log('Banquet Telegram config could not be loaded.');
    respond(500, ['ok' => false, 'message' => 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните в ресторан.']);
}

if (!is_array($config) || empty($config['bot_token']) || empty($config['chat_id'])) {
    error_log('Banquet Telegram config is invalid.');
    respond(500, ['ok' => false, 'message' => 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните в ресторан.']);
}

$budgetLabel = $budget === null ? '—' : number_format($budget, 0, '.', ' ') . ' ₽';
$commentLabel = $comment !== '' ? $comment : '—';
$message = "🎉 НОВАЯ ЗАЯВКА НА БАНКЕТ\n\n"
    . "🏷 Тип мероприятия: {$eventTypes[$eventType]}\n"
    . "📅 Дата: {$eventDate->format('d.m.Y')}\n"
    . "🕐 Время: {$eventTime}\n"
    . "👥 Гостей: {$guests}\n\n"
    . "👤 Имя: {$name}\n"
    . "📞 Телефон: {$phone}\n\n"
    . "💰 Бюджет на человека: {$budgetLabel}\n\n"
    . "💬 Комментарий:\n{$commentLabel}";

$endpoint = 'https://api.telegram.org/bot' . rawurlencode((string) $config['bot_token']) . '/sendMessage';
$payload = json_encode([
    'chat_id' => $config['chat_id'],
    'text' => $message,
], JSON_UNESCAPED_UNICODE);

if (function_exists('curl_init')) {
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($curl);
    curl_close($curl);
} else {
    $options = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 10,
        'ignore_errors' => true,
    ]];
    $result = @file_get_contents($endpoint, false, stream_context_create($options));
}

$telegramResponse = json_decode($result ?: '', true);
if (!is_array($telegramResponse) || empty($telegramResponse['ok'])) {
    error_log('Banquet Telegram request failed.');
    respond(502, ['ok' => false, 'message' => 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните в ресторан.']);
}

respond(200, [
    'ok' => true,
    'message' => 'Мы получили информацию о мероприятии и скоро свяжемся с вами, чтобы обсудить меню, посадку и остальные детали.',
]);
