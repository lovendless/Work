<?php
  /**
  * Template Name: Invoice-pay7
  */
  
include "invoice-init.php";
get_header(); ?>

<link href="https://fonts.googleapis.com/css?family=Exo+2" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<?php include "invoice-style.php"; ?>
    

    <div class="container">


        <div class="column1">
            <p class="text3">Адрес доставки</p>
            <hr>
            <p style="white-space:pre-wrap; line-height:1.4;"><?php echo ($result['contact']['destination']); ?></p>
            <div class="col2" style="margin:20px;line-height:1.4">
                Внимательно проверьте детали вашего заказа. Для внесения изменений свяжитесь со своим персональным менеджером.
            </div>
        </div>
		
        <div class="column2">
            <div class="col2">
                <p class="text3">Ваш заказ</p>
                <hr>
				<p style="white-space:pre-wrap; line-height:1.4;"><?php echo htmlspecialchars($result['order']['drug']); ?></p>
                <hr>
                <div class="col">
                    <div>
                        <p style="white-space:pre-wrap; line-height:1.4;">ИТОГО: </p>
                    </div>
                    <div>
                        <span class="text4"><?php echo htmlspecialchars($result['order']['price']); ?> Руб.</span>
                    </div>
                </div><hr>

            </div>
			
<div class="button-container">
    <!-- Форма для оплаты по СБП -->
    <form id="paymentFormSBP" method="GET" action="https://test.ru/IMpayment/paymentIP.php" style="display:none;">
        <input type="hidden" name="amount" value="<?php echo htmlspecialchars($result['order']['price']); ?>">
        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($result['order']['id']); ?>">
        <input type="hidden" name="email" value="miron.meel@gmail.com">
        <input type="hidden" name="currency" value="RUB">
        <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($result['order']['id']); ?>">
        <input type="hidden" name="ts" value="gsh">
    </form>
    <a class="button" href="" onclick="return submitForm('paymentFormSBP');">ПЕРЕЙТИ К ОПЛАТЕ</a>
