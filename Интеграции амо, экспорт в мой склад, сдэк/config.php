<?php

$dirname = "/public_html/support/amo_mystore/scripts";
$path = substr( dirname(  __FILE__ ), 0, -strlen($dirname));
// $database = $path."/db_store.db";
$database = "db_store.db";

$crm_config = array (
	"domain" => 'https://test.amocrm.ru',
	'client_id' => "7246a336-0343-4a25-b2a5-3a233992da23",
	"client_secret" => "RVVFOjHjXAaVtJ7FtVOxDkRHewWKGN2bZCe9TUOmxsPygn60HugwDtRGjHTjfiHr",
	"redirect_uri" => "https://vivaherb.ru/support/amo_mystore/scripts/amo_ms_export-order.php",
	'db_config_table' => "db_config",
	'crm_code' => "def50200c9468f94acaf129ccfcd543176ea40dca709079dd7b4e09010d4b5691d31ec0515c1022697d4af97a8bcd08f7fc48deef39bd5c5a5f83beb5d09037800a21e07adec651a616cd207d15bc7bc5ca86949af3d76967c5e91e4189243eedf636cd9491668d896fb22386723872e45eeef2214b0d0b4e07b1aa73b4e955c49484c45bd90a30e135cc1867909c345cdd0c142a33a850c497411cb538ce310246b03ba755857c9d55850f69c2f1eaf3eb19c094d3675ecd8cec2e5d2254e07f1e06bab446ae7d0547e3faf38c507735d7294d7006aa5d44d608b10c6a6487b279305e3d876c49e1771762cc66430f2ffce1f061958f5e3d4b11dbf3ce2b0c0a34dd448a25231ec55a261150b646c376505ddab646013848e421f4d142cda88874645fde4462b8ee4fa5ea17edae6255c8715bf3a91261627c63739a8aa9af85099868d4aafb8cb1407bc55e2276625de1e08bfec402996ee0be9b93843f5dade70b30abb42687e611bc660ce50817e77d68b616b649eff1c4971a0d76b0bdf6f02afacdbb8038ed8d55f158200cd127b3c326ef1935a214c42f402577c77dc48cf0b84f3f16d8bb4d0f1d90c828335a186d4a29da522b941a7554ab81fe4bc772dcb35ed1a6b198182058e3645824f9aa1c3c72710032ebfed9eacba4723c7a484931a2ffa41a923d2ae92445d2fde99ac4dcaf91eea6e32954a6401493bab1c70c60f79c00814c6aa83f0ad6b2a3f6d09ab59f45b7d62114869",
);

$mystore_config = array(
	"domain" => 'https://api.moysklad.ru/api/remap/1.2',
	"login" => "admin@test",
	"pass" => "tester",
	"delivery_name" => "Доставка",
	"meta" => array(
		"href"=>"https://api.moysklad.ru/api/remap/1.2/entity/organization/dce190f4-e243-11ec-0a80-0b2700059912",
		"type" => "organization",
		"mediaType" => "application/json",
	),
	'db_config_table' => "db_mystore_config",
);

$cdek_config = array(
	"domain" => 'https://api.cdek.ru/v2',
  	"secret" => 'TESTEKEY',
	"client_id" => "xBV7lkyz0lD1ds85iNc3HCQ76vaHDivw",
	"client_secret" => "axyTgH8Z2HaKuk4dJqffL3EtUpqdgaJr",
	'db_config_table' => "db_cdek_config",
  	"token_file" => __DIR__ . '/cdek_token.json',
);

$ozon_config = array(
	"client_id" => "",
	"client_secret" => "",
);

$wb_config = array(
	"client_secret_stat" => "",
	"client_secret_stand" => ""
);

//token валиден до 20.11.2024
$yast_config = array(
	"businessID" => "",
	"companyID" => "",
	"token" => ""
);