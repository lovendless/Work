<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/cz.class.php';

header('Content-Type: application/json; charset=utf-8');

function jexit($ok, array $extra = []): void {
    echo json_encode(['ok' => (bool)$ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function jerror(string $msg, array $extra = [], int $code = 400): void {
    http_response_code($code);
    jexit(false, ['error' => $msg] + $extra);
}
function clog(string $msg): void {
    error_log('[' . date('c') . '] [CZ] ' . $msg);
}

try {
    $cz = new CzClient($cz_config, $database);
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'get_key') {
        clog('get_key start');
        $key = $cz->getKey(); // ['uuid','data']
        // сохраняем в сессию для submit_signature
        $_SESSION['cz_auth'] = [
            'uuid' => (string)$key['uuid'],
            'data' => (string)$key['data'],
            'ts'   => time(),
        ];
        jexit(true, $key);
    }

    if ($action === 'submit_signature') {
        clog('submit_signature start');

        // читаем JSON-тело (если отправлено как application/json)
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !$payload) {
            // фолбэк на form-data / x-www-form-urlencoded
            $payload = $_POST;
        }

        // подпись: убираем все пробелы/переводы строк
        $signature = isset($payload['signature']) ? (string)$payload['signature'] : '';
        $signature = preg_replace('/\s+/', '', $signature ?? '');
        // uuid можно передать в теле или взять из сессии, где мы его сохранили на get_key
        $uuid = isset($payload['uuid']) && $payload['uuid'] !== ''
            ? (string)$payload['uuid']
            : (string)($_SESSION['cz_auth']['uuid'] ?? '');

        if ($uuid === '' || $signature === '') {
            jerror('Пустой uuid или подпись.');
        }

        $innOverride = isset($payload['inn']) ? trim((string)$payload['inn']) : null;

        clog('submit_signature uuid=' . $uuid . ' len(signature)=' . strlen($signature));

        $token = $cz->exchangeSignatureForToken($uuid, $signature, $innOverride);

        // очистим one-time ключ в сессии — он больше не нужен
        unset($_SESSION['cz_auth']);

        // проверим, что токен реально положился в БД и свежий
        $stored = $cz->getStoredToken();
        if (!$stored) {
            jerror('Токен не сохранён в БД.', [], 500);
        }

        jexit(true, ['message' => 'Авторизация выполнена. Можно продолжать работу.']);
    }

    if ($action === 'get_token_state') {
        $tok = $cz->getStoredToken();
        jexit(true, ['hasToken' => (bool)$tok]);
    }

    jerror('Неизвестное действие.');
} catch (Throwable $e) {
    clog('EXCEPTION: ' . $e->getMessage());
    jerror('Ошибка авторизации: ' . $e->getMessage(), [], 500);
}
