<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/mystore.class.php';
require_once __DIR__ . '/classes/cz.class.php';
require_once __DIR__ . '/functions.php';

session_start(); // нужно для хранения uuid/data между двумя запросами

// ==== Проверяем accesstoken ====
$cookieName  = $auth['cookie_name'] ?? 'accesstoken';
$cookieValue = $_COOKIE[$cookieName] ?? '';
$validToken  = $auth['access_token'] ?? '';

if (!is_string($cookieValue) || $cookieValue !== $validToken) {
    // редирект на страницу логина и возвращаемся обратно после авторизации
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/check-order.php');
    header('Location: /login.php?redirect=' . $redirect, true, 302);
    exit;
}
// === /конец блока авторизации ===

// Важно: порядок аргументов — сначала конфиг, потом путь к SQLite
try {
    $cz = new CzClient($cz_config, $database);
    // Модалка нужна только если актуального токена нет в БД
    $czTokenValid = $cz->getStoredToken() !== null;
} catch (Throwable $e) {
    error_log('[CZ] init error: ' . $e->getMessage());
    // Если SQLite не создался/не пишется — просто показываем модалку авторизации,
    // а страница не падает.
    $cz = null;
    $czTokenValid = false;
}

header('Content-Type: text/html; charset=utf-8');

$imagesDirAbs = __DIR__ . '/images';  
$imagesUrlBase = '/images';

// ===== 1) Валидация id =====
$orderId = isset($_GET['id']) ? trim($_GET['id']) : '';
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $orderId)) {
	http_response_code(400);
	echo "Некорректный или пустой параметр id.";
	exit;
}

try {
    $pdo = mysql_pdo_connect($mysql);
} catch (Throwable $e) {
    http_response_code(500);
    exit('MySQL CONNECT ERROR: ' . htmlspecialchars($e->getMessage()));
}

$table = $mysql['table_shipments'] ?? 'shipments_registry';

// Создадим таблицу, если её удалили
// try {
//     $pdo->exec("
//         CREATE TABLE IF NOT EXISTS `{$table}` (
//           `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
//           `shipment_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//           `order_id` CHAR(36) NOT NULL,
//           `order_number` VARCHAR(64) NOT NULL,
//           `assort_id` CHAR(36) NULL,
//           `item_name` VARCHAR(255) NULL,
//           `barcode` VARCHAR(64) NOT NULL,
//           `is_marked` TINYINT(1) NOT NULL DEFAULT 0,
//           `kiz` VARCHAR(255) NULL,
//           `price` DECIMAL(12,2) NULL,
//           `payment_method` VARCHAR(255) NULL,
//           `withdrawn_date` DATETIME NULL,
//           `expiration_date` DATE NULL,
//           PRIMARY KEY (`id`),
//           KEY `idx_order_id` (`order_id`),
//           KEY `idx_order_number` (`order_number`),
//           KEY `idx_assort_id` (`assort_id`),
//           KEY `idx_barcode` (`barcode`)
//           UNIQUE KEY `uniq_kiz` (`kiz`)
//         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
//     ");
// } catch (Throwable $e) {
//     error_log('[CHECK] create table failed: ' . $e->getMessage());
//     // не выходим — просто не будет префилла
// }

$isOrderAlreadySaved = false;
try {
    $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $orderId]);
    $isOrderAlreadySaved = (bool) $stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('[CHECK] check order saved failed: ' . $e->getMessage());
}

//=== Решил визуализировать заполненные инпуты с бд===
$prefilledScans = [];
try {
    $sel = $pdo->prepare("SELECT assort_id, barcode, kiz FROM `{$table}` WHERE order_id = :oid ORDER BY id ASC");
    $sel->execute([':oid' => $orderId]);
    while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
        $aid = (string)($r['assort_id'] ?? '');
        if ($aid === '') continue;
        $isKiz = isset($r['kiz']) && $r['kiz'] !== null && $r['kiz'] !== '';
        $prefilledScans[$aid][] = [
            'value' => $isKiz ? (string)$r['kiz'] : (string)$r['barcode'],
            'type'  => $isKiz ? 'kiz' : 'barcode',
        ];
    }
} catch (Throwable $e) {
    error_log('[CHECK] prefill select failed: ' . $e->getMessage());
    // Просто оставим $prefilledScans пустым
}

// ===== 3) Клиент МойСклад =====
$mystore = new mystore();

