<?php
// ===== Хелперы =====
/**
 * Склеивает путь и query-параметры в формат MoySklad.
 * Возвращает СТРОКУ ВИДА: /customerorder?... (без домена!)
 */
function ms_link(string $path, array $query = []): string {
    if (!$query) return $path;
    // PHP_QUERY_RFC3986 -> пробелы как %20, "=" и ":" тоже кодируются
    return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function getAttrByName(?array $attrs, string $name) {
    if (!$attrs) return null;
    foreach ($attrs as $a) {
        if (!empty($a['name']) && $a['name'] === $name) {
            if (!array_key_exists('value', $a)) return null;
            $v = $a['value'];
            return is_array($v) ? ($v['name'] ?? ($v['value'] ?? null)) : $v;
        }
    }
    return null;
}
// Достаем баркод из массива
function extractBarcode(?array $assortment): ?string {
    if (!$assortment) return null;
    if (!empty($assortment['barcodes']) && is_array($assortment['barcodes'])) {
        $b = $assortment['barcodes'][0];
        foreach (['ean','gtin','code128','code39','upca'] as $k) {
            if (!empty($b[$k])) return (string)$b[$k];
        }
    }
    if (!empty($assortment['article'])) return (string)$assortment['article'];
    if (!empty($assortment['code']))    return (string)$assortment['code'];
    return null;
}
// Достаем и округляем цену
function extractPriceRub(array $position): ?float {
    if (isset($position['price'])) return round(((float)$position['price'])/100, 2);
    if (!empty($position['assortment']['salePrices'][0]['value'])) {
        return round(((float)$position['assortment']['salePrices'][0]['value'])/100, 2);
    }
    return null;
}
function toMysqlDatetime(?string $iso): ?string {
    // МС обычно отдаёт ISO8601 с таймзоной, MySQL ждёт 'Y-m-d H:i:s'
    if (!$iso) return null;
    $ts = strtotime($iso);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

	//Curl request
	function request($url,$data=array(),$method='GET', $header = ['Content-Type:application/json'], $options = [])
	{
		if (empty($options)) $options = array(CURLOPT_TIMEOUT_MS => 30000,CURLOPT_RETURNTRANSFER => true);
		$curl = curl_init();
		curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($data));
		$array = $options + array(CURLOPT_URL => $url,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $header,
		CURLOPT_SSL_VERIFYPEER => false);
		curl_setopt_array($curl, $array);
		$response = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		$response = json_decode($response,true);
		if ($code < 200 || $code > 204) {
			log_func($response,"request.php - ".$url.' '.$method.' '.$code.' ',true);
			return array("error_code"=>$code, "response"=>$response);
		}
		else {
		  return $response;
		}
	}

	// Curl gzip
	function gziprequest($url,$data=array(),$method='GET', $header = ['Content-Type:application/json'], $options = [])
	{
	  $curl = curl_init();
	  // $compressed_data = gzdeflate($data);
	  curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($data));
	  $array = $options + array(CURLOPT_URL => $url,
	    CURLOPT_CUSTOMREQUEST => $method,
	    CURLOPT_HTTPHEADER => $header,
	    CURLOPT_ENCODING => 'gzip'
		);
	  // if (!empty($user)) curl_setopt($curl, CURLOPT_USERPWD, $user);
	  curl_setopt($curl,CURLOPT_RETURNTRANSFER, true); 
	  curl_setopt_array($curl, $array);
	  $response = curl_exec($curl);
	  // $err = curl_error($curl);
	  $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	  curl_close($curl);

	  $response = json_decode($response,true);
	  if ($code < 200 || $code > 204) {
  		log_func($response,"request.php - ".$url.' '.$method.' '.$code.' ',true);
  		return array("error_code"=>$code, "response"=>$response);
	  }
	  else {
	      return $response;
	  }
	}

	function log_func($data = [], $description = "", $debug = false, $error_stat = false)
	{
		if ($debug) print_r($data);
 	   	global $path, $error_log_array;
 	   	$log_msg = substr(date('Y-m-d H:i:s ').$description.' '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),0,500)."\n";
 	   	// $log_msg = date('Y-m-d H:i:s ').$description.' '.json_encode($data)."\n";
	    $log_filename = './log';
	    if (!file_exists($log_filename)) 
	    {
	        // create directory/folder uploads.
	        mkdir($log_filename, 0777, true);
	    }
	    $log_file_data = $log_filename.'/scripts-php_' . date('d-M-Y') . '.log';
	    // file_put_contents($log_file_data, $log_msg, FILE_APPEND);
	    file_put_contents($log_file_data, $log_msg, FILE_APPEND);

	    if ($error_stat)
	    {
	    	$error_log_array[] = $description." ".json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
	    }
	}

	function send_warning_log()
	{
		global $error_log_array;
		if (!empty($error_log_array))
		{
			call_to_tg(json_encode($error_log_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}	
	}
/**
 * Делает безопасное имя файла из произвольной строки (имя товара/картинки)
 */
function slugify_filename(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace('~[^A-Za-z0-9._-]+~', '-', $s);
    $s = trim($s, '-._');
    return $s !== '' ? $s : ('img-' . bin2hex(random_bytes(4)));
}

/**
 * Выбирает подходящее расширение по content-type
 */
function ext_from_content_type(?string $ct): string {
    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    return $map[strtolower((string)$ct)] ?? 'jpg';
}

/**
 * Скачивает первую картинку товара из МС и сохраняет как PNG:
 *   /images/{productId}.png
 * Вернёт абсолютный путь к PNG или null.
 * Требует доступ к $mystore->mystore_config[login,password] для Basic-авторизации.
 */
function ensure_product_image_png_cached(mystore $mystore, string $productId, string $imagesDirAbs): ?string {
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $productId)) return null;
    if (!is_dir($imagesDirAbs)) @mkdir($imagesDirAbs, 0775, true);

    // 1) Берём первую картинку товара
    $imgListResp = $mystore->callFunc('/product/' . $productId . '/images?limit=1', null, 'GET');
    $imgList = is_array($imgListResp['parsed'] ?? null) ? $imgListResp['parsed'] : $imgListResp;
    $imgMetaHref = $imgList['rows'][0]['meta']['href'] ?? null;
    if (!$imgMetaHref) return null;

    // Если PNG уже есть — возвращаем
    $targetPng = rtrim($imagesDirAbs, '/\\') . DIRECTORY_SEPARATOR . $productId . '.jpg';
    if (is_file($targetPng) && filesize($targetPng) > 0) return $targetPng;

    // 2) Скачиваем бинарь
    $downloadUrl = $imgMetaHref . '/download';
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode($mystore->mystore_config['login'] . ':' . $mystore->mystore_config['pass']),
        ],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { curl_close($ch); return null; }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body       = substr($resp, 0 + $headerSize);
    $ct         = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    curl_close($ch);
    if ($body === '' || $body === false) return null;

    // 3) Конвертируем в PNG через GD (raster only)
    // Для SVG конвертация не получится — тогда просто вернём null.
    if (stripos($ct, 'svg') !== false) return null;

    $im = @imagecreatefromstring($body);
    if (!$im) return null;
    imagealphablending($im, false);
    imagesavealpha($im, true);

    // Сохраняем {UUID}.png
    $ok = @imagepng($im, $targetPng, 6);
    imagedestroy($im);
    if (!$ok) return null;

    @chmod($targetPng, 0664);
    return $targetPng;
}

