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
// $data = array("id"=>"31571287","status_id"=>"30471025");
log_func($data, "input data from webhook");
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
    log_func($post_data, "request to me ".$url);
    $run = "curl -X POST -H 'Content-Type: application/json'";
    $run.= " -d '" .json_encode($post_data). "' " . "'" . $url . "'";
    $run.= " > /dev/null 2>&1 &";
    exec($run);
    die("OK");
}
//если в запросе есть id и status id, то отправляем в работу
$id = (!empty($data["id"]) && !empty($data["status_id"]))?$data["id"]:null;
log_func($id, "request to me id ".$id);
if (isset($id))
{
    // определяем нужные запросы и параметры из амо
    //парсим fields.csv
    $config_params = fileParse(__DIR__ . '/fields.csv');
    $request_info = ["lead"];
    foreach ($config_params as $config_param) {
        if (!in_array($config_param["Сущность в Амо"],$request_info)) $request_info[] = $config_param["Сущность в Амо"];
    }
}
else
{
    log_func($data, "crm id not detected");
    die("crm id not detected");
}
//Если статус Заказано по fields.csv, то отправляем в работу
$successStatusArray = explode(',', str_replace(array("[", "]"), '', findField("Наименование поля в Амо","Заказано","Имя или id поля в Амо",$config_params)));
log_func($successStatusArray, "request to me successStatusArray ".$successStatusArray);
if (in_array($data["status_id"],$successStatusArray))
// if (isset($data["status_id"]))
{
    //парсим pricelist
    $pricelist = file('pricelist')[0];

    //конфигурируем запросы МойСклад
    $mystore = new mystore();
    //проверяем уникальность заказа в МойСклад
    $MyStoreType = findField("Наименование поля в Амо","Ссылка на сделку Амо","Сущность в МойСклад",$config_params);
    $MyStoreFieldId = findField("Наименование поля в Амо","Ссылка на сделку Амо","Имя или id поля в МойСклад",$config_params);
    $MyStorePrefix = findField("Наименование поля в Амо","Ссылка на сделку Амо","Префикс",$config_params);
    $new_order = $mystore->callFunc(
        '/customerorder?filter='.http_build_query(array($mystore->mystore_config["domain"]."/entity/".$MyStoreType."/metadata/attributes/".$MyStoreFieldId => $MyStorePrefix.$data["id"])),
        array(),
        'GET'
    );
    // Если заказ не найден то продолжаем создание заказа в МойСклад
    if(isset($new_order["rows"][0]))
    {
        log_func($new_order, "Order is already exist!!!!!");
        die("Order is already exist!!!!!");
    }
        
    //получаем информацию о заказе из Амо
    $crm = new amocrmapi3();
    $order = [];
    $order["lead"] = $crm->Call_func('/api/v4/leads/'.$id.'?with=catalog_elements,contacts,products');

    //изменяем значения в файле с полями по определенным параметрам в АМО
    require_once($path.$dirname.'/actions/changeInFields.php');
    //Если заказ найден, формируем необходимые поля
    if (isset($order["lead"]["_embedded"]))
    {
        // получаем дополнительные требуемые сущности из Амо согласно fields.
        foreach ($request_info as $type) {
            foreach ($config_params as $config_param) {
                $type_id = [];
                if ($config_param["Тип запроса"] === $type && strpos($config_param["Имя или id поля в Амо"],"id") > 0)
                {
                    $type_id = fillFromAmoToMyStorebyCSV(array(array_merge($config_param,array("Имя или id поля в МойСклад"=>$type))));
                }
                
                if (!empty($type_id))
                {
                    $order[$type] = $crm->Call_func('/api/v4/'.key($type_id).'/'.$type_id[key($type_id)]);
                }
            }
        }
        log_func($order, "order data");

        //парсим инофрмацию по тавару
        $products = productsInfo();
        // log_func($products, "products");

        //Работа с контрагентом
        $customer = customer();
        // log_func($customer, "customer");

        //Создаем заказ в параметрах указывается предоплатный или нет
        $new_order = newCustomerOrder();
        // log_func($new_order, "newCustomerOrder");

        $myStore_amoId = findField("Наименование поля в Амо","Ссылка на МС","Имя или id поля в Амо",$config_params);
        $myStore_amoId_prefix = findField("Наименование поля в Амо","Ссылка на МС","Префикс",$config_params);
        //передаем номер из мойсклад в АМО
        $get = $crm->Call_func(
            '/api/v4/leads/'.$id,
            array(
                "custom_fields_values"=>array(
                    array(
                        "field_id"=>intval($myStore_amoId),
                        "values"=>array(array("value"=>$myStore_amoId_prefix.$new_order["id"]))
                    )
                )
            ),
            'PATCH'
        );

        if (isset($new_order["id"]))
        {
            $paymentInPrice = 0;
            //Важно, если заказ предоплатный, то создаем еще входящий платеж
            // $paymentInPrice = $crm->get_custom_field_value(
            //     $order["lead"]["custom_fields_values"],
            //     findField("Наименование поля в Амо","Получена оплата","Имя или id поля в Амо",$config_params)
            // );

            //парсим statuses.csv
            $statuses_params = fileParse(__DIR__ . '/statuses.csv');
            // проверяем, предоплатный ли заказ. Если заказ предоплатный, то создаем входящий платеж
            foreach ($statuses_params as $sParam)
            {
                if ($sParam["id воронки"] == $order["lead"]["pipeline_id"] && $sParam["Поменять на статус"] == "Успешно реализовано")
                {
                    $statusesInLine = explode(',', str_replace(array("[", "]"), '', $sParam["id значения поля сделки"]));
                    $amo_data = $crm->get_custom_field_id($order["lead"]["custom_fields_values"], $sParam["id поля сделки"]);
                    if (in_array($amo_data,$statusesInLine))
                    {
                        $paymentInPrice = $order["lead"]["price"];
                    }
                }
            }
            log_func((float)$paymentInPrice, "paymentInPrice");

            if ($paymentInPrice > 0)
            {
                //создаем входящий платеж. $paymentInPrice в рублях
                paymentIn($paymentInPrice);
            }
        }
        else
        {
            log_func([],"new order cannot be crearted ERRRORRRR!!!!");
            die("new order cannot be crearted ERRRORRRR!!!!");
        }
    }
    else
    {
        log_func($order, "order not found");
    }
}
else
{
    log_func($data, "status id not in array");
}
log_func(['OK'], "OK");
echo "OK";

