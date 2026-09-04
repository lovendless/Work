<?php

if (isset($order["lead"]))
{
	if ($order["lead"]["pipeline_id"] == 4930456)
	{
		for ($i=0; $i < sizeof($config_params); $i++)
		{	
			if ($config_params[$i]["Наименование поля в Амо"] == "Проект") $config_params[$i]["Префикс"] = "Блогеры";
		}
	}
}
?>