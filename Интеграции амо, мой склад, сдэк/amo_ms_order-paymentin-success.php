<?php
//конфигурационные настройки
require_once ('config.php');
//инициация классов и методов
require_once ($path.$dirname.'/classes/mystore.class.php');
require_once ($path.$dirname.'/classes/amocrmapi3.class.php');
require_once ($path.$dirname.'/modules/functions.php');

//получаем вебхук из AMO при переходе сделки в статус "Заказано"
$data = empty($_POST)?json_decode(file_get_contents('php://input'), true):$_POST;
$data = empty($data)?$_GET:$data;
log_func($data, "script 4 - input data from webhook");
print_r("in process \n");

//webhook живет небольшое количество времени, поэтому повторяем запрос
if (isset($data["id"]) && isset($data["type"]))
{
    $post_data = array(
        "id"=>$data["id"],
        "status_id"=>"paymentIn success",
    );
    $url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    log_func($post_data, "script 4 - request to me ".$url);
    request($url,$post_data,'POST');
    die();
}
//если в запросе есть id и status id, то отправляем в работу
$id = (!empty($data["id"]) && !empty($data["status_id"]))?$data["id"]:null;
if (isset($id))
{
    // определяем нужные запросы и параметры из амо
    //парсим fields.csv
    $config_params = fileParse('fields.csv');

    //конфигурируем запросы МойСклад
	$mystore = new mystore();

	//конфигурируем запросы Амо
	$crm = new amocrmapi3();

	//запрос заказа по id
    $get_order = $mystore->callFunc(
        '/customerorder/'.$id,
        array(),
        'GET'
    );
    log_func($get_order, "script 4 - find order in myStore");

    if (isset($get_order["name"]))
    {
    	// берем нужные поля из Инфо заявки в мой склад
    	$fieldId = findField("Наименование поля в Амо","Ссылка на сделку Амо","Имя или id поля в МойСклад",$config_params);
    	foreach($get_order["attributes"] as $attribute)
    	{
    		// Получаем id заказа в Амо
            if ($attribute["id"] == $fieldId)
            {
                $first_let = strlen(findField("Наименование поля в Амо","Ссылка на сделку Амо","Префикс",$config_params));
                // $last_let = strpos($attribute["value"],"?with=");
                $crmLeadId = substr($attribute["value"],$first_let);;
            }
    	}
    	// обновляем статус заказа в Амо на успех (id = 142)
    	if (isset($crmLeadId))
    	{
			// обновляем статус
	    	$get = $crm->Call_func(
	    		'/api/v4/leads/'.$crmLeadId,
	    		array("status_id"=>142),
	    		'PATCH'
	    	);
	    	log_func($get, "script 4 - change status in Amo order");
    	}
    }
}
else
{
    log_func($data, "script 4 - Order_id not detected in MyStore");
}


?>

