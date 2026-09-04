<?php
//конфигурационные настройки
require_once ('config.php');
// инициация классов и методов
require_once ($path.$dirname.'/classes/mystore.class.php');
require_once ($path.$dirname.'/classes/amocrmapi3.class.php');
require_once ($path.$dirname.'/modules/functions.php');
require_once ($path.$dirname.'/modules/country.php');

// обновление ключа АмоЦРМ
$crm = new amocrmapi3();
print_r($crm->db_select('WHERE name = "access_token";'));
$crm->First_Auth();
echo "ok";
?>