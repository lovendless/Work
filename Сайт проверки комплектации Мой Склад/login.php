<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

session_start();

// --- Проверяем, авторизован ли уже ---
$cookieName  = $auth['cookie_name'] ?? 'accesstoken';
$cookieValue = $_COOKIE[$cookieName] ?? '';
$validToken  = $auth['access_token'] ?? '';

if ($cookieValue === $validToken) {
    // уже вошёл, редиректим на страницу назначения (или главную)
    $redirectUrl = '/success.html';
    if (!empty($_GET['redirect']) && str_starts_with($_GET['redirect'], '/')) {
        $redirectUrl = $_GET['redirect'];
    }
    header('Location: ' . $redirectUrl, true, 302);
    exit;
}

// простая CSRF-защита
if (empty($_SESSION['csrf'])) {
	$_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$redirectUrl = '/check-order.php';
if (!empty($_GET['redirect']) && is_string($_GET['redirect'])) {
	// защищаемся от открытых редиректов: разрешаем только относительные пути
	if (str_starts_with($_GET['redirect'], '/')) {
		$redirectUrl = $_GET['redirect'];
	}
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$login = trim($_POST['login'] ?? '');
	$pass = (string) ($_POST['password'] ?? '');
	$csrf = (string) ($_POST['csrf'] ?? '');

	if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
		$error = 'Неверный CSRF-токен. Обновите страницу и попробуйте снова.';
	} else {
		$cfgLogin = (string) ($auth['login'] ?? '');
		$hash = (string) ($auth['password_hash'] ?? '');
		if ($login === $cfgLogin && password_verify($pass, $hash)) {
			// логин успешен — ставим куку с access_token
			$token = (string) ($auth['access_token'] ?? '');
			$params = [
				'expires' => time() + (int) ($auth['cookie_lifetime'] ?? 604800),
				'path' => (string) ($auth['cookie_path'] ?? '/'),
				'domain' => (string) ($auth['cookie_domain'] ?? ''),
				'secure' => (bool) ($auth['cookie_secure'] ?? true),
				'httponly' => (bool) ($auth['cookie_httponly'] ?? true),
				'samesite' => (string) ($auth['cookie_samesite'] ?? 'Lax'),
			];
			setcookie((string) ($auth['cookie_name'] ?? 'accesstoken'), $token, $params);

			header('Location: ' . $redirectUrl, true, 302);
			exit;
		} else {
			$error = 'Неверный логин или пароль.';
		}
	}
}
?>
<!doctype html>
<html lang="ru">

<head>
	<meta charset="utf-8">
	<title>Вход — Проверка комплектации</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
  	<link rel="icon" href="/images/cropped-logo_2-1-32x32.png" sizes="32x32">
  	<link rel="icon" href="/images/cropped-logo_2-1-192x192.png" sizes="192x192">
	<style>
		body {
			font-family: system-ui, -apple-system, Segoe UI, Roboto, Inter, Montserrat, Arial, sans-serif;
			background: #f6f7fb;
			margin: 0;
			padding: 0
		}

		.wrap {
			max-width: 420px;
			margin: 15vh auto;
			display: flex;
			justify-content: center;
			flex-direction: column;
			align-items: center;
			background: #fff;
			padding: 28px;
			border-radius: 14px;
			box-shadow: 0 6px 20px rgba(0, 0, 0, .08)
		}

		.form {
			width: 100%
		}

		h1 {
			margin: 0 0 18px 0;
			font-size: 22px
		}

		.field {
			margin-bottom: 12px
		}

		label {
			display: block;
			margin: 0 0 6px 4px;
			font-size: 14px;
			color: #444
		}

		input[type=text],
		input[type=password] {
			width: 100%;
			padding: 10px 12px;
			border: 1px solid #dcdfe4;
			border-radius: 10px;
			font-size: 16px;
			outline: none;
          	box-sizing: border-box;
		}

		button {
          	margin-top: 15px;
			width: 100%;
			padding: 12px;
			border: 0;
			border-radius: 10px;
			background: #4A65FF;
			color: #fff;
			font-weight: 600;
			font-size: 16px;
			cursor: pointer
		}

		.error {
			margin: 10px 0 0;
			color: #b00020;
			font-size: 14px
		}

		.helper {
			margin-top: 14px;
			font-size: 12px;
			color: #666;
			text-align: center
		}
	</style>
</head>

<body>
	<div class="wrap">
		<h1>Вход</h1>
		<?php if (!empty($error)): ?>
			<div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
		<?php endif; ?>
		<form class="form" method="post">
			<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
			<div class="field">
				<label>Логин</label>
				<input type="text" name="login" autocomplete="username" required>
			</div>
			<div class="field">
				<label>Пароль</label>
				<input type="password" name="password" autocomplete="current-password" required>
			</div>
			<button type="submit">Войти</button>
		</form>

	</div>
</body>

</html>