// ===== 4) Заказ покупателя =====
$orderLink = ms_link('/customerorder/' . $orderId, [
	'expand' => 'attributes',
]);

$orderResp = $mystore->callFunc($orderLink, null, 'GET');
//var_dump($orderResp);
$order = is_array($orderResp['parsed'] ?? null) ? $orderResp['parsed'] : $orderResp;

if (!is_array($order) || !empty($order['errors']) || empty($order['id'])) {
	http_response_code(404);
	echo "Заказ не найден или недоступен.";
	exit;
}

$orderNumber = $order['name'] ?? $order['id'];
$orderCreatedRaw = $order['created'] ?? null;
$orderCreated = $orderCreatedRaw ? (new DateTime($orderCreatedRaw))->format('d.m.Y') : '—';
$customer = getAttrByName($order['attributes'] ?? [], 'Получатель');
$trackNumber = getAttrByName($order['attributes'] ?? [], 'Трек номер');
$orderAddress = $order["shipmentAddress"] ?? '—';
$paymentMethod = getAttrByName($order['attributes'] ?? [], 'Метод оплаты');
$withdrawnDate = getAttrByName($order['attributes'] ?? [], 'Дата вывода из оборота');
$withdrawnDateMysql = toMysqlDatetime($withdrawnDate);

// ===== 4.1) Позиции заказа и сумма количества =====
$itemsCount = 0.0; // суммарное количество штук
$positionsCount = 0; // количество строк-позиций

// можно взять число позиций сразу из меты заказа
$positionsCount = (int) ($order['positions']['meta']['size'] ?? 0);

// подтянем сами позиции, чтобы сложить quantity
$positionsLink = ms_link('/customerorder/' . $orderId . '/positions', [
	'limit' => 100,
	'expand' => 'assortment,assortment.product',
]);

$posResp = $mystore->callFunc($positionsLink, null, 'GET');
//var_dump($posResp);
$posList = is_array($posResp['parsed'] ?? null) ? $posResp['parsed'] : $posResp;

if (!empty($posList['rows']) && is_array($posList['rows'])) {
	foreach ($posList['rows'] as $row) {
		$assort = $row['assortment'] ?? [];
		$type = $assort['meta']['type'] ?? null; // product | variant | service | bundle ...
		$qty = (float) ($row['quantity'] ?? 0);
		$priceRub = extractPriceRub($row) ?? 0.0;    // ваша функция: price/100
		$name = $assort['name'] ?? ($assort['code'] ?? 'Без названия');
      
      	if ($name == 'Доставка'){
        	continue;
        }

		// Определяем productId для картинок:
		$productId = null;
		if ($type === 'product') {
			$productId = $assort['id'] ?? null;
		} elseif ($type === 'variant') {
			$productHref = $assort['product']['meta']['href'] ?? null;
			$productId = ms_uuid_from_href($productHref);
		}

		$imgSrc = '/images/placeholder.svg';
		if ($productId) {
			$pngAbs = ensure_product_image_png_cached($mystore, $productId, $imagesDirAbs);
			if ($pngAbs) {
				$imgSrc = public_path_from_abs($pngAbs, $imagesDirAbs, $imagesUrlBase); // => /images/{UUID}.png
			}
		}

		$tt = getTrackingTypeFromAssort($assort);
		$isMarked = isMarkedByTrackingType($tt);

		// соберём ВСЕ возможные штрихкоды (ean, gtin, code128, code39, upca)
		$barcodes = [];
      
		// фолбэк (если нет массива баркодов — используем article/code как «идентификатор»)
		if (empty($barcodes)) {
			if (!empty($assort['article']))
				$barcodes[] = (string) $assort['article'];
			if (!empty($assort['code']))
				$barcodes[] = (string) $assort['code'];
		}

		// сколько «сканов» ждём по этой позиции
		$slots = max(0, (int) round($qty));


		$items[] = [
			'name' => $name,
			'qty' => $qty,
			'slots' => $slots,
			'priceRub' => $priceRub,
			'totalRub' => round($qty * $priceRub, 2),
			'imgSrc' => $imgSrc,
			'barcodes' => $barcodes,
			'barcode' => extractBarcode($assort),
			'assortId' => $assort['id'] ?? null,
			'assortType' => $type,
			'isMarked' => $isMarked,
		];
		$itemsCount += $qty;
	}
}

?>
<!doctype html>
<html lang="ru">

