<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/mystore.class.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');

// ВРЕМЕННО: показываем ошибки
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$mystore = new mystore();

// ===== Пример использования =====
$products = ms_fetch_all_products($mystore, onlyActive: true);

// Можешь сразу пронормализовать под свои нужды:
$normalized = array_map(function(array $p) {
    return [
        'id'        => $p['id']        ?? null,
        'name'      => $p['name']      ?? '',
        'code'      => $p['code']      ?? '',
        'article'   => $p['article']   ?? '',
        'archived'  => (bool)($p['archived'] ?? false),
        'uom'       => $p['uom']['name'] ?? null,              // единица измерения, если нужна
        'barcodes'  => $p['barcodes']  ?? [],                  // массив штрихкодов
        'salePrices'=> $p['salePrices']?? [],                  // цены продажи (в копейках)
        'images'    => $p['images']['meta']['size'] ?? 0,      // кол-во изображений (если поле присутствует)
        // добавь любые другие поля, которые используешь
    ];
}, $products);

// Пример: вывести общее количество и первые 5 строк
echo 'Всего товаров: ' . count($products) . "<br>\n";
foreach ($normalized as $row) {
    echo htmlspecialchars("{$row['id']} | {$row['name']} | code={$row['code']} | артикул={$row['article']} | архив=" . ($row['archived']?'да':'нет')) . "<br>\n";
}
