<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/cz.class.php';
require_once __DIR__ . '/functions.php'; // если тут нет ничего нужного — можно убрать

// ====================== helpers ======================

/**
 * Парсинг «сырая строка сканера» → [barcode, kiz]
 * - Если встречается GTIN (01######14) и следом серия (21...), считаем это КИЗ и возвращаем сырой КИЗ во второй части
 * - Иначе пробуем вытащить «цифровой штрихкод»
 * - Иначе кладём всё в barcode как текст
 */
function parse_barcode_and_kiz(string $raw): array {
    $s = trim($raw);
    if ($s === '') return [null, null];

    // GTIN14 + SERIAL (КИЗ). Допускаем (01) и (21), GS 0x1D
    $s_no_gs = preg_replace("/[\x1D\r\n]+/", "", $s);
    if (preg_match('/(?:\(?01\)?\s*)(\d{14})(.*)$/', $s_no_gs, $m)) {
        $tail = $m[2] ?? '';
        if ($tail !== '' && preg_match('/(?:\(?21\)?\s*)(.+)$/', $tail)) {
            // это КИЗ — возвращаем весь «сырой» как kiz
            return [null, $s];
        }
    }

    // Просто цифровой штрихкод
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits !== '') {
        if (strlen($digits) === 14 && $digits[0] === '0') $digits = substr($digits, 1);
        return [$digits, null];
    }

    // Нецифровой код — сохраняем как баркод (как есть)
    return [$s, null];
}

/**
 * Нормализация CIS:
 *  - находим (01)GTIN14, затем (21), и РОВНО 12 символов серии ПОСЛЕ 21
 *  - ничего не "чистим" заранее (чтобы не сдвигать границы)
 *  - поддерживаем варианты с/без скобок вокруг 01/21 и с пробелами
 */
function normalize_cis_12(string $raw): ?string {
    $s = (string)$raw;

    // 1) найти (01) + 14 цифр
    if (!preg_match('/(?:\(?01\)?\s*)(\d{14})/s', $s, $m01, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $gtin = $m01[1][0]; // сами 14 цифр
    $pos_after_01 = $m01[0][1] + strlen($m01[0][0]);

    // 2) найти (21) после (01)
    if (!preg_match('/(?:\(?21\)?\s*)/s', $s, $m21, PREG_OFFSET_CAPTURE, $pos_after_01)) {
        return null;
    }
    $serial_start = $m21[0][1] + strlen($m21[0][0]);

    // 3) взять РОВНО 12 байт серии (включая любые символы, напр. " и ')
    $serial12 = substr($s, $serial_start, 13);
 
    if ($serial12 === '' || strlen($serial12) < 13) {
        return null; // мало символов после 21
    }

    return '01' . $gtin . '21' . $serial12;
}

/** Нормализация даты из ЧЗ к формату DATE 'Y-m-d' */
function normalize_expiration_date(?string $in): ?string {
    if (!$in) return null;
    $s = trim($in);
    if ($s === '') return null;

    // ISO 8601 или yyyy-mm-dd
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
        try { $dt = new DateTime($s); return $dt->format('Y-m-d'); } catch (Throwable $e) { return null; }
    }
    // dd.mm.yyyy
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $s)) {
        $parts = explode('.', $s);
        return sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
    }
    // Всё остальное — пробуем через DateTime
    try { $dt = new DateTime($s); return $dt->format('Y-m-d'); } catch (Throwable $e) { return null; }
}

// ====================== 1) Подключаемся к БД ======================