<head>
	<meta charset="utf-8">
	<title>Проверка комплектации — Заказ <?= htmlspecialchars($orderNumber) ?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
  	<link rel="icon" href="/images/cropped-logo_2-1-32x32.png" sizes="32x32">
  	<link rel="icon" href="/images/cropped-logo_2-1-192x192.png" sizes="192x192">
  	<script>
  	window.CZ_NEEDS_AUTH = <?= $czTokenValid ? 'false' : 'true' ?>;
	</script>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link
		href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Montserrat:ital,wght@0,100..900;1,100..900&family=Rubik:ital,wght@0,300..900;1,300..900&display=swap"
		rel="stylesheet">
</head>
<style>
  .disabled{
  	opacity: .7!important;
    cursor: not-allowed!important;
  }
</style> 
<body style="font-family:'Montserrat';">
	<div id="order-root"
       data-order-id="<?= htmlspecialchars($orderId) ?>"
       data-order-number="<?= htmlspecialchars($orderNumber) ?>"
       data-payment-method="<?= htmlspecialchars((string)($paymentMethod ?? '')) ?>"
       data-withdrawn-date="<?= htmlspecialchars((string)($withdrawnDateMysql ?? '')) ?>" 
       data-order-locked="<?= $isOrderAlreadySaved ? '1' : '0' ?>"
       style="max-width:1400px;margin:0 auto;padding-left:15px;padding-right:15px;">
		<h1>Проверка комплектации</h1>
		<div class="meta">
			Номер заказа: <strong><?= htmlspecialchars($orderNumber) ?></strong><br>
            Дата заказа: <?= htmlspecialchars($orderCreated) ?><br><br>
			Покупатель: <?= htmlspecialchars($customer ?: '—') ?><br>
			Трек номер: <?= htmlspecialchars($trackNumber ?: '—') ?><br>
			Адрес: <?= htmlspecialchars($orderAddress ?: '—') ?><br>
          	Способ оплаты: <?= htmlspecialchars($paymentMethod ?: '—') ?><br>
		</div>
		<div>
			<h3>Количество товаров в заказе: <?= htmlspecialchars((string) $itemsCount) ?></h3>
		</div>
		<?php if (!empty($items)): ?>
			<div class="products" style="display:flex;flex-direction:column;gap:20px;">
				<?php foreach ($items as $index => $it): ?>
              	<?php
      			$assortId = (string)($it['assortId'] ?? '');
      			$existing = $assortId ? ($prefilledScans[$assortId] ?? []) : [];
      			// Нормализуем длину до числа слотов
      			$existing = array_values(array_slice($existing, 0, (int)$it['slots']));
    			?>
					<div class="product-item" style="display:flex;align-items:center;gap:10px;padding:15px;border-radius:18px;"
						data-assort-id="<?= htmlspecialchars((string) ($it['assortId'] ?? '')) ?>"
						data-barcodes='<?= htmlspecialchars(json_encode($it['barcodes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'
						data-name='<?= htmlspecialchars($it["name"], ENT_QUOTES) ?>'
						data-marked='<?= $it["isMarked"] ? "1" : "0" ?>'
						data-price="<?= htmlspecialchars((string) $it['priceRub']) ?>" data-slots="<?= (int) $it['slots'] ?>">
						<div class="product-img"
							style="flex:0 0 5%;display:flex;align-items:center;text-align:center;gap:10px;">
							<div><?php echo $index + 1; ?></div>
							<img src="<?= htmlspecialchars($it['imgSrc']) ?>" alt=""
								style="max-width:100px; max-height:100px; object-fit:contain;">
						</div>
						<div class="product-info" style="flex:0 0 45%;">
							<?= htmlspecialchars($it['name']) ?>
							<?php if ($it['isMarked'] === true): ?>
								<div style="color:#666; font-size:12px;">Маркируемый товар</div>
							<?php else: ?>
								<div style="color:#666; font-size:12px;">Нет маркировки</div>
							<?php endif; ?>
							<?php if (!empty($it['barcode'])): ?>
								<div style="color:#666; font-size:12px;">Баркод: <?= htmlspecialchars($it['barcode']) ?></div>
							<?php endif; ?>
							<?php if (!empty($it['qty'])): ?>
								<div style="color:#666; font-size:12px;">Количество: <?= htmlspecialchars((string) $it['qty']) ?>
								</div>
							<?php endif; ?>
							<?php if (!empty($it['priceRub'])): ?>
								<div style="color:#666; font-size:12px;">Цена:
									<?= number_format((float) $it['priceRub'], 2, ',', ' ') ?>&nbsp;RUB
								</div>
							<?php endif; ?>
						</div>
						<div class="product-inputs"
							style="flex:0 0 50%;margin:0 auto;display:flex;justify-content: center;gap:6px;flex-wrap:wrap;max-width:300px;">
							<div>
								<?php if ($it['isMarked'] === true): ?>
									<div style="color:#666; font-size:12px;">сканировать <strong>Дата матрикс код на крышке</strong>
									</div>
								<?php else: ?>
									<div style="color:#666; font-size:12px;">сканировать <strong>Штрихкод</strong></div>
								<?php endif; ?>
							</div>
							<?php for ($i = 0; $i < (int)$it['slots']; $i++):
                				$pref = $existing[$i] ?? null;
                				$val  = $pref['value'] ?? '';
                				// тип скана: если есть сохранённый — берём его; иначе по типу товара
                				$scanType = $pref['type'] ?? ($it['isMarked'] ? 'kiz' : 'barcode');
                				$filled = $val !== '';
            				?>
                			<div style="width:100%;display:flex;align-items:center;gap:15px;">
                            <div><?= $i + 1 ?></div>
                    		<input
                        		type="text"
                        		class="scan-field<?= $filled ? ' filled-ok' : '' ?>"
                        		inputmode="text"
                        		autocomplete="off"
                        		value="<?= htmlspecialchars($val) ?>"
                        		data-filled="<?= $filled ? '1' : '0' ?>"
                        		data-scantype="<?= htmlspecialchars($scanType) ?>"
                        		style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:6px;"
                    		/>
                			</div>
            			<?php endfor; ?>

							<?php if ((int) $it['slots'] === 0): ?>
								<div style="font-size:12px;color:#999;">Количество = 0 — скан не требуется</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<div style="margin-top:30px;display:flex;gap:12px;align-items:center;">
				<button id="saveBtn" disabled
					style="width: 100%;max-width: 300px;height: 60px;margin: 0 auto;padding:10px 16px;border-radius:10px; border:0;background:#4A65FF;color:#fff;cursor:not-allowed;font-size:18px;font-weight:600;">
					Сохранить
				</button>
				<span id="saveMsg" style="font-size:14px;color:#555;"></span>
			</div>
		<?php else: ?>
			<div class="empty">Позиции заказа не найдены.</div>
		<?php endif; ?>
	</div>
	<footer style="height:100px;"></footer>
    <script src="/js/jquery.min.js"></script>
  	<script src="/js/main.js?v=3"></script>
	

	<style>
		.filled-ok {
			background: #ecfdf5;
		}
		
		/* лёгкий зелёный фон для заполненного поля */
	</style>
  <!-- ========== CZ AUTH MODAL (LOCKED) ========== -->