//Заполняет необходимые поля из Амо в требуемые поля для МойСклад согласно fields.csv
function fillFromAmoToMyStorebyCSV($config_params = [])
{
    global $mystore, $crm, $order;

    $data = [];
    foreach ($config_params as $param) {
        if (!empty($param['Имя или id поля в МойСклад']))
        {
            // получаем значение по Амо
            if (intval($param['Имя или id поля в Амо']) > 1000)
            {
                // если адрес, то парсим все поля и структурируем функцией
                if ($param['Наименование поля в Амо'] === 'Адрес')
                {
                    $amo_data = parseAdress($crm->get_custom_field_value($order[$param["Сущность в Амо"]]["custom_fields_values"], $param["Имя или id поля в Амо"],true,true));
                }
                else
                    $amo_data = $crm->get_custom_field_value($order[$param["Сущность в Амо"]]["custom_fields_values"], $param["Имя или id поля в Амо"]);
            }
            else
            {
                $params = explode('.',$param['Имя или id поля в Амо']);
                $variable = $order[$param["Сущность в Амо"]];
                for ($i=0; $i < sizeof($params); $i++) {
                    $variable = isset($variable[$params[$i]])?$variable[$params[$i]]:"";
                }
                $amo_data =  isset($variable)?$variable:"";
            }
            //если есть префикс, топ подставляем его к полученным данным
            if (isset($param["Префикс"]))
                $amo_data = $param["Префикс"].$amo_data;

            //Формируем поля для заказа МойСклад
            //Если поле имеет тип метаданных, то делаем запрос в МойСклад
            $result = null;
            if ($param["Тип Данных в МойСклад"] === "meta" OR strlen($param["Тип Данных в МойСклад"]) > 20)
            {
                $type = (strlen($param["Имя или id поля в МойСклад"]) > 20)?"customentity/":$param["Имя или id поля в МойСклад"];
                $type .= (strlen($param["Тип Данных в МойСклад"]) > 20)?$param["Тип Данных в МойСклад"]:"";
                $getMetaData = $mystore->callFunc('/'.strtolower($type).'?filter='.http_build_query(array("name"=>$amo_data)),array(),'GET');
                if (isset($getMetaData["rows"][0]))
                {
                    $result = array("meta" => $getMetaData["rows"][0]["meta"]);
                }
            }
            else
            {
                $result = is_array($amo_data)?$amo_data:$amo_data."";
            }
            log_func($result, "connect amo and myStore data");

            //Формируем поле
            if (isset($result))
            {
                if (strlen($param["Имя или id поля в МойСклад"]) > 20)
                {
                    if (!isset ($data["attributes"]))
                        $data["attributes"] = [];

                    $data["attributes"][] = array(
                        "meta" => array(
                            "href" => $mystore->mystore_config["domain"]."/entity/".$param["Сущность в МойСклад"]."/metadata/attributes/".$param["Имя или id поля в МойСклад"],
                            "type" => "attributemetadata",
                            "mediaType"=>"application/json",
                        ),
                        "value" => $result,
                    );
                }
                else $data[$param["Имя или id поля в МойСклад"]] = $result;
            }
            $result = null;
        }
    }
    return $data;
}
    
