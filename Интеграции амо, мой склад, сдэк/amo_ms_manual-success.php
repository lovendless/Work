<?php
//конфигурационные настройки
require_once ('config.php');
//инициация классов и методов
require_once ($path.$dirname.'/classes/mystore.class.php');
require_once ($path.$dirname.'/classes/amocrmapi3.class.php');
require_once ($path.$dirname.'/modules/functions.php');
require_once ($path.$dirname.'/modules/country.php');

//получаем вебхук из AMO при переходе сделки в статус "Заказано"
$data = empty($_POST)?json_decode(file_get_contents('php://input'), true):$_POST;
// $data = array("id"=>"29016517","status_id"=>"142");
log_func($data, "script 5 - input data from webhook");
print_r("in process \n");

//webhook живет небольшое количество времени, поэтому повторяем запрос
if (isset($data["leads"]))
{
    $lead_data = array_values($data["leads"])[0];
    $post_data = array(
        "id"=>$lead_data[0]["id"],
        "status_id"=>$lead_data[0]["status_id"],
    );
    $url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    log_func($post_data, "script 5 - request to me ".$url);
    $run = "curl -X POST -H 'Content-Type: application/json'";
    $run.= " -d '" .json_encode($post_data). "' " . "'" . $url . "'";
    $run.= " > /dev/null 2>&1 &";
    exec($run);
    die("OK");
}

//если в запросе есть id и status id, то отправляем в работу
$id = (!empty($data["id"]) && !empty($data["status_id"]))?$data["id"]:null;
if (isset($id))
{
    // определяем нужные запросы и параметры из амо
    //парсим fields.csv
    // $config_params = fileParse($path.'/fields.csv');
    $config_params = fileParse('fields.csv');
}
else
{
    log_func($data, "script 5 - crm id not detected");
    die("script 5 - crm id not detected");
}
$successStatusArray = array(142);
if (in_array($data["status_id"],$successStatusArray))
{
    $myStore_amoId = findField("Наименование поля в Амо","Ссылка на МС","Имя или id поля в Амо",$config_params);
    $myStore_amoId_prefix = findField("Наименование поля в Амо","Ссылка на МС","Префикс",$config_params);
    $myStore_stateSuccessId = findField("Наименование поля в Амо","status_id","Имя или id поля в МойСклад",$config_params);
	//получаем информацию о заказе из Амо
    $crm = new amocrmapi3();
    $order = [];
    $order["lead"] = $crm->Call_func('/api/v4/leads/'.$id.'?with=catalog_elements,contacts,products');

    $ms_id = $crm->get_custom_field_value($order["lead"]["custom_fields_values"], $myStore_amoId);
    $ms_id = str_replace($myStore_amoId_prefix, '', $ms_id);
    if (strlen($ms_id) > 20)
    {
		// Меняем статус Заказа Покупателя на Успех
    	$mystore = new mystore();
        $get_order = $mystore->callFunc('/customerorder/'.$ms_id,array(),'GET');

        if (isset($get_order["id"]))
        {
			$get_order["state"] = array(
	            "meta" => array(
	                "href" => $mystore->mystore_config["domain"]."/customerorder/metadata/states/".$myStore_stateSuccessId,
	                "type" => "state",
	                "mediaType"=>"application/json",
                )
    		);
    		$get_order = $mystore->callFunc(
    	        '/customerorder/'.$ms_id,
    	        $get_order,
    	        'PUT'
    	    );
        }
	    // Проверяем, что по Заказу не существует оплаты. Если оплаты не существует, то проводим входящий платеж. Иначе просто не создаем платеж.
	    if (isset($get_order["id"]))
        {
            //Важно, если нет входящего платежа то, проводим
            $paymentInPrice = $order["lead"]["price"];
            foreach ($get_order["payments"] as $payment)
            {
             	$paymentInPrice -= isset($payment["linkedSum"])?$payment["linkedSum"]/100:0.0;
            } 
            log_func((float)$paymentInPrice, "script 5 - paymentInPrice");
            if ($paymentInPrice > 1)
            {
                //создаем входящий платеж. $paymentInPrice в рублях
                paymentIn($paymentInPrice);
            }
	   }

       log_func([],"script 5 - Success");

    }
    else
    {
    	log_func([],"script 5 - order in MS cannot be found ERRRORRRR!!!!");
        die("script 5 - order in MS cannot be found ERRRORRRR!!!!");
    }
    
}

function paymentIn($paymentInPrice = 0)
{
    global $get_order, $customer, $mystore;
    // Получаем шаблон платежа
    $paymentDraft = $mystore->callFunc('/paymentin/new',
            array( 
                "operations" => array(array("meta"=> $get_order["meta"]))
            ),
            'PUT'
    );
    $paymentDraft["agent"] = $get_order["agent"];
    $paymentDraft["operations"] = array(array("meta"=> $get_order["meta"]));
    $paymentDraft["paymentPurpose"] = "Оплата по заказу ".$get_order["name"];
    $paymentDraft["sum"] = $paymentInPrice*100.0;

    // создаем платеж на основе шаблона
    $newPaymentIn = $mystore->callFunc('/paymentin',$paymentDraft,'POST');
    log_func($newPaymentIn, "script 5 - newPaymentIn");
}

?>