/** Абсолютный путь -> веб-путь (/images/{file}) */
function public_path_from_abs(string $absPath, string $imagesDirAbs, string $publicBase = '/images'): string {
    $rel = ltrim(str_replace($imagesDirAbs, '', $absPath), '/\\');
    return rtrim($publicBase, '/') . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
}

/** Достаём UUID из meta.href (для модификаций) */
function ms_uuid_from_href(?string $href): ?string {
    if ($href && preg_match('~([0-9a-fA-F-]{36})~', $href, $m)) return $m[1];
    return null;
}

// ===== Функция: забираем все товары с пагинацией =====
function ms_fetch_all_products(mystore $mystore, bool $onlyActive = false): array {
    $limit  = 1000;   // максимум у REMAP 1.2
    $offset = 0;
    $all    = [];

    do {
        // Хочешь только неархивные — раскомментируй filter ниже
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
            // 'filter' => 'archived=false',
        ];
        $link = ms_link('/product', $params);

        $resp = $mystore->callFunc($link, null, 'GET');
        $data = is_array($resp['parsed'] ?? null) ? $resp['parsed'] : $resp;

        $rows   = $data['rows'] ?? [];
        $all    = array_merge($all, $rows);

        // продвигаем оффсет
        $got    = count($rows);
        $offset += $data['meta']['limit'] ?? $got;

        // если сервер отдал общий size — используем его; если нет — идём, пока есть строки
        $total  = $data['meta']['size'] ?? null;
        $cont   = $got > 0 && ($total === null ? true : $offset < $total);
    } while ($cont);

    if ($onlyActive) {
        $all = array_values(array_filter($all, fn($p) => empty($p['archived'])));
    }

    return $all;
}

// helper: извлечь trackingType с учётом product/variant
    function getTrackingTypeFromAssort(array $assort): ?string {
        $type = $assort['meta']['type'] ?? null;
        if ($type === 'product') {
            return $assort['trackingType'] ?? null;
        }
        if ($type === 'variant') {
            // у модификации trackingType живёт у родителя-товара
            return $assort['product']['trackingType'] ?? null;
        }
        return null;
    }

    function isMarkedByTrackingType(?string $tt): bool {
        if ($tt === null) return false;
        $tt = strtoupper(trim($tt));
        // Явно НЕ маркируемые:
        $notTracked = ['','NOT_TRACKED'];
        if (in_array($tt, $notTracked, true)) return false;

        // Всё остальное считаем маркируемым (например: MEDICINES, FOOTWEAR, PERFUME и т.п.)
        return true;
    }

?>