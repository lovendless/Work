<?php

if (isset($products))
{
	$delivery_price = $crm->get_custom_field_value($order["lead"]["custom_fields_values"],702533);
	if ($delivery_price > 0) 
	{
		$products[] = array(
			"name" => "Доставка",
			"quantity"=>"1",
			"sku"=>"code=4687203150879",
			"type" => "service",
			"lastPrice" => $delivery_price,
			"ms_price" => $delivery_price,
		);

	}		
}
?>