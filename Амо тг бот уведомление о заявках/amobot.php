<?php
require "class/Logger.php";
require "class/Messenger.php";
require "class/Request.php";

$logger = new Logger();
$messenger = new Messenger("6163791128:AAH0hSaOXi48Rd0sSZrBCTgZ1uFqEbhoja0");
$request = new Request();

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if ($data !== null) {
    $_POST = $data;
}

http_response_code(200);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		//$src = $_POST['unsorted']['add'][0]['source'] ?? "ошибочка";
		$deleted = $_POST['leads']['delete'] ?? "ошибочка";	//палим удаленные сделки
		$leadsAddName = $_POST['leads']['add'][0]['name'] ?? "ошибочка";
		$customFields = $data['leads']['add'][0]['custom_fields'] ?? "null";
		$unsortedLeadId = $_POST['unsorted']['add'][0]['lead_id'] ?? "ошибочка";
		$leadsAddId = $_POST['leads']['add'][0]['id'] ?? "ошибочка";
		$tags = $_POST['leads']['add'][0]['tags'][0]['name'] ?? "ошибочка";
		$wazzup = $_POST['unsorted']['add'][0]['source_data']['site'] ?? "ошибочка";
		$messageAdd0Origin	=	$_POST ['message']['add'][0]['origin'] ?? "ошибочка";
		$service = $_POST ['unsorted']['add'][0]['source_data']['service'] ?? "ошибочка";
		//$wazzupTxt = $_POST['unsorted']['add'][0]['source_data']['data'][0]['text'] ?? "ошибочка";
		$imeetda	=	$_POST ['unsorted']['add'][0]['source_data']['data']['2287897_2']['value']  ?? "не смог определить";
		$test = $_POST ['leads']['add'][0]['tags'][0]['name'] ?? "null";
		$testLead = $_POST ['leads']['add'][0]['id'] ?? "null";
		$unsortedAdd0Source = $_POST ['unsorted']['add'][0]['source'] ?? "null";
		$lead		=	$_POST ['unsorted']['add'][0]['lead_id']  ?? "не смог определить";
		$phoneNum	=	$_POST ['unsorted']['add'][0]['source_data']['data']['1271170_1']['value']  ?? "ошибка";
		$phoneNumCleaned = preg_replace('/\D/', '', $phoneNum);
		$phoneNumCute	=	substr($phoneNumCleaned, -7);
		$missedCall	=	$_POST ['task']['add'][0]['text']  ?? "не смог определить";
		$user_names = [
					9575258 => 'Виктория Уварова',
					10255154 => 'Есения Иванова',
					9885630 => 'Елена Кузнецова',
					8448205 => 'Анна Титкова',
					3381484 => 'Дарья Рерих',
					3381481 => 'Максим Ежов',
					7697797 => 'Вероника Сидорова',
					1425745 => 'Николай Муравьев',
					1540633 => 'Валерий Ларионов',
					7081546 => 'Алиса Линкер',
					11107086 => 'Марина Соколова',
					10840502 => 'Евгений Игнатенко',
					1082892 => 'Админ',
					0 => 'автоматически',
				];
		$pipelines = [
					305943 =>	'ВГС',
					3302728 =>	'Доппрепараты',
					7143782 =>	'Повторные продажи',
					739021 =>	'ГШ',
					3271213 =>	'Консультация 1000 руб',
					721828 =>	'Врачи',
					1249443 =>	'Оптовые продажи',
					6767446 =>	'Прогрев консультации',
					6804262 =>	'Прогрев консультации Б',
					6840486 =>	'Поиск аптек',
					7051622 =>	'test',
					8518542 =>	'Треш',
					8593918 =>	'Квота',
					8673674 =>	'Конкуренты'
				];
