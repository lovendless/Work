<?php
class mystore {
	var $db_config = null;
	var $db_config_table = null;
	var $mystore_config = array();

	function __construct() {
		global $database;
		global $mystore_config;

		$this->db_config = new SQLite3($database);
		$this->mystore_config = array (
			"domain" => $mystore_config["domain"],
			"login" => $mystore_config["login"],
			"pass" => $mystore_config["pass"],
			"meta" => $mystore_config["meta"],
			"delivery_name" => $mystore_config["delivery_name"],
		);
		$this->db_config_table = $mystore_config["db_config_table"];
	}
	
	function First_Auth() {
		$this->db_config->exec("CREATE TABLE IF NOT EXISTS ".$this->db_config_table." (name TEXT PRIMARY KEY NOT NULL , value TEXT NOT NULL, expired INTEGER );");
		// print_r(base64_encode($this->mystore_config["login"].":".$this->mystore_config["pass"]));
		$header =  ["Authorization: Basic ".base64_encode($this->mystore_config["login"].":".$this->mystore_config["pass"]),'Accept-Encoding: gzip'];

		$result = gziprequest($this->mystore_config["domain"].'/security/token', [], 'POST', $header);
		if (isset($result["access_token"]))
		{
			log_func($result, "First Authorize success");
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','".$result["access_token"]."',1);");
		}
		else
		{
			log_func($result, "First Authorize failed");
			die("First Authorize failed");
		}
	}

	function callFunc($link, $data = null, $http_method = 'GET',$header = [], $options = []) 
	{
		$access_token = !empty($this->db_select('WHERE name = "access_token";'))?$this->db_select('WHERE name = "access_token";')[0]:null;
		// Если ключа нет в бд, то создаем новый
		if (!isset($access_token))
			$this->First_Auth();
		// Если истек период работы ключа
		if ($access_token["expired"] == 0)
			$this->First_Auth();

		$access_token = $this->db_select('WHERE name = "access_token";')[0];
		if (empty($header))
            //log_func($access_token, "access_token!");
			$header =  ['Authorization: Bearer ' .$access_token["value"],'Content-Type: application/json','Accept-Encoding: gzip'];
		$result = gziprequest($this->mystore_config["domain"].'/entity'.$link, $data, $http_method, $header, $options);

		if (isset($result["error_code"]))
		{
			// log_func($result, "MyStore request Error!");
			if ($result["response"]["errors"][0]["code"] == 1056){
				$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','".$access_token["access_token"]."',0);");
              
               $this->First_Auth();
        	   $access_token = $this->db_select('WHERE name = "access_token";')[0];
              
               $header =  ['Authorization: Bearer ' .$access_token["value"],'Content-Type: application/json','Accept-Encoding: gzip'];
               // повторяем запрос
               $result = gziprequest($this->mystore_config["domain"].'/entity'.$link, $data, $http_method, $header, $options);
                      
		}
        }
		return $result;
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