<div  id="czAuthOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;"></div>

<div  id="czAuthModal" role="dialog" aria-modal="true" aria-labelledby="czAuthTitle"
     style="display:none;position:fixed;z-index:9999;left:50%;top:50%;transform:translate(-50%,-50%);
            width: min(720px, 96vw); max-width: 720px; background:#fff; border-radius:14px; padding:18px 18px 14px; box-shadow:0 20px 80px rgba(0,0,0,.3);">
  <h2 id="czAuthTitle" style="margin:0 0 10px 0;">Авторизация в Честном Знаке</h2>
  <p style="margin:0 0 10px 0;color:#444;line-height:1.4;">
    Скопируйте строку ниже и выполните <strong>откреплённую CAdES-BES</strong> подпись (без изменения строки).
    Затем вставьте полученную <strong>base64</strong>-подпись в поле и нажмите «Отправить».
  </p>

  <div style="margin:10px 0 6px; font-size:12px; color:#666;">Строка для подписи (из ЧЗ):</div>
  <textarea id="czDataToSign" readonly
            style="width:100%; min-height: 120px; font-family:monospace; font-size:12px; padding:8px; border:1px solid #ccc; border-radius:8px; background:#f9fafb;"></textarea>
  <div style="margin:6px 0 14px; display:flex; gap:8px;">
    <button id="czCopyDataBtn" type="button" style="padding:8px 12px; border:0; background:#e5e7eb; border-radius:8px; cursor:pointer;">
      Копировать строку
    </button>
    <span id="czKeyStatus" style="font-size:12px; color:#666;"></span>
  </div>

  <div style="margin:10px 0 6px; font-size:12px; color:#666;">Вставьте вашу base64-подпись:</div>
  <textarea id="czSignature" class="cz-editable" placeholder="Вставьте сюда base64 подпись CAdES-BES"
            style="width:100%; min-height: 100px; font-family:monospace; font-size:12px; padding:8px; border:1px solid #ccc; border-radius:8px;"></textarea>

  <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
    <button id="czSubmitBtn" type="button"
            style="padding:10px 16px; background:#4A65FF; color:#fff; border:0; border-radius:8px; cursor:pointer; font-weight:600;">
      Отправить
    </button>
  </div>

  <div id="czAuthMsg" style="margin-top:10px; font-size:13px; color:#555;"></div>
