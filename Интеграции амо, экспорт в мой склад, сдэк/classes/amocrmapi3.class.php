<?php
class amocrmapi3 {
	var $db_config = null;
	var $db_config_table = null;
	var $crm_config = array();
	var $crm_domain = null;
	var $crm_code = null;

	function __construct() {
		global $database;
		global $crm_config;

		$this->db_config = new SQLite3($database);
		$this->crm_config = array (
			"client_id" => $crm_config["client_id"],
			"redirect_uri" => $crm_config["redirect_uri"],
			"client_secret" => $crm_config["client_secret"],
		);
		$this->db_config_table = $crm_config["db_config_table"];
		$this->crm_domain = $crm_config["domain"];
		$this->crm_code = $crm_config["crm_code"];
	}

	//запрос ключа в Амо. Обязательно обновить в ручную crm_code в config.php
	function First_Auth() {
		$this->db_config->exec("CREATE TABLE IF NOT EXISTS ".$this->db_config_table." (name TEXT PRIMARY KEY NOT NULL , value TEXT NOT NULL, expired INTEGER );");
		$data = $this->crm_config;
		$data["code"] = $this->crm_code;
		$data ["grant_type"] = "authorization_code";
		// print_r($data);
		$result = request($this->crm_domain.'/oauth2/access_token', $data, 'POST', ['Content-Type:application/json']);
		if (isset($result["access_token"]))
		{
			log_func($result, "First CRM Authorize success");
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','".$result["access_token"]."',".(time()+$result["expires_in"]).");");
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('refresh_token','".$result["refresh_token"]."',".(time()+$result["expires_in"]).");");
		}
		else
		{
			log_func($result, "First CRM Authorize failed");
			die("First CRM Authorize failed");
		}
	}

	//функция обновления ключа
	function Refresh_Auth() {
		$data = $this->crm_config;
		$data ["grant_type"] = "refresh_token";
		$data ["refresh_token"] = $this->db_select('WHERE name = "refresh_token";')[0]["value"];
		// print_r($data);
		$result = request($this->crm_domain.'/oauth2/access_token', $data, 'POST',['Content-Type:application/json']);
		if (isset($result["access_token"]))
		{
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','".$result["access_token"]."',".(time()+$result["expires_in"]).");");
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('refresh_token','".$result["refresh_token"]."',".(time()+$result["expires_in"]).");");
		}
		else
		{
			log_func($result, "CRM Authorize failed");
			die("CRM Authorize failed");
		}
	}
	
	//Функция отправки запроса к АмоCRM
	function Call_func($link, $data = null, $http_method = 'GET') {
		$access_token = $this->db_select('WHERE name = "access_token";')[0];
		// Если ключа нет в бд, то создаем новый
		if (!isset($access_token))
		{
			$this->First_Auth();
			$access_token = $this->db_select('WHERE name = "access_token";')[0];
		}
		// log_func(time(), "CRM request localtime");
		// log_func($access_token, "CRM access_token");
		// Если истек период работы ключа
		if (time() >= $access_token["expired"])
		{
			$this->Refresh_Auth();
			$access_token = $this->db_select('WHERE name = "access_token";')[0];
		}

		$result = request($this->crm_domain.$link, $data, $http_method, ['Content-Type:application/json','Authorization: Bearer ' .$access_token["value"]]);	
		return $result;
	}

	// get custom field value by name or id from convert array
	function get_custom_field_value ($custom_field = [], $field_id, $end=true, $getAll = false){
		if (!isset($custom_field) || !isset($field_id)) return null;
		foreach ($custom_field as $value) {
			if (in_array($field_id, $value)) 
			{
				if ($getAll) 
					return $value["values"];
				else
					return $end ? end($value["values"])["value"] : $value["values"][0]["value"];
			}
		}
		return 0;
	}
	// get custom field id by name or id from convert array
	function get_custom_field_id ($custom_field = [], $field_id, $end=true){
		if (!isset($custom_field) || !isset($field_id)) return null;
		foreach ($custom_field as $value) {
			if (in_array($field_id, $value)) return $end ? end($value["values"])["enum_id"] : $value["values"][0]["enum_id"];
		}
		return 0;
	}

	function db_select($request)
	{
		$result = $this->db_config->query('SELECT * FROM '.$this->db_config_table.' '.$request);

		$row = array();
		if (!is_bool($result))
		{
			while($res = $result->fetchArray(SQLITE3_ASSOC))
			{
				array_push($row,$res);
			}
		}
		return $row;
	}

	function __destruct (){
		$this->db_config->close();
	}
}

?>