//-------------------------------------------------------------------------------------------------------------------Заявка
		if ($imeetda === "Да")
			{
		$imeet	=		$_POST ['unsorted']['add'][0]['source_data']['data']['2287897_2']['name']  ?? "не смог определить";
		//$imeetda	=	$_POST ['unsorted']['add'][0]['source_data']['data']['2287897_2']['value']  ?? "не смог определить";
		$vopros	=		$_POST ['unsorted']['add'][0]['source_data']['data']['2287899_2']['value']  ?? "не смог определить";
		$name	=		$_POST ['unsorted']['add'][0]['source_data']['data']['name_1']['value']  ?? "не смог определить";
		$utmSource =	$_POST ['unsorted']['add'][0]['source_data']['data']['2264715_2']['value']  ?? "не смог определить";
		$utmMedium =	$_POST ['unsorted']['add'][0]['source_data']['data']['2264717_2']['value']  ?? "не смог определить";
		$leadDesc	=	$_POST ['unsorted']['add'][0]['data']['leads'][0]['name']  ?? "не смог определить";
		$clientIp	=	$_POST ['unsorted']['add'][0]['source_data']['origin']['ip']  ?? "не смог определить";	
		$text = urlencode("❕ $leadDesc \n \n $name \n $imeet $imeetda \n Вопрос: $vopros \n $phoneNum \n $utmSource $utmMedium \n \n $clientIp \n Сделка: https://mygepatit.amocrm.ru/leads/detail/$lead");
		$messenger->sendMessage("-1001249246001", $text);
		// $logger->logToFile("log/amobot_unk", $_POST);
		}

