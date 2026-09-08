<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanText(mixed $value, int $maxLength): string {
    $text = trim((string) $value);
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return mb_substr($text, 0, $maxLength);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}

$raw = file_get_contents('php://input');
$request = json_decode($raw ?: '', true);
if (!is_array($request)) {
    respond(400, ['ok' => false, 'message' => 'Проверьте данные заказа.']);
}

$name = cleanText($request['name'] ?? '', 80);
$phone = cleanText($request['phone'] ?? '', 32);
$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
$method = $request['delivery_method'] ?? '';
$address = cleanText($request['address'] ?? '', 200);
$comment = cleanText($request['comment'] ?? '', 500);
$items = $request['items'] ?? [];

if (mb_strlen($name) < 2 || strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
    respond(422, ['ok' => false, 'message' => 'Укажите имя и корректный номер телефона.']);
}
if (!in_array($method, ['delivery', 'pickup'], true)) {
    respond(422, ['ok' => false, 'message' => 'Выберите способ получения заказа.']);
}
if ($method === 'delivery' && mb_strlen($address) < 5) {
    respond(422, ['ok' => false, 'message' => 'Укажите адрес доставки.']);
}
if (!is_array($items) || count($items) === 0 || count($items) > 30) {
    respond(422, ['ok' => false, 'message' => 'Корзина пуста. Добавьте блюда в заказ.']);
}

// The server is the single source of truth for dishes and prices.
$catalog = [
    'shurpa-lamb' => ['Шурпа из баранины', 400], 'shurpa-beef' => ['Шурпа из говядины', 400],
    'borscht' => ['Борщ из говядины', 350], 'kharcho' => ['Суп харчо из говядины', 350],
    'rice-soup' => ['Суп рисовый из говядины', 350], 'lagman-home' => ['Лагман домашний из говядины', 350],
    'lagman-uyghur' => ['Лагман уйгурский из говядины', 400], 'plov' => ['Плов по-ташкентски из говядины', 450],
    'kazan-kebab' => ['Казан-кебаб из баранины', 700], 'steamed-beef' => ['Мясо на пару из говядины', 600],
    'manty' => ['Манты из говядины (5 штук)', 400], 'fried-lagman' => ['Лагман жареный из говядины', 400],
    'lyulya' => ['Шашлык люля-кебаб из говядины', 300], 'lamb-pieces' => ['Шашлык кусковой из баранины', 400],
    'chicken-shashlik' => ['Шашлык из курицы', 280], 'lamb-shashlik' => ['Шашлык из баранины', 750],
    'vegetable-salad' => ['Салат овощной', 200], 'achichuk' => ['Салат ачичук', 250],
    'chaka' => ['Салат чака', 150], 'pickles' => ['Салат солёное ассорти', 250],
    'olivier' => ['Салат оливье', 250], 'korean-carrot' => ['Салат морковь по-корейски', 150],
    'tea-black' => ['Чай чёрный', 100], 'tea-green' => ['Чай зелёный', 100],
    'coffee' => ['Кофе растворимый три в одном', 50], 'compote-cherry' => ['Компот сухофрукты, вишня', 50],
    'compote-cherry-liter' => ['Компот сухофрукты, вишня (1 литр)', 250],
    'compote-orange' => ['Компот апельсин (200 грамм)', 50],
    'compote-orange-liter' => ['Компот апельсин (1 литр)', 250],
    'ayran' => ['Айран кисломолочный (200 грамм)', 60],
    'ayran-liter' => ['Айран кисломолочный (1 литр)', 250],
];

$orderLines = [];
$total = 0;
foreach ($items as $item) {
    $id = is_array($item) ? (string) ($item['id'] ?? '') : '';
    $quantity = is_array($item) ? (int) ($item['quantity'] ?? 0) : 0;
    if (!isset($catalog[$id]) || $quantity < 1 || $quantity > 20) {
        respond(422, ['ok' => false, 'message' => 'Проверьте состав корзины и повторите попытку.']);
    }
    [$dishName, $price] = $catalog[$id];
    $lineTotal = $price * $quantity;
    $total += $lineTotal;
    $orderLines[] = '• ' . $dishName . ' × ' . $quantity . ' — ' . number_format($lineTotal, 0, '.', ' ') . ' ₽';
}

if ($total <= 0 || $total > 100000) {
    respond(422, ['ok' => false, 'message' => 'Не удалось подтвердить сумму заказа.']);
}

$configPath = '/home/t/tiukhl2z/tiukhl2z.beget.tech/private/telegram_config.php';
if (!is_file($configPath)) {
    error_log('Telegram config file is unavailable.');
    respond(500, ['ok' => false, 'message' => 'Не удалось отправить заказ. Попробуйте ещё раз или позвоните в ресторан.']);
}
$config = require $configPath;
if (!is_array($config) || empty($config['bot_token']) || empty($config['chat_id'])) {
    error_log('Telegram config is invalid.');
    respond(500, ['ok' => false, 'message' => 'Не удалось отправить заказ. Попробуйте ещё раз или позвоните в ресторан.']);
}

$methodLabel = $method === 'delivery' ? 'Доставка' : 'Самовывоз';
$addressLabel = $method === 'delivery' ? $address : 'Самовывоз';
$message = "🍚 НОВЫЙ ЗАКАЗ\n\n"
    . "👤 Имя: {$name}\n"
    . "📞 Телефон: {$phone}\n"
    . "🚗 Получение: {$methodLabel}\n"
    . "📍 Адрес: {$addressLabel}\n\n"
    . "🛒 ЗАКАЗ:\n" . implode("\n", $orderLines) . "\n\n"
    . "💰 ИТОГО: " . number_format($total, 0, '.', ' ') . " ₽\n\n"
    . "💬 Комментарий: " . ($comment !== '' ? $comment : '—');

$endpoint = 'https://api.telegram.org/bot' . rawurlencode((string) $config['bot_token']) . '/sendMessage';
$payload = json_encode(['chat_id' => $config['chat_id'], 'text' => $message], JSON_UNESCAPED_UNICODE);
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
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 10, 'ignore_errors' => true]];
    $result = @file_get_contents($endpoint, false, stream_context_create($options));
}
$telegramResponse = json_decode($result ?: '', true);

if (!is_array($telegramResponse) || empty($telegramResponse['ok'])) {
    error_log('Telegram order request failed.');
    respond(502, ['ok' => false, 'message' => 'Не удалось отправить заказ. Попробуйте ещё раз или позвоните в ресторан.']);
}

respond(200, ['ok' => true, 'message' => 'Заказ принят! Мы получили ваш заказ и скоро свяжемся с вами для подтверждения.']);