</div>

        </div>
    </div>

    <div class="other">
        <div class="column3"><span class="text4">Другие способы оплаты</span><hr>
		<!---Выберите удобный способ оплаты -->

            <button class="accordion" onclick="toggleAccordion(this)">
					<img src="/images/T-Bank.png" alt="Перевод через Т-Банк">
					<span class="accordion-text" style="font-weight:700;">Перевод через Т-Банк</span>
				</button>
				
             <div class="panel">
				<ol class="numbered-list">
						<?php if ($selectedTbankHolder['method'] == "phone"): ?>
							<li><span class="list-text">Откройте мобильное приложение банка</span></li>
							<li><span class="list-text">Выберите перевод по <b>СБП</b></span></li>
							<li><span class="list-text">Переведите нужную сумму по номеру телефона указанному ниже:</span></li>
							
						<?php elseif ($selectedTbankHolder['method'] == "card"): ?>
							<li><span class="list-text">Откройте мобильное приложение банка</span></li>
							<li><span class="list-text">Выберите перевод по <b>СБП</b></span></li>
							<li><span class="list-text">Переведите нужную сумму по номеру карты указанному ниже:</span></li>
						<?php elseif ($selectedTbankHolder['method'] == "req"): ?>
							<li><span class="list-text">Откройте мобильное приложение банка</span></li>
							<li><span class="list-text">Выберите перевод по <b>номеру счета</b></span></li>
							<li><span class="list-text">Переведите нужную сумму по номеру счета указанному ниже:</span></li>
						<?php endif; ?>
                </ol>
                <div id="ph-target" class="ph">
				<div class="loading-indicator" style="display: none;">
					<div class="spinner" style="text-align:center;margin-left:auto;margin-right:auto;"></div>
						<p style="font-size: 15px; text-align: center;">Выполняется подготовка реквизитов</p>
				</div>
				
					<div class="info-block column4" style="margin-top:-30px;">
                        <p style="font-size: 16px;max-width:320px;"><span class="text5">
							<?php if ($selectedTbankHolder['method'] == "phone"): ?>
							Номер телефона</span><br/><span id="phone-numbertbank" class="phone-number" style="font-weight:700;"><?php echo $selectedTbankHolder['phone']; ?></span>
							<?php elseif ($selectedTbankHolder['method'] == "card"): ?>
							Номер карты</span><br/><span id="phone-numbertbank" class="phone-number" style="font-weight:700;"><?php echo $selectedTbankHolder['cardNum']; ?></span>
							<?php elseif ($selectedTbankHolder['method'] == "req"): ?>
							Номер счета</span><br/><span id="phone-numbertbank" class="phone-number" style="font-weight:700;"><?php echo $selectedTbankHolder['req']; ?></span>
						<?php endif; ?>
						
						<a href="#" class="copy-button" onclick="copyNumberTbank()" title="Копировать"><i class="fas fa-copy"></i></a>
                        <p style="font-size: 16px;"><span class="text5">Получатель</span><br/><span style="color: #000; font-weight:700;"><?php echo $selectedTbankHolder['name']; ?></span></p>
						<p style="font-size: 16px;"><span class="text5">Банк получателя</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo $selectedTbankHolder['bank']; ?></span></p>
                        <p style="font-size: 16px;"><span class="text5">Сумма</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo htmlspecialchars($result['order']['price']); ?> Руб.</span></p>
						
                    </div>
					<div class="column4 notify" style="color: #000;width: 320px;font-size: 16px;line-height:1.4;">Оплатите в течение 10-ти минут.<br/><br/>Для зачисления платежа отправьте скриншот сделанного перевода персональному менеджеру.</div>
                </div>
                <hr style="margin:10px;">
                <ul class="custom-list">
                    <li>
                        <span class="list-text">В комментарии ничего не указывайте. Это может затруднить распознавание платежа</span>
                    </li>
                </ul>
            </div>


            <button class="accordion" onclick="toggleAccordion(this)">
                <img src="/images/sberlogo.png" alt="Перевод на Сбербанк">
                <span class="accordion-text" style="font-weight:700;">Перевод через Сбербанк</span>
            </button> 
			
				<div class="panel">
			<?php if ($selectedSberbankHolder['method'] == "phone"): ?>
				<ol class="numbered-list">
						<li><span class="list-text">Перейдите по ссылке <a href="https://www.sberbank.com/sms/pbpn?requisiteNumber=<?php echo $selectedSberbankHolder['num']; ?>">www.sberbank.com</a></span></li>
						<li><span class="list-text">Выполните перевод на сумму <span style="color: #000; font-weight:700;" class="price"></span></span></li>
					</ol>
					<p style="font-weight:700;font-size:16px;color:#000;margin:0px;">ИЛИ</p>
                <ol class="numbered-list">
                    <li><span class="list-text">Откройте мобильное приложение банка</span></li>
                    <li><span class="list-text">Выберите перевод по <b>СБП</b></span></li>
                    <li><span class="list-text">Переведите нужную сумму по номеру телефона указанному ниже:</span></li>
                </ol>
			<?php elseif ($selectedSberbankHolder['method'] == "card"): ?>
				<ol class="numbered-list">
						<li><span class="list-text">Перейдите по ссылке <a href="https://www.sberbank.com/sms/pbpn?requisiteNumber=<?php echo $selectedSberbankHolder['num']; ?>">www.sberbank.com</a></span></li>
						<li><span class="list-text">Выполните перевод на сумму <span style="color: #000; font-weight:700;" class="price"></span></span></li>
					</ol>
					<p style="font-weight:700;font-size:16px;color:#000;margin:0px;">ИЛИ</p>
                <ol class="numbered-list">
                    <li><span class="list-text">Откройте приложение своего мобильного банка</span></li>
                    <li><span class="list-text">Выберите перевод по <b>номеру карты</b></span></li>
                    <li><span class="list-text">Переведите нужную сумму по номеру карты указанному ниже:</span></li>
                </ol>
			<?php elseif ($selectedSberbankHolder['method'] == "req"): ?>
				<ol class="numbered-list">
						<li><span class="list-text">Перейдите по ссылке <a href="https://www.sberbank.com/sms/pbpn?requisiteNumber=<?php echo $selectedSberbankHolder['num']; ?>">www.sberbank.com</a></span></li>
						<li><span class="list-text">Выполните перевод на сумму <span style="color: #000; font-weight:700;" class="price"></span></span></li>
					</ol>
					<p style="font-weight:700;font-size:16px;color:#000;margin:0px;">ИЛИ</p>
                <ol class="numbered-list">
                    <li><span class="list-text">Откройте приложение своего мобильного банка</span></li>
                    <li><span class="list-text">Выберите перевод по <b>номеру счета</b></span></li>
                    <li><span class="list-text">Переведите нужную сумму по номеру счета указанному ниже:</span></li>
                </ol>
			<?php endif; ?>
                <div id="ph-target" class="ph">
				        <!-- Индикатор загрузки -->
						<div class="loading-indicator" style="display: none;">
							<div class="spinner" style="text-align:center;margin-left:auto;margin-right:auto;"></div>
								<p style="font-size: 15px; text-align: center;">Выполняется подготовка реквизитов</p>
						</div>
                    <div class="info-block column4" style="margin-top:-30px;">
                        <p style="font-size: 16px;max-width:320px;"><span class="text5">
							<?php if ($selectedSberbankHolder['method'] == "phone"): ?>
							Номер телефона</span><br/><span id="phone-numbersber" class="phone-number" style="font-weight:700;"><?php echo $selectedSberbankHolder['phone']; ?></span>
							<?php elseif ($selectedSberbankHolder['method'] == "card"): ?>
							Номер карты</span><br/><span id="phone-numbersber" class="phone-number" style="font-weight:700;"><?php echo $selectedSberbankHolder['cardNum']; ?></span>
							<?php elseif ($selectedSberbankHolder['method'] == "req"): ?>
							Номер счета</span><br/><span id="phone-numbersber" class="phone-number" style="font-weight:700;"><?php echo $selectedSberbankHolder['req']; ?></span>
							<?php endif; ?>
						
						<a href="#" class="copy-button" onclick="copyNumberSber()" title="Копировать">
									<i class="fas fa-copy"></i>
								</a>
                            
                        <!---<p style="font-size: 16px;"><span class="text5">Банк получателя</span><br/><span style="color: #000; font-weight:700;">СБЕРБАНК</span></p> -->
                        <p style="font-size: 16px;"><span class="text5">Получатель</span><br/><span style="color: #000; font-weight:700;"><?php echo $selectedSberbankHolder['name']; ?></span></p>
						<p style="font-size: 16px;"><span class="text5">Банк получателя</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo $selectedSberbankHolder['bank']; ?></span></p>
                        <p style="font-size: 16px;"><span class="text5">Сумма</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo htmlspecialchars($result['order']['price']); ?> Руб.</span></p>
						
                    </div>
					<div class="column4 notify" style="color: #000;width: 320px;font-size: 16px;line-height:1.4;">Оплатите в течение 10-ти минут.<br/><br/>Для зачисления платежа отправьте скриншот сделанного перевода персональному менеджеру.</div>
                </div>
                <hr style="margin:10px;">
                <ul class="custom-list">
                    <li>
                        <span class="list-text">В комментарии ничего не указывайте. Это может затруднить распознавание платежа</span>
                    </li>
                </ul>
            </div>
			
			
			<button class="accordion" onclick="toggleAccordion(this)">
					<img src="/images/alphalogo.png" alt="Перевод через Альфа-банк">
					<span class="accordion-text" style="font-weight:700;">Перевод через Альфа-Банк</span>
				</button>
				
             <div class="panel">
				<ol class="numbered-list">
					<?php if ($selectedAlphaHolder['method'] == "phone"): ?>
						<li><span class="list-text">Откройте мобильное приложение банка</span></li>
						<li><span class="list-text">Выберите перевод по <b>СБП</b></span></li>
						<li><span class="list-text">Переведите нужную сумму по номеру телефона указанному ниже:</span></li>
					<?php elseif ($selectedAlphaHolder['method'] == "card"): ?>
						<li><span class="list-text">Откройте мобильное приложение банка</span></li>
						<li><span class="list-text">Выберите перевод по <b>номеру карты</b></span></li>
						<li><span class="list-text">Переведите нужную сумму по номеру карты указанному ниже:</span></li>
					<?php elseif ($selectedAlphaHolder['method'] == "req"): ?>
						<li><span class="list-text">Откройте мобильное приложение банка</span></li>
						<li><span class="list-text">Выберите перевод по <b>номеру счета</b></span></li>
						<li><span class="list-text">Переведите нужную сумму по номеру счета указанному ниже:</span></li>
					<?php endif; ?>
                </ol>
                <div id="ph-target" class="ph">
				<div class="loading-indicator" style="display: none;">
					<div class="spinner" style="text-align:center;margin-left:auto;margin-right:auto;"></div>
						<p style="font-size: 15px; text-align: center;">Выполняется подготовка реквизитов</p>
				</div>
					<div class="info-block column4" style="margin-top:-30px;">
					<?php if ($selectedAlphaHolder['method'] == "phone"): ?>
						<p style="font-size: 16px;max-width:320px;"><span class="text5">Номер телефона</span><br/>
						<span id="phone-numberalpha" class="phone-number" style="font-weight:700;"><?php echo $selectedAlphaHolder['phone']; ?></span>
					<?php elseif ($selectedAlphaHolder['method'] == "card"): ?>
						<p style="font-size: 16px;max-width:320px;"><span class="text5">Номер карты</span><br/>
						<span id="phone-numberalpha" class="phone-number" style="font-weight:700;"><?php echo $selectedAlphaHolder['cardNum']; ?></span>
					<?php elseif ($selectedAlphaHolder['method'] == "req"): ?>
						<p style="font-size: 16px;max-width:320px;"><span class="text5">Номер счета</span><br/>
						<span id="phone-numberalpha" class="phone-number" style="font-weight:700;"><?php echo $selectedAlphaHolder['req']; ?></span>
					<?php endif; ?>
						<a href="#" class="copy-button" onclick="copyNumberAlpha()" title="Копировать"><i class="fas fa-copy"></i></a>
                        <p style="font-size: 16px;"><span class="text5">Получатель</span><br/><span style="color: #000; font-weight:700;"><?php echo $selectedAlphaHolder['name']; ?></span></p>
						<p style="font-size: 16px;"><span class="text5">Банк получателя</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo $selectedAlphaHolder['bank']; ?></span></p>
                        <p style="font-size: 16px;"><span class="text5">Сумма</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo htmlspecialchars($result['order']['price']); ?> Руб.</span></p>
						
                    </div>
					<div class="column4 notify" style="color: #000;width: 320px;font-size: 16px;line-height:1.4;">Оплатите в течение 10-ти минут.<br/><br/>Для зачисления платежа отправьте скриншот сделанного перевода персональному менеджеру.</div>
                </div>
                <hr style="margin:10px;">
                <ul class="custom-list">
                    <li>
                        <span class="list-text">В комментарии ничего не указывайте. Это может затруднить распознавание платежа</span>
                    </li>
                </ul>
            </div>
			
			
			<button class="accordion" onclick="toggleAccordion(this)">
                <img src="/images/card2card2.png" alt="Перевод через другие банки">
                <span class="accordion-text" style="font-weight:700;">Перевод через другие банки</span>
            </button>
		
            <div class="panel">
                <ol class="numbered-list">
                    <?php if ($selectedOtherHolder['method'] == "phone"): ?>
						<li><span class="list-text">Откройте мобильное приложение банка</span></li>
						<li><span class="list-text">Выберите перевод по <b>СБП</b></span></li>
						<li><span class="list-text">Переведите нужную сумму по номеру телефона указанному ниже:</span></li>
					<?php elseif ($selectedOtherHolder['method'] == "card"): ?>
						<li><span class="list-text">Откройте мобильное приложение банка</span></li>
						<li><span class="list-text">Выберите перевод по <b>номеру карты</b></span></li>
						<li><span class="list-text">Переведите нужную сумму по номеру карты указанному ниже:</span></li>
					<?php elseif ($selectedOtherHolder['method'] == "req"): ?>
						<li><span class="list-text">Откройте мобильное приложение банка</span></li>
						<li><span class="list-text">Выберите перевод по <b>номеру счета</b></span></li>
						<li><span class="list-text">Переведите нужную сумму по номеру счета указанному ниже:</span></li>
					<?php endif; ?>
                </ol>

                <div id="ph-target" class="ph">
					<div class="loading-indicator" style="display: none;">
						<div class="spinner" style="text-align:center;margin-left:auto;margin-right:auto;"></div>
							<p style="font-size: 15px; text-align: center;">Выполняется подготовка реквизитов</p>
					</div>
                           <div class="info-block column4" style="margin-top:-30px;">
							<p style="font-size: 16px;max-width: 300px;">
								<?php if ($selectedOtherHolder['method'] == "phone"): ?>
								<span class="text5">Номер телефона</span><br/>
								<span id="phone-numberotherbank" class="phone-number" style="font-weight:700;"><?php echo $selectedOtherHolder['phone']; ?></span>
								<?php elseif ($selectedOtherHolder['method'] == "card"): ?>
								<span class="text5">Номер карты</span><br/>
								<span id="phone-numberotherbank" class="phone-number" style="font-weight:700;"><?php echo $selectedOtherHolder['cardNum']; ?></span>
								<?php elseif ($selectedOtherHolder['method'] == "req"): ?>
								<span class="text5">Номер счета</span><br/>
								<span id="phone-numberotherbank" class="phone-number" style="font-weight:700;"><?php echo $selectedOtherHolder['req']; ?></span>
								<?php endif; ?>
								
								<a href="#" class="copy-button" onclick="copyNumberOtherBank()" title="Копировать">
									<i class="fas fa-copy"></i>
								</a>
							</p>
							<p style="font-size: 16px;"><span class="text5">Получатель</span><br/><span style="color: #000; font-weight:700;"><?php echo $selectedOtherHolder['name']; ?></span></p>
							<p style="font-size: 16px;"><span class="text5">Банк получателя</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo $selectedOtherHolder['bank']; ?></span></p>
							<p style="font-size: 16px;"><span class="text5">Сумма</span><br/><span style="color: #000; font-weight:700;" class="price"><?php echo htmlspecialchars($result['order']['price']); ?> Руб.</span></p>
							
                    </div>
                    <div class="column4 notify" style="color: #000;width: 320px;font-size: 16px;line-height:1.4;">Оплатите в течение 10-ти минут.<br/><br/>Для зачисления платежа отправьте скриншот сделанного перевода персональному менеджеру.</div>
                </div>
                <hr style="margin:10px;">
                <ul class="custom-list">
                    <li>
                        <span class="list-text">В комментарии ничего не указывайте. Это может затруднить распознавание платежа</span>
                    </li>
                </ul>
            </div>
			
			
        </div>
    </div>

    <div class="footer2">
        <div style="padding: 10px;">
            <img width="100" src="/images/cadr-mc.png" alt="card">
        </div>
        <div style="padding: 10px;">
            <img width="100" src="/images/card-visa.png" alt="card">
        </div>
        <div style="padding: 10px;">
            <img width="100" src="/images/card-mir.png" alt="card">
        </div>
        <div style="padding: 10px;">
            <img width="100" src="/images/card-sbp.png" alt="card">
        </div>
    </div>
    <div class="footer3">
        <div class="column2">
            <p>Generic Shop © 2024 | Все права защищены</p>
        </div>
        <div class="column2" style="text-decoration:underline; text-align:right;">Политика конфиденциальности</div>
    </div>
	
	</div>
	<script>
    var obj; // Глобальная переменная для хранения данных заказа

    function toggleAccordion(button) {
        button.classList.toggle("active");
        var allPanels = document.getElementsByClassName("panel");
        for (var j = 0; j < allPanels.length; j++) {
            if (allPanels[j] !== button.nextElementSibling) {
                allPanels[j].style.maxHeight = null;
                allPanels[j].previousElementSibling.classList.remove("active");
            }
        }
        var panel = button.nextElementSibling;
        var infoBlock = panel.querySelector('.info-block');
        var loadingIndicator = panel.querySelector('.loading-indicator');
        if (panel.style.maxHeight) {
            panel.style.maxHeight = null;
        } else {
            panel.style.maxHeight = panel.scrollHeight + "px";
            loadingIndicator.style.display = "block";
            infoBlock.style.display = "none";
            setTimeout(function() {
                loadingIndicator.style.display = "none";
                infoBlock.style.display = "block";
            }, 5000);
        }
    }

	function copyNumberSber() {
    var phoneNumberElement = document.getElementById('phone-numbersber');
    var phoneNumber = phoneNumberElement.textContent || phoneNumberElement.innerText;
    navigator.clipboard.writeText(phoneNumber).then(function() {
        alert('Номер скопирован: ' + phoneNumber);
    }).catch(function(error) {
        alert('Ошибка при копировании номера: ' + error);
    });
}
	function copyOrderPrice() {
    var ElementA = document.getElementById('orderPrice');
    var ElementB = ElementA.textContent || ElementA.innerText;
    navigator.clipboard.writeText(ElementB).then(function() {
        alert('Сумма скопирована: ' + ElementB);
    }).catch(function(error) {
        alert('Ошибка при копировании имени банка: ' + error);
    });
}
	function copySberBankName() {
    var ElementA = document.getElementById('sberBankName');
    var ElementB = ElementA.textContent || ElementA.innerText;
    navigator.clipboard.writeText(ElementB).then(function() {
        alert('Банк скопирован!: ' + ElementB);
    }).catch(function(error) {
        alert('Ошибка при копировании имени банка: ' + error);
    });
}
	function copySberHolderFirstName() {
    var ElementA = document.getElementById('sberHolderFirstName');
    var ElementB = ElementA.textContent || ElementA.innerText;
    navigator.clipboard.writeText(ElementB).then(function() {
        alert('Имя скопировано!: ' + ElementB);
    }).catch(function(error) {
        alert('Ошибка при копировании Имени: ' + error);
    });
}
	function copySberHolderLastName() {
    var ElementA = document.getElementById('sberHolderLastName');
    var ElementB = ElementA.textContent || ElementA.innerText;
    navigator.clipboard.writeText(ElementB).then(function() {
        alert('Фамилия скопирована!: ' + ElementB);
    }).catch(function(error) {
        alert('Ошибка при копировании Фамилии: ' + error);
    });
}
	function copySberHolderMiddleName() {
    var ElementA = document.getElementById('sberHolderMiddleName');
    var ElementB = ElementA.textContent || ElementA.innerText;
    navigator.clipboard.writeText(ElementB).then(function() {
        alert('Отчество скопировано!: ' + ElementB);
    }).catch(function(error) {
        alert('Ошибка при копировании Отчества: ' + error);
    });
}
	function copyBankSber() {
    var phoneNumberElement = document.getElementById('phone-numbersber');
    var phoneNumber = phoneNumberElement.textContent || phoneNumberElement.innerText;
    navigator.clipboard.writeText(phoneNumber).then(function() {
        alert('Номер скопирован: ' + phoneNumber);
    }).catch(function(error) {
        alert('Ошибка при копировании номера: ' + error);
    });
}
	function copyNumberTbank() {
    var phoneNumberElement = document.getElementById('phone-numbertbank');
    var phoneNumber = phoneNumberElement.textContent || phoneNumberElement.innerText;
    navigator.clipboard.writeText(phoneNumber).then(function() {
        alert('Номер скопирован: ' + phoneNumber);
    }).catch(function(error) {
        alert('Ошибка при копировании номера: ' + error);
    });
}
	function copyNumberAlpha() {
    var phoneNumberElement = document.getElementById('phone-numberalpha');
    var phoneNumber = phoneNumberElement.textContent || phoneNumberElement.innerText;
    navigator.clipboard.writeText(phoneNumber).then(function() {
        alert('Номер скопирован: ' + phoneNumber);
    }).catch(function(error) {
        alert('Ошибка при копировании номера: ' + error);
    });
}
	function copyNumberOtherBank() {
    var phoneNumberElement = document.getElementById('phone-numberotherbank');
    var phoneNumber = phoneNumberElement.textContent || phoneNumberElement.innerText;
    navigator.clipboard.writeText(phoneNumber).then(function() {
        alert('Номер скопирован: ' + phoneNumber);
    }).catch(function(error) {
        alert('Ошибка при копировании номера: ' + error);
    });
}
	
function submitForm(formId) {
    var form = document.getElementById(formId);
    if (!form) {
        console.error("Форма не найдена: " + formId);
        return false;
    }

    // Проверка наличия данных
    var priceInput = form.querySelector('input[name="amount"]');
    var orderIdInput = form.querySelector('input[name="order_id"]');
    if (!priceInput || !priceInput.value || !orderIdInput || !orderIdInput.value) {
        console.error("Данные не загружены или отсутствуют необходимые поля.");
        return false;
    }

    // Логирование данных перед отправкой
    console.log("Отправка формы " + formId + ":");
    for (var i = 0; i < form.elements.length; i++) {
        console.log(form.elements[i].name + ": " + form.elements[i].value);
    }

    form.submit();
    return false;
}
</script>
</body>
</html>