//-------------------------------------------------------------------------------------------------------------------Заявка
		elseif ($test == "TeSt")
		{
		$testName = $_POST ['leads']['add'][0]['name'] ?? "null";
		$text = urlencode("❕ Новая заявка ❕
		
		Создан заказ на Teste
		
		👤 $testName
		
		https://mygepatit.amocrm.ru/leads/detail/$testLead");
		$messenger->sendMessage("-1001249246001", $text);
		// $logger->logToFile("log/amobot_testOrder", $_POST);
		}

//-------------------------------------------------------------------------------------------------------------------Пропущенный звонок
elseif (isset($_POST['leads']['note'][0]['note']['text'])) {
    $noteText = $_POST['leads']['note'][0]['note']['text'];
    $noteData = json_decode($noteText, true);

    if (isset($noteData['call_status']) && $noteData['call_status'] == 6 && $noteData['call_result'] == 'входящий') {
        $callResult = $noteData['call_result'] ?? "не определено";
        $phone = $noteData['PHONE'] ?? "не определено";
        $leadId = $_POST['leads']['note'][0]['note']['element_id'] ?? "не определено";
        $text = urlencode("📞 Звонил клиент, но никто не ответил
Телефон: $phone
Статус: $callResult / Недозвон!

https://mygepatit.amocrm.ru/leads/detail/$leadId");
        $messenger->sendMessage("-1002129599267", $text);
		// $logger->logToFile("log/amobot_missedCall2", $_POST);
    }
}
//-------------------------------------------------------------------------------------------------------------------Заявка
		elseif ($service == "com.wazzup24.wz" && strpos($_POST['unsorted']['add'][0]['source_data']['data'][0]['text'], 'ZDS-r') !== false) {
			$wzNum = $_POST ['unsorted']['add'][0]['data']['contacts'][0]['custom_fields'][0]['values'][0]['value'] ?? "не смог определить";
			$wzNumCleaned = preg_replace('/\D/', '', $wzNum);
			$wzNumCute = substr($wzNumCleaned, -7);
			$wzLead = $_POST['unsorted']['update'][0]['data']['leads.id'] ?? "не смог определить";

			$text = urlencode("❕ Новая заявка ❕
			👤 Клиент из wazzup
			☎️ ...$wzNumCute
			
			Источник: test
			https://mygepatit.amocrm.ru/leads/detail/$wzLead");
			$messenger->sendMessage("-1001249246001", $text);
			// $logger->logToFile("log/amobot_wazzupDeal", $_POST);
		}
//-------------------------------------------------------------------------------------------------------------------Заявка
		elseif ($wazzup == "VK") {
		$unsortedAdd0SourcedataName	=	$_POST ['unsorted'] ['add'] [0] ['source_data'] ['name']   ?? "не смог определить";
		$findAptekaSrc	=	$_POST ['unsorted']['add'][0]['source_data']['source_name'] ?? "не смог определить";
		$lead		=	$_POST ['unsorted']['add'][0]['lead_id']  ?? "не смог определить";
		$unsortedAddSourcedataDataText	=	$_POST ['unsorted'] ['add'] [0] ['source_data']['data'][0]['text']   ?? "пустое сообщение";
		$text = urlencode("❕ Новая заявка ❕
		👤 $unsortedAdd0SourcedataName
		
		Клиент написал в ВК:
		$unsortedAddSourcedataDataText
				
		Источник: $findAptekaSrc
		https://mygepatit.amocrm.ru/leads/detail/$lead");
		$messenger->sendMessage("-1001249246001", $text);
		// $logger->logToFile("log/amobot_vkDeal", $_POST);
		}
//-------------------------------------------------------------------------------------------------------------------Заявка		
		elseif ($tags == "WZ (Gydus)") {
		$text = urlencode("❕ Новая заявка ❕
		👤 $leadsAddName
		$tags
		https://mygepatit.amocrm.ru/leads/detail/$leadsAddId");
		$messenger->sendMessage("-1001249246001", $text);
		// $logger->logToFile("log/amobot_wzZydusDeal", $_POST);
		}
//-------------------------------------------------------------------------------------------------------------------Заявка с тильды
		elseif ($tags == "tilda") {
		$formName = findFieldValue($customFields, 'FORMNAME');
		$text = urlencode("❕ Новая заявка ❕
		👤 $leadsAddName
		Тип формы: $formName
		$tags
		https://mygepatit.amocrm.ru/leads/detail/$leadsAddId");
		$messenger->sendMessage("-1001249246001", $text);
		// $logger->logToFile("log/amobot_tildaDeal", $_POST);
		}
//-------------------------------------------------------------------------------------------------------------------Заявка через тг и ватсу
		elseif (isset($_POST['unsorted']['add'][0]['source_data']['site']) && 
        ($_POST['unsorted']['add'][0]['source_data']['site'] === "Wazzup" || 
         $_POST['unsorted']['add'][0]['source_data']['site'] === "Telegram" || 
         $_POST['unsorted']['add'][0]['source_data']['site'] === "whatsappWZ")) {
		$unsortedText = $_POST ['unsorted']['add'][0]['source_data']['data'][0]['text'];
		$text = urlencode("❕ Новая заявка ❕
		$unsortedText

		https://mygepatit.amocrm.ru/leads/detail/$unsortedLeadId");
		$messenger->sendMessage("-1001249246001", $text);
		// $logger->logToFile("log/amobot_waztg", $_POST);
		}
//-------------------------------------------------------------------------------------------------------------------Заявка
		elseif ($unsortedAdd0Source == "https://ruwebservice.ru") {
			$contactName = $_POST ['unsorted']['add'][0]['source_data']['data']['name_1']['value'] ?? "null";
			$trafficSource = $_POST ['unsorted']['add'][0]['source_data']['data']['2003389_2']['value'] ?? "null";
			$utmSource	=	$_POST ['unsorted']['add'][0]['source_data']['data']['2264715_2']['value'] ?? "null";
			$utmTerm	=	$_POST ['unsorted']['add'][0]['source_data']['data']['2264721_2']['value'] ?? "null";
			$leadname	=	$_POST ['unsorted']['add'][0]['data']['leads'][0]['name'];
			
			
			$text = urlencode("❕ Новая заявка ❕\n
			👤 $contactName\n
			☎️ ...$phoneNumCute\n
			TraffSrc:$trafficSource
			UtmSrc:$utmSource
			UtmTerm:$utmTerm
			(tildaform)
			https://mygepatit.amocrm.ru/leads/detail/$lead");
			$messenger->sendMessage("-1001249246001", $text);
			// $logger->logToFile("log/amobot_unkDeal", $_POST);
		}
}

//$logger->logToFile("log/amobot_ALL", $_POST);

function findFieldValue($customFields, $fieldName) {
    foreach ($customFields as $field) {
        if ($field['name'] === $fieldName) {
            foreach ($field['values'] as $value) {
                if (isset($value['value'])) {
                    return $value['value'];
                }
            }
        }
    }
    return null;
}

echo "ok";
?>