</div>

<script>
(function ($) {
  let czLocked = true;              // пока нет токена — окно «заперто»
  let czAuthDone = false;           // станет true после успешной авторизации

  function showCzModal() {
    $('#czAuthOverlay, #czAuthModal').show();
    $('body').css('overflow', 'hidden');
    czLocked = true;
  }
  function hideCzModal() {
    $('#czAuthOverlay, #czAuthModal').hide();
    $('body').css('overflow', '');
  }

  function loadCzKey() {
    $('#czKeyStatus').text('Запрашиваем ключ авторизации…');
    return $.getJSON('/cz_ajax.php', { action: 'get_key' })
      .done(function (resp) {
        if (resp && resp.ok) {
          $('#czDataToSign').val(resp.data);
          $('#czKeyStatus').text('Ключ получен. Подпишите указанную строку.');
        } else {
          $('#czKeyStatus').text((resp && resp.error) ? resp.error : 'Не удалось получить ключ.');
        }
      })
      .fail(function (xhr) {
        const msg = 'Ошибка: ' + (xhr.responseJSON?.error || ('HTTP ' + xhr.status));
        $('#czKeyStatus').text(msg);
      });
  }

  function submitSignature() {
    const sig = ($('#czSignature').val() || '').trim();
    if (!sig) {
      $('#czAuthMsg').css('color', '#c2410c').text('Вставьте подпись base64.');
      return;
    }
    $('#czAuthMsg').css('color', '#555').text('Отправляем подпись…');

    return $.ajax({
      url: '/cz_ajax.php?action=submit_signature',
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      dataType: 'json',
      data: JSON.stringify({ signature: sig })
    })
    .done(function (resp) {
      if (resp && resp.ok) {
        czAuthDone = true;
        czLocked = false; // разблокируем
        $('#czAuthMsg').css('color', '#16a34a').text('Авторизация выполнена. Обновляем страницу…');
        setTimeout(function(){ window.location.reload(); }, 600);
      } else {
        $('#czAuthMsg').css('color', '#dc2626').text(resp && resp.error ? resp.error : 'Не удалось получить токен.');
      }
    })
    .fail(function (xhr) {
      const msg = xhr.responseJSON?.error || ('HTTP ' + xhr.status);
      $('#czAuthMsg').css('color', '#dc2626').text('Ошибка авторизации: ' + msg);
    });
  }

  // Блокируем закрытие через клик по подложке:
  $('#czAuthOverlay').on('click', function (e) {
    e.preventDefault();
    $('#czAuthMsg').css('color','#c2410c').text('Авторизация обязательна: отправьте подписанную строку.');
  });

  // Блокируем ESC, пока окно «заперто»
  $(document).on('keydown', function (e) {
    if (czLocked && (e.key === 'Escape' || e.key === 'Esc')) {
      e.preventDefault();
      e.stopPropagation();
    }
  });

  // Защита от ухода/перезагрузки страницы
  window.addEventListener('beforeunload', function (e) {
    if (czLocked && !czAuthDone) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  $(function () {
    if (window.CZ_NEEDS_AUTH) {
      showCzModal();
      loadCzKey();
    }
    $('#czCopyDataBtn').on('click', function () {
      const ta = document.getElementById('czDataToSign');
      ta.select();
      ta.setSelectionRange(0, 999999);
      try {
        document.execCommand('copy');
        $('#czKeyStatus').text('Строка скопирована в буфер обмена.');
      } catch (e) {
        $('#czKeyStatus').text('Скопируйте вручную.');
      }
    });
    $('#czSubmitBtn').on('click', submitSignature);
  });
})(jQuery);
</script>
<!-- ========== /CZ AUTH MODAL (LOCKED) ========== -->

</body>

</html>