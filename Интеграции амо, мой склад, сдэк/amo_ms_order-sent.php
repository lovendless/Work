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
log_func($data, "script 3 - input data from webhook");
print_r("in process \n");

//webhook живет небольшое количество времени, поэтому повторяем запрос
if (isset($data["id"]) && isset($data["type"]))
{
    $post_data = array(
        "id"=>$data["id"],
        "status_id"=>"sent",
    );
    $url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    log_func($post_data, "script 3 - request to me ".$url);
    request($url,$post_data,'POST');
    die();
}
//если в запросе есть id и status id, то отправляем в работу
// $data = ["id"=>"5066d85a-a8fd-11ed-0a80-06a700090e9e","status_id"=>"sent"];

$id = (!empty($data["id"]) && !empty($data["status_id"]))?$data["id"]:null;
if (isset($id))
{
    // определяем нужные запросы и параметры из амо
    //парсим fields.csv
    $config_params = fileParse('fields.csv');
    log_func($config_params, "script 3 - change status in Amo order");
    //парсим statuses.csv
    $statuses_params = fileParse('statuses.csv');
    log_func($statuses_params, "script 3 - change status in Amo order");

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
    log_func($get_order, "script 3 - find order in myStore");

    if (isset($get_order["name"]))
    {
    	// берем нужные поля из Инфо заявки в мой склад
    	$fieldId = findField("Наименование поля в Амо","Ссылка на сделку Амо","Имя или id поля в МойСклад",$config_params);
    	$trackNumIdMyStore = findField("Наименование поля в Амо","Трек","Имя или id поля в МойСклад",$config_params);
    	foreach($get_order["attributes"] as $attribute)
    	{
          
    		// Получаем id заказа в Амо
            if ($attribute["id"] == $fieldId)
            {
              log_func($attribute["id"], "script 3 - id attribute amo");
                $first_let = strlen(findField("Наименование поля в Амо","Ссылка на сделку Амо","Префикс",$config_params));
                // $last_let = strpos($attribute["value"],"?with=");
                $crmLeadId = substr($attribute["value"],$first_let);
            }
            // Получаем трек-номер
            if ($attribute["id"] == $trackNumIdMyStore)
            {
                $trackNum = $attribute["value"];
            }
    	}
        log_func($trackNum, "script 3 - find track");
        log_func($crmLeadId, "script 3 - find LeadId");
    	// обновляем заказ в Амо
    	if (isset($crmLeadId) && isset($trackNum))
    	{
    		//получаем заказ
    		$order = $crm->Call_func('/api/v4/leads/'.$crmLeadId.'?with=catalog_elements,contacts,products');
    		log_func($order, "script 3 - find CRM order");

    		// ищем на какой статус нужно поменять
    		$statusIdNew = GetNewStatusUpdateId($order);
            log_func($statusIdNew, "script 3 - set status");
    		if (isset($statusIdNew))
    		{
    			// обновляем трек номер в заказе
	    		$trackNumIdAmo = findField("Наименование поля в Амо","Трек","Имя или id поля в Амо",$config_params);
	    		$get = $crm->Call_func(
		    		'/api/v4/leads/'.$crmLeadId,
		    		array("custom_fields_values"=>array(array("field_id"=>intval($trackNumIdAmo),"values"=>array(array("value"=>$trackNum))))),
		    		'PATCH'
		    	);
	    		log_func($get, "script 3 - push track number to Amo order");

				// обновляем статус
		    	$get = $crm->Call_func(
		    		'/api/v4/leads/'.$crmLeadId,
		    		array("status_id"=>intval($statusIdNew)),
		    		'PATCH'
		    	);
		    	log_func($get, "script 3 - change status in Amo order");
    		}
    	}

    }
}
else
{
    log_func($data, "script - 3 Order_id not detected in MyStore");
}

// Получаем необходимый статус по значениям воронки и доп. полей
function GetNewStatusUpdateId($order = [])
{
	global $crm, $statuses_params;

	foreach ($statuses_params as $sParam) {
		if ($sParam["id воронки"] == $order["pipeline_id"])
		{
			// получаем значение по Амо
            if (intval($sParam['id поля сделки']) > 1000)
            {
                $amo_data = $crm->get_custom_field_id($order["custom_fields_values"], $sParam["id поля сделки"]);
            }
            else
            {
                $params = explode('.',$sParam['id поля сделки']);
                $variable = $order;
                for ($i=0; $i < sizeof($params); $i++) {
                    $variable = isset($variable[$params[$i]])?$variable[$params[$i]]:"";
                }
                $amo_data = $variable;
            }
            log_func($amo_data, "script 3 - get status");
            if ($amo_data != 0 && in_array($amo_data, explode(',', str_replace(array("[", "]"), '', $sParam["id значения поля сделки"]))))
            	return $sParam["Поменять на статус (id статуса)"];
		}
	}

	return null;
}

?>