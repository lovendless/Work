<?php
// Подключение конфигурации и необходимых классов
require_once 'config.php';
require_once $path . $dirname . '/classes/mystore.class.php';
require_once $path . $dirname . '/modules/functions.php';

// Получение параметров из запроса
$groups = $_GET['groups'] ?? [];
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

if (empty($groups) || !$dateFrom || !$dateTo) {
    die("Ошибка: необходимо выбрать хотя бы одну группу и указать даты.");
}

$dateFromFormatted = date('Y-m-d H:i', strtotime($dateFrom . ' -4 hours'));
$dateToFormatted = date('Y-m-d H:i', strtotime($dateTo . ' -4 hours'));

$mystore = new mystore();

//Достаем группу/группы контрагентов из моего склада
$allCounterpartyIds = [];
foreach ($groups as $group) {
    $filter = '/counterparty?filter=tags=' . urlencode($group);
    $response = $mystore->callFunc($filter);
	//Достаем контрагентов
    if (isset($response['rows'])) {
        foreach ($response['rows'] as $counterparty) {
            if (isset($counterparty['id'])) {
                $allCounterpartyIds[$counterparty['id']] = $counterparty['id'];
            }
        }
    }
}

if (empty($allCounterpartyIds)) {
    die("Не найдено контрагентов по заданным группам.");
}
//Готовим массив с ссылками каждого контрагента групп для фильтрации отгрузок
$agentFilters = [];
foreach ($allCounterpartyIds as $id) {
    $agentFilters[] = 'agent=https://api.moysklad.ru/api/remap/1.2/entity/counterparty/' . $id;
}
//Готовим массив с ссылками каждого контрагента групп для фильтрации отгрузок и даты
$filterParts = array_merge(
    $agentFilters,
    [
        'moment>=' . $dateFromFormatted,
        'moment<=' . $dateToFormatted
    ]
);

$params = ['filter' => implode(';', $filterParts)];
$query = http_build_query($params);
$link = '/demand?' . $query;

//Достаем отгрузки
$demandResult = $mystore->callFunc($link);

$rows = [];
	
if (isset($demandResult['rows'])) {
    foreach ($demandResult['rows'] as $demand) {
     
      	//Дата отгрузки
        $date = new DateTime($demand['moment'], new DateTimeZone('UTC'));
		$date->setTimezone(new DateTimeZone('+04:00'));
		$formattedDate = $date->format('Y-m-d\TH:i:s');
        $inn = '';
      	//Достаем агента
        if (!empty($demand['agent']['meta']['href'])) {
            $agentHref = $demand['agent']['meta']['href'];
            $agentData = $mystore->callFunc(str_replace("https://api.moysklad.ru/api/remap/1.2/entity", '', $agentHref));
          	
          	//Достаем его инн
            $inn = $agentData['inn'] ?? '';
        }
		//Достаем позиции каждой отгрузки
        $positionsHref = $demand['positions']['meta']['href'] ?? null;
        if ($positionsHref) {
            $positions = $mystore->callFunc(str_replace("https://api.moysklad.ru/api/remap/1.2/entity", '', $positionsHref));
            foreach ($positions['rows'] ?? [] as $position) {
                $assortmentHref = $position['assortment']['meta']['href'] ?? '';
                $name = '';
                $article = '';
                if ($assortmentHref) {
                  	// Достаем ассортимент позиции
                    $assortmentData = $mystore->callFunc(str_replace("https://api.moysklad.ru/api/remap/1.2/entity", '', $assortmentHref));
                    $name = $assortmentData['name'] ?? '';
                    $article = $assortmentData['article'] ?? '';
                }
				// Готовим данные
                $rows[] = [
                  	
                    'product' => $name,
                    'article' => $article,
                    'quantity' => $position['quantity'],
                    'price' => $position['price'] / 100,
                    'date' => $formattedDate,
                    'inn' => $inn
                ];
            }
        }
    }
}

// === Создание XML === //
$namespace = "http://localhost/mysklad";
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;

$root = $dom->createElementNS($namespace, 'Отгрузки');
$root->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns:xs", "http://www.w3.org/2001/XMLSchema");
$root->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");
$dom->appendChild($root);

// Группируем строки по ключу отгрузки: используем дату и ИНН (можно добавить номер документа при необходимости)
$groupedByShipment = [];

foreach ($rows as $row) {
    $key = $row['date'] . '_' . $row['inn'];
    $groupedByShipment[$key][] = $row;
}

// Каждая группа — отдельная отгрузка
foreach ($groupedByShipment as $shipmentRows) {
    $shipmentNode = $dom->createElementNS($namespace, 'Отгрузка');

    foreach ($shipmentRows as $row) {
        $doc = $dom->createElementNS($namespace, 'Позиция');

        $doc->appendChild($dom->createElementNS($namespace, 'Номенклатура', htmlspecialchars($row['product'])));
        $doc->appendChild($dom->createElementNS($namespace, 'Артикул', htmlspecialchars($row['article'])));
        $doc->appendChild($dom->createElementNS($namespace, 'Количество', $row['quantity']));
        $doc->appendChild($dom->createElementNS($namespace, 'Цена', number_format($row['price'], 2, '.', '')));
        $doc->appendChild($dom->createElementNS($namespace, 'ДатаДокумента', $row['date']));
        $doc->appendChild($dom->createElementNS($namespace, 'ИННКонтрагента', $row['inn']));

        $shipmentNode->appendChild($doc);
    }

    $root->appendChild($shipmentNode);
}

// === Заголовки для скачивания === //
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="export_1c.xml"');
echo $dom->saveXML();
exit;