try {
    $pdo = mysql_pdo_connect($mysql);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'MySQL CONNECT ERROR: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$table = $mysql['table_shipments'] ?? 'shipments_registry';

// ====================== 2) Создаём таблицу, если её нет ======================

$createSql = "
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shipment_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `order_id` CHAR(36) NOT NULL,
  `order_number` VARCHAR(64) NOT NULL,
  `assort_id` CHAR(36) NULL,
  `item_name` VARCHAR(255) NULL,
  `barcode` VARCHAR(128) NOT NULL,
  `is_marked` TINYINT(1) NOT NULL DEFAULT 0,
  `kiz` TEXT NULL,
  `price` DECIMAL(12,2) NULL,
  `payment_method` VARCHAR(255) NULL,
  `withdrawn_date` DATETIME NULL,
  `expiration_date` DATE NULL,        -- << срок годности
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_assort_id` (`assort_id`),
  KEY `idx_barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$pdo->exec($createSql);


// ====================== 3) Принимаем JSON ======================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || ($payload['action'] ?? '') !== 'save_scans') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================== 4) Достаём данные заказа ======================

$order = $payload['order'] ?? [];
$orderId = (string) ($order['id'] ?? '');
$orderNumber = (string) ($order['number'] ?? '');
$paymentMethod = (string) ($order['paymentMethod'] ?? '');
$withdrawnDate = $order['withdrawnDate'] ?? null; // ожидаем 'Y-m-d H:i:s' (как вы передаёте из PHP)

if (!preg_match('/^[0-9a-fA-F-]{36}$/', $orderId) || $orderNumber === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Некорректные данные заказа'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================== 5) Валидация: все слоты должны быть заполнены ======================

$items = $payload['items'] ?? [];
foreach ($items as $item) {
    $slots = (int) ($item['slots'] ?? 0);
    $filled = array_values(array_filter(array_map('trim', $item['scans'] ?? []), fn($v) => $v !== ''));
    if ($slots > 0 && count($filled) < $slots) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Не все поля заполнены'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// === Блокируем повторное сохранение этого заказа ===
try {
    $st = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE order_id = :oid LIMIT 1");
    $st->execute([':oid' => $orderId]);
    if ($st->fetchColumn()) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'Этот заказ уже был сохранён ранее. Повторное сохранение запрещено.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error (check order exists): ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================== 6) Собираем КИЗ (нормализуем до 12) и дёргаем ЧЗ ======================

$cz_debug = [
    'http_code'   => null,
    'sent'        => [],
    'raw_to_norm' => [],
    'raw_response'=> null,
    'exp_by_cis'  => []
];

// Получим токен из SQLite (если нет — просто не вызываем ЧЗ, но данные всё равно сохраним)
$czToken = null;
try {
    $cz = new CzClient($cz_config, $database);
    $czToken = $cz->getStoredToken(); // string|null
} catch (Throwable $e) {
    // в debug отдадим, но не упадём
    $cz_debug['error'] = 'CZ init: ' . $e->getMessage();
}

$kiz_for_api_set = [];
$kiz_debug_map   = []; // raw -> normalized

foreach ($items as $item) {
    $isMarked = !empty($item['is_marked']) || !empty($item['isMarked']);
    if (!$isMarked) continue;

    foreach (($item['scans'] ?? []) as $rawScan) {
        $raw = trim(str_replace(["\r","\n"], '', (string)$rawScan));
        if ($raw === '') continue;

        // определим, это КИЗ или нет
        [, $maybeKiz] = parse_barcode_and_kiz($raw);
        if ($maybeKiz) {
            $norm = normalize_cis_12($maybeKiz);
            $kiz_debug_map[$raw] = $norm;
            if ($norm) $kiz_for_api_set[$norm] = true; // уникальность
        }
    }
}

$kiz_for_api = array_keys($kiz_for_api_set);
$cz_debug['sent'] = $kiz_for_api;
$cz_debug['raw_to_norm'] = $kiz_debug_map;

$exp_by_cis = []; // cis(normalized12) => expirationDate(Y-m-d)|null

if ($czToken && !empty($kiz_for_api)) {
    $host  = rtrim($cz_config['base_url'] ?? 'https://markirovka.crpt.ru', '/');
    $pref  = '/' . trim($cz_config['api_prefix'] ?? '/api/v3/true-api', '/');
    $url   = $host . $pref . '/cises/info';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => 'gzip,deflate',
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Authorization: Bearer ' . $czToken,
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($kiz_for_api, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $cz_raw  = curl_exec($ch);
    $cz_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cz_err  = curl_error($ch);
    curl_close($ch);

    $cz_debug['http_code'] = $cz_http;

    if ($cz_raw === false || ($cz_http < 200 || $cz_http >= 300)) {
        $cz_debug['raw_response'] = [
            'error'     => "cises/info HTTP {$cz_http}: " . ($cz_raw ?: $cz_err),
            'requested' => $kiz_for_api,
        ];
    } else {
        $cz_json = json_decode((string)$cz_raw, true);
        if (!is_array($cz_json)) {
            $cz_debug['raw_response'] = ['raw' => (string)$cz_raw];
        } else {
            $cz_debug['raw_response'] = $cz_json;
            // разбор expirationDate
            foreach ($cz_json as $row) {
                $cis = null; $exp = null;

                // cis / requestedCis
                if (isset($row['cisInfo']['requestedCis']))       $cis = (string)$row['cisInfo']['requestedCis'];
                elseif (isset($row['cis']))                        $cis = (string)$row['cis'];

                // возможные места срока годности
                if (isset($row['cisInfo']['expirationDate']))                               $exp = (string)$row['cisInfo']['expirationDate'];

                if ($cis !== null) {
                    $exp_by_cis[$cis] = normalize_expiration_date($exp);
                }
            }
        }
    }
}
$cz_debug['exp_by_cis'] = $exp_by_cis;

// ====================== 7) Запись строк в БД ======================

// === Сбор всех КИЗ из входных данных и проверка, что они не встречались в других заказах ===
try {
    // Собираем уникальные КИЗ из items
    $kizSet = [];
    foreach ($items as $item) {
        foreach (($item['scans'] ?? []) as $raw) {
            $raw = (string)$raw;
            [, $kizRaw] = parse_barcode_and_kiz($raw);
            if (!$kizRaw) continue;
            // нормализуем до '01....21<12>'
            $norm = normalize_cis_12($kizRaw);
            // если нормализация не удалась — тоже считаем это значимым значением (берём raw)
            $key = $norm ?: $kizRaw;
            $kizSet[$key] = $kizRaw; // храним raw, чтобы красиво вернуть в ошибке
        }
    }

    if (!empty($kizSet)) {
        $check = $pdo->prepare("SELECT order_id, order_number, kiz FROM `{$table}` WHERE kiz = :kiz AND order_id <> :oid LIMIT 1");
        foreach ($kizSet as $normOrRaw => $rawOriginal) {
            // В БД мы кладём в поле `kiz` именно raw КИЗ (ниже при вставке),
            // поэтому ищем по raw-значению (если нормализовали — ищем всё равно rawOriginal).
            $check->execute([':kiz' => $rawOriginal, ':oid' => $orderId]);
            $conflict = $check->fetch(PDO::FETCH_ASSOC);
            if ($conflict) {
                http_response_code(409);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Данный КИЗ был сохранён в другом заказе',
                    'details' => [
                        'kiz' => $rawOriginal,
                        'conflict_order_id' => $conflict['order_id'] ?? null,
                        'conflict_order_number' => $conflict['order_number'] ?? null,
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error (check KIZ uniqueness): ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}


try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare("
        INSERT INTO `{$table}`
        (shipment_date, order_id, order_number, assort_id, item_name,
         barcode, is_marked, kiz, price, payment_method, withdrawn_date, expiration_date)
        VALUES
        (NOW(), :order_id, :order_number, :assort_id, :item_name,
         :barcode, :is_marked, :kiz, :price, :payment_method, :withdrawn_date, :expiration_date)
    ");

    $saved = 0;
    $rows_for_gsheet = []; // Сюда соберём строки для Google Sheets (если включено)

    foreach ($items as $item) {
        $price     = (float) ($item['priceRub'] ?? 0);
        $isMarked  = !empty($item['is_marked']) || !empty($item['isMarked']) ? 1 : 0;
        $assortId  = (string) ($item['assortId'] ?? '');
        $itemName  = (string) ($item['name'] ?? '');
        $slots     = (int) ($item['slots'] ?? 0);

        foreach (($item['scans'] ?? []) as $raw) {
            $raw = (string)$raw;
            [$barcode, $kizRaw] = parse_barcode_and_kiz($raw);
            if (!$barcode && !$kizRaw) continue;

            // Для БД: barcode — всегда должен быть, используем либо цифры, либо raw
            if (!$barcode && $kizRaw) {
                // положим в barcode нормализованный CIS (12),
                // а весь сырой КИЗ сохраним в поле kiz (TEXT)
                $norm = normalize_cis_12($kizRaw);
                $barcode = $norm ?: $kizRaw; // если не распарсили — просто кладём raw
            }

            // expiration по нормализованному CIS (если есть)
            $expiration = null;
            if ($kizRaw) {
                $norm = normalize_cis_12($kizRaw);
                if ($norm && isset($exp_by_cis[$norm])) {
                    $expiration = $exp_by_cis[$norm];
                }
            }
            // на всякий пожарный: если в exp_by_cis ключ — requestedCis без 21, но у нас с 21
            // оставим как есть; ЧЗ обычно возвращает именно requestedCis

            $itemNameSafe = mb_substr($itemName, 0, 255);

            $ins->execute([
                ':order_id'       => $orderId,
                ':order_number'   => $orderNumber,
                ':assort_id'      => $assortId ?: null,
                ':item_name'      => $itemNameSafe ?: null,
                ':barcode'        => $barcode,
                ':is_marked'      => $isMarked,
                ':kiz'            => $kizRaw ?: null,
                ':price'          => $price,
                ':payment_method' => $paymentMethod ?: null,
                ':withdrawn_date' => $withdrawnDate ?: null,
                ':expiration_date'=> $expiration ?: null,
            ]);
            $saved++;

            // строка для Google Sheets
            $rows_for_gsheet[] = [
                date('Y-m-d H:i:s'), // shipment_date
                $orderId,
                $orderNumber,
                $assortId ?: '',
                $itemNameSafe ?: '',
                $barcode,
                $isMarked ? '1' : '0',
                $kizRaw ?: '',
                number_format($price, 2, '.', ''),
                $paymentMethod ?: '',
                $withdrawnDate ?: '',
                $expiration ?: '',
            ];
        }
    }

    $pdo->commit();

    // ====================== 8) Отправка в Google Sheets (опционально) ======================
    // В config.php можно задать:
    $gsheet_webhook_url = 'https://script.google.com/macros/s/AKfycbxjRzluBu50cWOjqYjJdgDzEqNPpYp95KB2I789gywsPJEDCVP5kXjOeIOnvZbFwZVb/exec';
    $gs_url = $gsheet_webhook_url ?? '';
    $gs_res = null;
    if ($gs_url && !empty($rows_for_gsheet)) {
         $header = [
        'Дата отгрузки',
        'ID заказа',
        'Номер заказа',
        'ID ассортимента',
        'Наименование товара',
        'Штрихкод / код',
        'Маркируемый товар',
        'КИЗ (сырой)',
        'Цена, ₽',
        'Способ оплаты',
        'Дата вывода из оборота',
        'Срок годности'
    ];
        $payload_gs = json_encode(['header' => $header, 'rows' => $rows_for_gsheet], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $gs_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => $payload_gs,
        ]);
        $gs_raw  = curl_exec($ch);
        $gs_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $gs_err  = curl_error($ch);
        curl_close($ch);

        $gs_res = ['http' => $gs_http, 'raw' => $gs_raw ?: $gs_err];
    }

    echo json_encode([
        'ok'        => true,
        'saved'     => $saved,
      	//'cz_json' => $expiration,
        //'cz_debug'  => $cz_debug,
        //'gs_result' => $gs_res,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage(), 'cz_debug' => $cz_debug], JSON_UNESCAPED_UNICODE);
}