function productsInfo() //получаем инофрмацию по тавару из заказа Амо и из МойСклад
{
    global $mystore, $crm, $order, $pricelist, $config_params, $action_params, $path, $dirname;
    $total_price = 0;
    $delivery_price = 0;
    $products = [];

    foreach ($order["lead"]["_embedded"]["catalog_elements"] as $prod) 
    {
        //получаем информацию о товаре из заказа Амо
        $get = $crm->Call_func('/api/v4/catalogs/'.$prod["metadata"]["catalog_id"].'/elements/'.$prod["id"]);
        if (isset($get["id"]))
        {
            $sku = $crm->get_custom_field_value(
                $get["custom_fields_values"],
                findField("Наименование поля в Амо","SKU","Имя или id поля в Амо",$config_params)
            );
            if (isset($sku))
            {
                $product = array(
                    "name" => $get["name"],
                    "quantity" => $prod["metadata"]["quantity"],
                    "sku" => 'article='.$sku,
                    "type" => "product",
                    "lastPrice" => 0,
                );
                $products[] = $product;
            }
        }
    }
    $product = null;

    //Добавляем продукты по доп. полям
    require_once($path.$dirname.'/actions/addCustomProducts.php');

    $productsResult = [];
    foreach ($products as $product) 
    {
        //получаем информацию по товару из МойСклад и его цену
        $request = $mystore->callFunc(
                '/'.$product["type"].'?filter='.$product["sku"],
                array(),
                'GET'
            );
        log_func($request, "myStore product data");
        if(isset($request["rows"][0]))
        {
            $request = $request["rows"][0];
            $product["ms_id"] = $request["id"];
            $product["ms_name"] = $request["name"];
            $product["meta"] = $request["meta"];
            //Если поле не заполнено в ручную в actions.php, то получаем информацию из запроса в мой склад
            if (!isset($product["ms_price"]))
            {
                $product["ms_price"] = 0;
                foreach ($request["salePrices"] as $price) {
                    if ($price["priceType"]["id"] === $pricelist)
                        $product["ms_price"] = isset($price["value"])?$price["value"]/100.0:$product["ms_price"];
                }
            }
        }
        
        if ($product["name"] === $mystore->mystore_config["delivery_name"])
                    $delivery_price = $product["ms_price"];

        $total_price += $product["name"] === $mystore->mystore_config["delivery_name"]?0:$product["ms_price"]*$product["quantity"];
        $productsResult[] = $product;
    }
    $products = $productsResult;
    if ($total_price == 0)
    {
        log_func([],"TOTAL PRICE IS NULL ERRRORRRR!!!!");
        $products = [];
    }

    // //Расчет конечной цены товара
    $productsResult = []; 
    foreach ($products as $prod) {
        if (isset($prod["meta"]))
        { 
            if ($prod["lastPrice"] == 0)
            {
                $last_price = $prod["ms_price"]*($order["lead"]["price"] - $delivery_price)/$total_price;
                $prod = array_merge($prod,array("lastPrice"=>$last_price));
            }
            $productsResult[] = $prod;
        }
        else
        {
            log_func($prod,"product not found in myStore ERRRORRRR!!!!");
            // die("product not found in myStore ERRRORRRR!!!!");
        }
    }
    return $productsResult;
}

