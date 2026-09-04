<?php

$database = __DIR__ . "/db_store.db";

// === МойСклад (оставляем ваши данные) ===
$mystore_config = array(
    "domain" => 'https://api.moysklad.ru/api/remap/1.2',
    "login" => "admin@test",
    "pass" => "f33325c2f7",
    "delivery_name" => "Доставка",
    "meta" => array(
        "href"=>"https://api.moysklad.ru/api/remap/1.2/entity/organization/dce190f4-e243-11ec-0a80-0b2700059912",
        "type" => "organization",
        "mediaType" => "application/json",
    ),
    'db_config_table' => "db_mystore_config", // таблица токена в SQLite
);

// === Честный знак ===
$cz_config = [
    'base_url'        => 'https://markirovka.crpt.ru',       
    'api_prefix'      => '/api/v3/true-api',          
    'inn'             => '5433976730',                

    'save_token_sqlite' => true, // сохранить токен в SQLite (db_store.db)
    'sqlite_table'      => 'db_cz_tokens', // имя таблицы для токена
    // Таймауты
    'connect_timeout' => 10,
    'read_timeout'    => 30,
];

// === MySQL: реестр отгрузок ===
$mysql = [
    'host'   => 'localhost',
    'port'   => 3306,
    'dbname' => 'cs92623_shipment',
    'user'   => 'cs92623_shipment',
    'pass'   => '9YHHD5vR',
    'charset'=> 'utf8mb4',
    'table_shipments' => 'shipments_registry',
];

// === Авторизация страницы проверки комплектации ===
$auth = [
    'login'          => 'nooteria_packer',                    
    'password_hash'  => password_hash('z3#r]379;BkC', PASSWORD_DEFAULT),

    'access_token'   => 'C0kJIqQEwaxMGMF76aFSDEFruWrL9IjsVqGB278Jot7PYUBeKUQgBgYOHPzsDAcK',

    // настройки cookie
    'cookie_name'    => 'accesstoken',
    'cookie_lifetime'=> 60 * 60 * 24 * 7, // 7 дней
    'cookie_path'    => '/',
    'cookie_domain'  => '',               
    'cookie_secure'  => true,            
    'cookie_httponly'=> true,
    'cookie_samesite'=> 'Lax',            
];

// вспомогательная функция подключения PDO к MySQL
function mysql_pdo_connect(array $cfg): PDO {
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$cfg['charset']}",
    ]);
    return $pdo;
}
