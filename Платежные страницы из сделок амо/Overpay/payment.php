<?php
require_once ('init.php');
require_once ('request.php');

$data = array_merge($_POST, (array) json_decode(file_get_contents('php://input')));

// $data = array("amount"=>"100","order_id"=>"112412","currency"=>"RUB");

$amount = isset($data["amount"])?intval($data["amount"])*100:0;
$order_id = isset($data["order_id"])?$data["order_id"]:0;
$currency = isset($data["currency"])?$data["currency"]:'none';
$client_name = isset($data["client_name"])?$data["client_name"]:'none';
$ts = isset($data["ts"])?$data["ts"]:'';

wh_log("overpay_payment.php - ".date('Y-m-d H:i:s ').json_encode($data)."\n");

$transaction_id = uniqid();

$data_ = array( "checkout" => 
	array(
		"test" => false,
		"transaction_type" => "payment",
		"attempts" => 3,
		"settings" => array(
			"language" => "ru",
			"success_url" => "https://test.ru/Overpay/success.php"
		),
		"payment_method" => array(
			"types" => ["credit_card"]
		),
		"order" => array(
			"currency" => $currency,
			"amount" => $amount,
			"description"=> $order_id,
			"tracking_id" => $transaction_id
		)
	)
);

$url = $ext_url."/checkouts";
$headers = ['Authorization: Basic '.base64_encode($shopId.':'.$secretKey),'Content-Type: application/json', 'Accept: application/json', 'X-API-Version: 2'];

wh_log("overpay_payment.php - ".date('Y-m-d H:i:s ').json_encode($data_)."\n");

$res = request($url,'POST', $data_, $headers);
$res = json_decode($res,true);

$redurect_url = "error.php";

if( isset($res['checkout']))
{
     if ( isset($res['checkout']['redirect_url'] )) 
     {
        $redurect_url = $res['checkout']['redirect_url'];
    }
}
wh_log("order-overpay_payment.php - ".date('Y-m-d H:i:s ').json_encode($res)."\n");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
	<style>
		body {
		background: rgba(240, 240, 240, 0.5); /* Цвет фона */
		}
	</style>
</head>
<body>
	 <img src="images/ajax_loader.png" style="width: 4%; display: block; margin: 0 auto; margin-top: 20%;">
<script>
	  function setCookie(name,value,days) {
	      var expires = "";
	      if (days) {
	          var date = new Date();
	          date.setTime(date.getTime() + (days*24*60*60*1000));
	          expires = "; expires=" + date.toUTCString();
	      }
	      document.cookie = name + "=" + (value || "")  + expires + "; path=/";
	  }
	  var params = {
	      client_id: '<?php echo $client_id?>',
	      amount: <?php echo $amount?>,
	      order_id: <?php echo $order_id?>,
	      client_name: '<?php echo $client_name?>',
	      ts: '<?php echo $ts?>'
	  };
	  setCookie('yaparama', JSON.stringify(params), 7);
    window.location.href = "<?php echo $redurect_url; ?>";
</script>
</body>
</html>