function customer()
{
    global $mystore, $order, $config_params;

    //ищем по клиенту из Амо, создан ли уже покупатель в Мой склад
    foreach ($config_params as $param) {
        if ($param["Тип запроса"] == "counterparty" && $param["Имя или id поля в МойСклад"] == "name")
            $attribute_data[] = $param;
    }
    $data = fillFromAmoToMyStorebyCSV($attribute_data);
    $customer = $mystore->callFunc(
        '/counterparty?filter='.http_build_query($data),
        array(),
        'GET'
    );
    log_func($customer, "find customer in myStore");

    // Если контрагент (покупатель) не найден, создаем в МойСклад
    if(!isset($customer["rows"][0]))
    {
        //выбираем необходимые поля для заполнения
        $attribute_data = [];
        foreach ($config_params as $param) {
            if ($param["Тип запроса"] === "counterparty")
                $attribute_data[] =  $param;
        }
        $data = fillFromAmoToMyStorebyCSV($attribute_data);
        $data["companyType"] = "individual";
        log_func($data, "data to creation the customer in myStore");
        $customer =  $mystore->callFunc(
            '/counterparty',
            $data,
            'POST'
        );
        log_func($customer, "create customer in myStore");
    }
    else
    {
        $customer = $customer["rows"][0];
    }

    if(!isset($customer["id"]))
    {
        log_func([],"customer cannot be crearted ERRRORRRR!!!!");
        // die("customer cannot be crearted ERRRORRRR!!!!");
    }

    return $customer;
}

function newCustomerOrder($paymentInStatus = false)
{
    global $mystore, $crm, $order, $config_params, $customer, $products;

    $attribute_data = [];
    foreach ($config_params as $param) {
        if ($param["Тип запроса"] === "order")
            $attribute_data[] =  $param;
    }
    //создаем заказ МойСклад и передаем поля согласно fields.csv
    $data = fillFromAmoToMyStorebyCSV($attribute_data);
    $data["organization"] = array("meta" => $mystore->mystore_config["meta"]);
    $data["agent"] = array("meta" => $customer["meta"]);
    $data["name"] = $data["name"] . " " . date('d-M-Y H:m');
    
    //определяем, на какой счет зачислить оплату
    //задачем счет по-умолчанию
    $account = "/organization/dce190f4-e243-11ec-0a80-0b2700059912/accounts/848b70ec-a356-11ef-0a80-135d001268e0";
    //получаем массив custom fields
    $customFields = $order["lead"]["custom_fields_values"];
    //определяем метод оплаты
    $paymentMethod = getPaymentMethod($customFields) ?? "";
    //проверяем оплату через Intellect Money
    if ($paymentMethod === "Оплата через СБП") {
        $account = "/organization/dce190f4-e243-11ec-0a80-0b2700059912/accounts/c00f29f2-a35e-11ef-0a80-0bc20013e871";
    }
    //проверяем оплату наложкой через сдек
    if ($paymentMethod === "Оплата при получении") {
        $account = "/organization/dce190f4-e243-11ec-0a80-0b2700059912/accounts/17bc8c79-a369-11ef-0a80-176b0015b2ec";
    }
    //указываем правильный счет зачисления денег
    $data["organizationAccount"] = array(
            "meta" => array(
                "href" => $mystore->mystore_config["domain"].$account,
                "type" => "account",
                "mediaType"=>"application/json",
            )
        );
    $data["state"] = array(
            "meta" => array(
                "href" => $mystore->mystore_config["domain"]."/customerorder/metadata/states/dcfb2029-e243-11ec-0a80-0b270005993e",
                "type" => "state",
                "mediaType"=>"application/json",
            )
        );

 
    //Добавляем продукты к заказу
    $positions = [];
    foreach ($products as $product) {
        $positions[] = array(
            "quantity" => (float)$product["quantity"],
            "price" => $product["lastPrice"]*100.0,
            "vat" => 5,
            "assortment" => array("meta" => $product["meta"])
        );
    }
    $data["positions"] = $positions;
    log_func($data, "data for order creation");
  	log_func($data["positions"], "positions for order creation");
    $new_order = $mystore->callFunc('/customerorder',$data,'POST');
    log_func($new_order, "create new order");
    return $new_order;
}

