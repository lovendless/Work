<?php
// Подключение к amoCRM и получение данных клиента
$orderId = $_GET["order_id"] ?? null; // Получаем order_id из URL

// Настройки CRM
define('CRM_URL', "https://test.amocrm.ru");
define('USER_LOGIN', "test@gmail.com");
define('USER_HASH', "226767e7aa262e47cb643778866f8455ea7d5dbe");
define('DESCRIPTION', 1963247);
define('AMOUNT', 1974835);
define('SUBTOTAL', 97973);
define('NAME', 60377397);
define('PHONE', 1271170);
define('ADDRESS', 1967219);
define('EMAIL', 1987725);
define('EXPIRE', 2294579);
define('VALIDATION_CODE', 2002965);
define('STATUS_ID', array('24346207' => 'ожидаем оплату', '12404886' => 'заказано','12404889' => 'отправлено','12404892' => 'получена'));
define('PAYMENT', 1969983);
define('PAYMENT_WAY', array('4753975' => 'Отложенный платеж'));

$result = null;

if ($orderId) {
    // Обработка orderId
    if (strpos($orderId, '.')) {
        $tmp = explode(".", $orderId);
        $check = end($tmp);
        $order_id = $tmp[0];
    } else {
        $check = substr($orderId, -4);
        $order_id = substr($orderId, 0, -4);
    }

    // Авторизация в CRM
    $auth = http_request_(CRM_URL . '/private/api/auth.php?type=json','POST', array ('USER_LOGIN' => USER_LOGIN, 'USER_HASH' => USER_HASH));
    
    if (isset($auth['response']['auth'])) {
        // Получение данных о заказе
        $link = CRM_URL . '/api/v2/leads?id=' . $order_id;
        $out = get_lead($link);
        
        // Обработка данных заказа
        if ($out) {
            $price = $out['sale'];
            $status_id = $out['status_id'];
            $custom_fields = get_custom_fields_from_array($out);

            // Получение данных о контакте
            $link = CRM_URL . '/api/v2/contacts?id=' . end($out['contacts']['id']);
            $out = get_lead($link);
            $contact_fields = get_custom_fields_from_array($out);

            // Дальнейшая обработка...
            // (Остальная часть кода обработки заказа)
            
            // Формируем результат для отображения
            $result = array(
                "order" => array(
                    "id" => $order_id,
                    "drug" => get_custom_field_value(DESCRIPTION, $custom_fields) . " " . get_custom_field_value(AMOUNT, $custom_fields),
                    "price" => $price,
                ),
                "contact" => array(
                    "name" => $out["name"],
                    "destination" => join("<br>", (array_key_exists(ADDRESS, $contact_fields) ? $contact_fields[ADDRESS] : [])),
                    "phone" => get_custom_field_value(PHONE, $contact_fields, false),
                ),
            );
        }
    }
}

// Проверка наличия необходимых данных
if (!$result || empty($result['contact']['phone']) || empty($result['order']['id']) || empty($result['contact']['name'])) {
    // Перенаправление на страницу ошибки или другую страницу
    header("Location: /index.php"); // Замените на нужный URL
    exit();
}

// Функции для работы с API amoCRM
function get_lead($link) {
    return http_request_($link)['_embedded']['items'][0] ?? null;
}

