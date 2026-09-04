<?php
class cdek {
	var $db_config = null;
	var $db_config_table = null;
	var $cdek_config = array();

	function __construct() {
		global $database;
		global $cdek_config;

		$this->db_config = new SQLite3($database);
		$this->cdek_config = array (
			"domain" => $cdek_config["domain"],
			"client_id" => $cdek_config["client_id"],
			"client_secret" => $cdek_config["client_secret"],
		);
		$this->db_config_table = $cdek_config["db_config_table"];
	}
	
	function First_Auth() {
		$this->db_config->exec("CREATE TABLE IF NOT EXISTS ".$this->db_config_table." (name TEXT PRIMARY KEY NOT NULL , value TEXT NOT NULL, expired INTEGER );");
		$data = $this->cdek_config;
		$data["domain"] = null;
		$data["grant_type"] = "client_credentials";

		$curl1 = curl_init();//Инициализируем переменную CURL
		curl_setopt_array($curl1,array(
		  CURLOPT_URL => $this->cdek_config["domain"]."/oauth/token?parameters",
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_POST => true,
		  CURLOPT_POSTFIELDS => http_build_query($data)
		));
		$result = curl_exec($curl1);
		curl_close($curl1);
		$result = json_decode($result,true);
		if (isset($result["access_token"]))
		{
			log_func($result, "First CDEK Authorize success", true);
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','".$result["access_token"]."','".(time()+intval($result["expires_in"]))."');");
		}
		else
		{
			log_func($result, "First CDEK Authorize failed");
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','','".time()."');");
			die("First CDEK Authorize failed");
		}
	}
	function get_file($link, $data = null, $http_method = 'GET')
	{
		$access_token = $this->db_select('WHERE name = "access_token";')[0];
		// Если ключа нет в бд, то создаем новый
		if (!isset($access_token))
		{
			print_r("1\n");
			$this->First_Auth();
			$access_token = $this->db_select('WHERE name = "access_token";')[0];
		}
		// Если истек период работы ключа
		if (time() >= $access_token["expired"])
		{
			print_r("2\n");
			$this->First_Auth();
			$access_token = $this->db_select('WHERE name = "access_token";')[0];
		}

		$curl1 = curl_init();
		curl_setopt_array($curl1,array(
		  CURLOPT_URL => $link,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_HTTPHEADER => ['Content-Type:application/json','Authorization: Bearer ' .$access_token["value"]]
		));
		if ($http_method == 'POST')
		{
			curl_setopt_array($curl1,array(
				CURLOPT_POST => 'POST',
		  		CURLOPT_POSTFIELDS => json_encode($data),
			));
		}
		$result = curl_exec($curl1);
		$code = curl_getinfo($curl1, CURLINFO_HTTP_CODE);
		curl_close($curl1);

		if ($code < 200 || $code > 204) {
			log_func($response,"CDEK request.php - ".$url.' '.$method.' '.$code.' ',true);
			$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','','".time()."');");
		}
		return $result;
	}

	function Call_func($link, $data = null, $http_method = 'GET') {
		$access_token = $this->db_select('WHERE name = "access_token";')[0];
		// Если ключа нет в бд, то создаем новый
		if (!isset($access_token))
		{
			print_r("1\n");
			$this->First_Auth();
			$access_token = $this->db_select('WHERE name = "access_token";')[0];
		}
		// Если истек период работы ключа
		if (time() >= $access_token["expired"])
		{
			print_r("2\n");
			$this->First_Auth();
			$access_token = $this->db_select('WHERE name = "access_token";')[0];
		}

		$result = [];
		if (!empty($access_token["value"]))
		{
			$curl1 = curl_init();
			curl_setopt_array($curl1,array(
			  CURLOPT_URL => $this->cdek_config["domain"].$link,
			  CURLOPT_RETURNTRANSFER => true,
			  CURLOPT_HTTPHEADER => ['Content-Type:application/json','Authorization: Bearer ' .$access_token["value"]]
			));
			if ($http_method == 'POST')
			{
				curl_setopt_array($curl1,array(
					CURLOPT_POST => 'POST',
			  		CURLOPT_POSTFIELDS => json_encode($data),
				));
			}
			$result = curl_exec($curl1);
			$code = curl_getinfo($curl1, CURLINFO_HTTP_CODE);
			curl_close($curl1);
			$result = json_decode($result,true);
			if ($code < 200 || $code > 204) {
				log_func($response,"CDEK request.php - ".$url.' '.$method.' '.$code.' ',true);
				$this->db_config->exec("INSERT OR REPLACE INTO ".$this->db_config_table." (name,value,expired) VALUES ('access_token','','".time()."');");
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