function paymentIn($paymentInPrice = 0)
{
    global $new_order, $customer, $mystore;
    // Получаем шаблон платежа
    $paymentDraft = $mystore->callFunc('/paymentin/new',
            array( 
                "operations" => array(array("meta"=> $new_order["meta"]))
            ),
            'PUT'
    );
    $paymentDraft["agent"] = array("meta"=>$customer["meta"]);
    $paymentDraft["operations"] = array(array("meta"=> $new_order["meta"]));
    $paymentDraft["paymentPurpose"] = "Предоплата по заказу ".$new_order["name"];
    $paymentDraft["sum"] = $paymentInPrice*100.0;

    // создаем платеж на основе шаблона
    $newPaymentIn = $mystore->callFunc('/paymentin',$paymentDraft,'POST');
    log_func($newPaymentIn, "newPaymentIn");
}

function parseAdress($values = [])
{
    global $mystore;

    //Структура полей моего склада в терминах АмоCRM
    $myStoreAdressStructureArray = array(
        "postalCode"=>'zip',
        "country" => array("meta"=>'country'),
        "region" => array("meta"=>'state'),
        "city"=>"city",
        "street" => "address_line_1.0",
        "house" => "address_line_1.1",
        "apartment" => "address_line_1.2",
        "addInfo"=>''
    );
    foreach ($myStoreAdressStructureArray as $param_key => $param_value)
    {
        $initVal = $param_value;
        foreach ($values as $value)
        {
            if (is_array($param_value))
            {
                $search_param = array_values($param_value)[0];
                if (in_array($search_param, $value))
                {
                    $searching = ($search_param === "country") ? CountryCodes::get('alpha2', 'country', 'ru')[$value["value"]] : $value["value"];
                    $searchMS = isset($searching) ? $mystore->callFunc("/".$param_key."?filter=".http_build_query(array("name" => $searching)), array() , 'GET') : '';
                    $myStoreAdressStructureArray[$param_key] = isset($searchMS["rows"][0]) ? $searchMS["rows"][0] : null; 
                }
            }
            else
            {
                $search_param = explode(".",$param_value)[0];
                $indexSP = explode(".",$param_value)[1]?:0;
                if (in_array($search_param, $value))
                    $myStoreAdressStructureArray[$param_key] = explode(",",$value["value"])[$indexSP]?:''; 
            }
        }
        if ($initVal == $myStoreAdressStructureArray[$param_key])
            unset($myStoreAdressStructureArray[$param_key]);
    }
    return $myStoreAdressStructureArray;
}

function getPaymentMethod($customFields) {
    foreach ($customFields as $field) {
        if ($field["field_id"] == 421383) {
            return $field["values"][0]["value"];
        }
    }
    return null;
}

?>