function http_request_($link, $http_method = 'GET', $params = null) 
{
	$http_header = ($params && $http_method == 'POST')? array('Content-Type: application/json') :  array ();
	$post_fields = ($params && $http_method == 'POST')? json_encode($params) : '';

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
	curl_setopt($curl, CURLOPT_URL, $link);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $http_header);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_fields);
	curl_setopt($curl, CURLOPT_COOKIEFILE, dirname(__FILE__) . '/cookie_'.PREFIX.'.txt');
	curl_setopt($curl, CURLOPT_COOKIEJAR, dirname(__FILE__) . '/cookie_'.PREFIX.'.txt');
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $http_method);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10); // Тайм-аут выполнения запроса в секундах
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3); // Тайм-аут подключения в секундах

	$out = curl_exec($curl); 
	$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // Проверка на тайм-аут
    if(curl_errno($curl)) {
        // Проверяем, был ли это тайм-аут
        if (curl_errno($curl) == CURLE_OPERATION_TIMEOUTED) {
            echo '<script type="text/javascript">
                    alert("Выставляем счет на оплату и формируем платежную страницу... ");
                    location.reload(); // Перезагружаем страницу
                  </script>';
        } else {
            echo 'Ошибка cURL: ' . curl_error($curl);
        }
    }

	curl_close($curl);
	
	$errors = array(
    301 => 'Moved permanently',
    400 => 'Bad request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Not found',
    500 => 'Internal server error',
    502 => 'Bad gateway',
    503 => 'Service unavailable',
	);
	try
	{ 
	    if ($code != 200 && $code != 204) {
	        throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undescribed error', $code);
	    }
	} 
	catch (Exception $E) {
	    die('Ошибка: ' . $E->getMessage() . PHP_EOL . 'Код ошибки: ' . $E->getCode());
	}
	$out = str_replace('\n', "<br>", $out);
	return json_decode($out, true);
}

function get_custom_fields_from_array ($data){
	$result = array ();
	if (!isset($data['custom_fields'])) return $result;
	
	foreach ($data['custom_fields'] as $field) {
		foreach ($field['values'] as $value) {
			if (isset($value['enum']) && (!isset($field['code']) || (isset($field['code']) && !in_array($field['code'], array('EMAIL', 'PHONE'))))) {
				$result[$field['id']][$value['enum']] = $value['value'];
			} else {
				$result[$field['id']][] = $value['value'];
			}
		}
	}
	return $result;
}


function get_custom_field_value ($id, $custom_field, $end=true){
	$result = 0;
	if (array_key_exists($id, $custom_field)) {
		$result = $end ? end($custom_field[$id]) : reset($custom_field[$id]);
	}
	return $result;
}


function getPaymentReq($source) {
    $secretWord = "secret";
    $timestamp = time();
    $hash = hash('sha256', $timestamp . $secretWord);
    $token = "{$hash}:{$timestamp}";
    $url = "https://api.softmediaservice.ru/v1/payments?source={$source}";

    // Инициализация cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Проверка на ошибки cURL
    if ($response === false) {
        return ["error" => "Ошибка при выполнении запроса: " . curl_error($ch)];
    }

    // Проверка HTTP-кода
    if ($httpCode !== 200) {
        return ["error" => "Ошибка API: HTTP код {$httpCode}. Проверьте корректность."];
    }

    // Декодируем JSON-ответ
    $data = json_decode($response, true);

    // Проверяем, есть ли ошибка в ответе
    if (isset($data['error'])) {
        return ["error" => "Ошибка API: " . htmlspecialchars($data['error'])];
    }

    // Проверяем, что данные существуют и являются массивом
    if (!is_array($data) || empty($data)) {
        return ["error" => "Данные не найдены."];
    }

    // Возвращаем первый элемент массива (API уже выбирал случайный реквизит)
    return [
        "method" => $data['method'] ?? "Не указано",
        "phone" => $data['phone'] ?? "Не указано",
        "cardNum" => $data['cardNum'] ?? "Не указано",
        "req" => $data['req'] ?? "Не указано",
        "name" => $data['name'] ?? "Не указано",
        "bank" => $data['bank'] ?? "Не указано",
        "source" => $data['source'] ?? "Не указано"
    ];
}
		
	

    function selectRandomHolder($holders) {
        $rand = mt_rand(1, 100);
        $cumulativeChance = 0;

        foreach ($holders as $holder) {
            $cumulativeChance += $holder['chance'];
            if ($rand <= $cumulativeChance) {
                return $holder;
            }
        }
    }

    // Выбор случайного держателя карты для Сбербанка
    $selectedSberbankHolder = selectRandomHolder($sberbankHolders);
	// Выбор случайного держателя карты для Т-Банка
    $selectedTbankHolder = selectRandomHolder($tbankHolders);
    $selectedAlphaHolder = selectRandomHolder($alphaHolders);
    // Выбор случайного держателя карты для СБП
    $selectedOtherHolder = selectRandomHolder($otherHolders);

// HTML часть для отображения заказа
?>