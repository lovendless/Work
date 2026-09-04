<?php
$order_id = isset($_POST["order_id"])?$_POST["order_id"]:"null";
$amount = isset($_POST["amount"])?$_POST["amount"]:"null";
$client_id = isset($_POST["client_id"])?$_POST["client_id"]:"null";
$client_name = isset($_POST["client_name"])?$_POST["client_name"]:"null";
$ts = isset($_POST["ts"])?$_POST["ts"]:"null";
?>
<form action="https://merchant.intellectmoney.ru/ru/" id="pay" name="pay" method="POST">
     <input type="hidden" name="eshopId" value="462337">
     <input type="hidden" name="orderId" value=<?php echo $order_id?>>
     <input type="hidden" name="ServiceName" value="Оплата заказа">
     <input type="hidden" name="recipientAmount" value=<?php echo $amount?>>
     <input type="hidden" name="recipientCurrency" value="RUB">
     <input type="hidden" name="successUrl" value="https://test.ru/IMpayment/success.php">
     <input type="hidden" name="failUrl" value="https://test.ru/IMpayment/error.php">
</form>
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
  document.getElementById("pay").submit();
</script>
</html>