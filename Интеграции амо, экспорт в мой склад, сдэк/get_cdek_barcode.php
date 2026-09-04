<?php
define('CLIENT_ID', 'WJSdKofTZydrgudQa0ODJ0vZ3Ll4VfBK');
define('CLIENT_SECRET', 'LmpznVZYQEJRqZ1hfpHtoKgmIyC84mxq');
define('SECRET_KEY', 'TESTEKEY');
define('TOKEN_FILE', __DIR__ . '/cdek_token.json');

// Получение access_token
function getAccessToken() {
    if (file_exists(TOKEN_FILE)) {
        $data = json_decode(file_get_contents(TOKEN_FILE), true);
        if (isset($data['expires_at']) && $data['expires_at'] > time()) {
            return $data['access_token'];
        }
    }

    $response = file_get_contents('https://api.cdek.ru/v2/oauth/token', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => CLIENT_ID,
                'client_secret' => CLIENT_SECRET,
            ]),
        ],
    ]));

    $result = json_decode($response, true);
    if (isset($result['access_token'])) {
        $result['expires_at'] = time() + $result['expires_in'];
        file_put_contents(TOKEN_FILE, json_encode($result));
        return $result['access_token'];
    }

    return null;
}

// Поиск order_uuid по трек-номеру
function getOrderUuidByTrackingNumber($trackingNumber, $accessToken) {
    $url = "https://api.cdek.ru/v2/orders?cdek_number=" . urlencode($trackingNumber);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
        ],
    ]);

    $response = file_get_contents($url, false, $context);
    $data = json_decode($response, true);
  
    file_put_contents('debug_orders.json', json_encode($data, JSON_PRETTY_PRINT));

    return $data['entity']['uuid'] ?? null;
}

// Создание PDF штрихкода
function createBarcode($orderUuid, $accessToken) {
    $data = [
        'orders' => [['order_uuid' => $orderUuid]],
        'copy_count' => 1,
        'format' => 'A4',
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $accessToken\r\n",
            'content' => json_encode($data),
        ],
    ]);

    $response = file_get_contents("https://api.cdek.ru/v2/print/barcodes", false, $context);
    return json_decode($response, true)['entity']['uuid'] ?? null;
}

// Получение PDF-ссылки
function getBarcodePdfUrl($barcodeUuid, $accessToken) {
    $url = "https://api.cdek.ru/v2/print/barcodes/$barcodeUuid";
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $accessToken\r\n",
        ],
    ]);

    $response = file_get_contents($url, false, $context);
    $data = json_decode($response, true);
    return $data['entity']['url'] ?? null;
}

// Скачивание и вывод PDF клиенту
function downloadBarcodeFile($pdfUrl, $accessToken) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $accessToken\r\n"
        ]
    ]);

    $fileContent = file_get_contents($pdfUrl, false, $context);

    if ($fileContent === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to download PDF']);
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="barcode.pdf"');
    echo $fileContent;
    exit;
}

// --- Основная логика ---

$secret = $_GET['secret'] ?? null;
if ($secret !== SECRET_KEY) {
    http_response_code(400);
    echo json_encode(['error' => 'access denied!']);
    exit;
}

$trackNumber = $_GET['track_number'] ?? null;
if (!$trackNumber) {
    http_response_code(400);
    echo json_encode(['error' => 'track_number is required']);
    exit;
}

$accessToken = getAccessToken();
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to get access token']);
    exit;
}

$orderUuid = getOrderUuidByTrackingNumber($trackNumber, $accessToken);
if (!$orderUuid) {
    http_response_code(404);
    echo json_encode(['error' => 'Order UUID not found for this tracking number']);
    exit;
}

$barcodeUuid = createBarcode($orderUuid, $accessToken);
if (!$barcodeUuid) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create barcode']);
    exit;
}

sleep(2); // Подождать, пока файл будет готов

$pdfUrl = getBarcodePdfUrl($barcodeUuid, $accessToken);
if (!$pdfUrl) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve barcode PDF']);
    exit;
}

// Вывод PDF клиенту
downloadBarcodeFile($pdfUrl, $accessToken);
