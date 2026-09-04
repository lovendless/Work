<?php
declare(strict_types=1);

class CzClient {
    private SQLite3 $db;
    private string $table;
    private string $host;
    private string $prefix;
    private string $inn;

    // Последний HTTP (для отладки)
    private ?int $lastHttpCode = null;
    private ?string $lastHttpRaw = null;
    private ?string $lastUrl = null;

    public function __construct(array $cz_config, string $sqlite_path) {
        $this->host   = rtrim($cz_config['base_url'] ?? 'https://markirovka.crpt.ru', '/');
        $this->prefix = '/' . trim($cz_config['api_prefix'] ?? '/api/v3/true-api', '/');
        $this->inn    = (string)($cz_config['inn'] ?? '');

        $this->table  = $cz_config['sqlite_table'] ?? 'db_cz_tokens';
        $this->db     = new SQLite3($sqlite_path);

        // чтобы не упираться в блокировки
        if (method_exists($this->db, 'busyTimeout')) {
            $this->db->busyTimeout(5000);
        }

        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                token      TEXT    NOT NULL,
                exp        INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            );
        ";
        $ok = $this->db->exec($sql);
        if ($ok === false) {
            throw new RuntimeException("SQLite CREATE TABLE failed: " . $this->db->lastErrorMsg());
        }

        // quick sanity check
        $res = $this->db->query("PRAGMA table_info({$this->table})");
        if ($res === false) {
            throw new RuntimeException("SQLite PRAGMA table_info failed: " . $this->db->lastErrorMsg());
        }
        $cols = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $cols[strtolower((string)$row['name'])] = true;
        }
        foreach (['token','exp','created_at'] as $need) {
            if (empty($cols[$need])) {
                throw new RuntimeException("SQLite schema mismatch: column '{$need}' not found in {$this->table}");
            }
        }
    }

    private function log(string $msg): void {
        error_log('[CZ] ' . $msg);
    }

    private function http(string $url, string $method = 'GET', ?array $headers = null, $body = null): array {
        $this->lastUrl = $url;

        $ch = curl_init();
        $hdrs = $headers ?: [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $this->lastHttpCode = $http;
        $this->lastHttpRaw  = ($raw === false) ? $err : (string)$raw;

        if ($raw === false) {
            throw new RuntimeException("HTTP error: {$err}");
        }
        $json = json_decode($raw, true);
        return ['code' => $http, 'raw' => $raw, 'json' => $json];
    }

    public function getLastHttp(): array {
        return [
            'url'  => $this->lastUrl,
            'code' => $this->lastHttpCode,
            // не возвращаем мегабайты — режем
            'raw'  => mb_substr((string)$this->lastHttpRaw, 0, 3000, 'UTF-8'),
        ];
    }

    private function b64url_decode(string $s): string {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return base64_decode($s) ?: '';
    }

    /** Локальная проверка валидности JWT (без запросов к ЧЗ). */
    public function isTokenValidLocal(string $token): bool {
        $parts = explode('.', $token);
        if (count($parts) < 2) return false;
        $payloadJson = $this->b64url_decode($parts[1]);
        if ($payloadJson === '') return false;

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) return false;

        $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
        if ($exp <= 0 || $exp <= time() + 30) {
            $this->log('local token check: exp is invalid/expired');
            return false;
        }
        if (!empty($this->inn) && isset($payload['inn']) && (string)$payload['inn'] !== $this->inn) {
            $this->log('local token check: inn mismatch in JWT');
            return false;
        }
        return true;
    }

    public function saveToken(string $token): void {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            throw new RuntimeException('Некорректный формат JWT.');
        }
        $payload = json_decode($this->b64url_decode($parts[1]), true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            throw new RuntimeException('В токене отсутствует exp.');
        }
        $exp = (int)$payload['exp'];
        if ($exp <= time()) {
            throw new RuntimeException('Получен истёкший токен.');
        }

        $stmt = $this->db->prepare("INSERT INTO {$this->table} (token, exp, created_at) VALUES (:t, :e, :c)");
        if (!$stmt) {
            throw new RuntimeException('SQLite prepare failed: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':t', $token, SQLITE3_TEXT);
        $stmt->bindValue(':e', $exp, SQLITE3_INTEGER);
        $stmt->bindValue(':c', time(), SQLITE3_INTEGER);
        if (!$stmt->execute()) {
            throw new RuntimeException('SQLite insert failed for token: ' . $this->db->lastErrorMsg());
        }
    }

    public function getStoredToken(): ?string {
        $res = $this->db->query("SELECT token, exp FROM {$this->table} ORDER BY id DESC LIMIT 1");
        if (!$res) return null;
        $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
        if (!$row) return null;

        $token = (string)$row['token'];
        $exp   = (int)$row['exp'];
        if ($exp > time() + 30 && $this->isTokenValidLocal($token)) {
            return $token;
        }
        return null;
    }

    /** Получаем ключ для подписи. */
    public function getKey(): array {
        $url = $this->host . $this->prefix . '/auth/key';
        $resp = $this->http($url, 'GET', ['Accept: application/json', 'Accept-Encoding: gzip']);
        if ($resp['code'] !== 200 || !is_array($resp['json']) || empty($resp['json']['uuid']) || empty($resp['json']['data'])) {
            $cut = mb_substr((string)$resp['raw'], 0, 500, 'UTF-8');
            $this->log("get_key failed: HTTP={$resp['code']}, body_cut={$cut}");
            throw new RuntimeException('Не удалось получить uuid/data для подписи.');
        }
        $this->log('get_key ok: uuid=' . $resp['json']['uuid'] . ' len(data)=' . strlen($resp['json']['data']));
        return ['uuid' => $resp['json']['uuid'], 'data' => $resp['json']['data']];
    }

    /**
     * Обмен подписанной строки на токен.
     */
    public function exchangeSignatureForToken(string $uuid, string $signatureBase64, ?string $innOverride = null): string {
        $url = $this->host . $this->prefix . '/auth/simpleSignIn';

        $payload = ['uuid' => $uuid, 'data' => $signatureBase64];
        if ($innOverride !== null && $innOverride !== '') {
            $payload['inn'] = $innOverride;
        }

        $resp = $this->http(
            $url,
            'POST',
            ['Content-Type: application/json; charset=utf-8', 'Accept: application/json', 'Accept-Encoding: gzip'],
            $payload
        );

        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            $cut = mb_substr((string)$resp['raw'], 0, 500, 'UTF-8');
            throw new RuntimeException("simpleSignIn HTTP {$resp['code']}: {$cut}");
        }

        $tok = '';
        if (is_array($resp['json']) && isset($resp['json']['token']) && is_string($resp['json']['token'])) {
            $tok = trim($resp['json']['token']);
        } elseif (is_string($resp['raw'])) {
            $maybe = trim($resp['raw']);
            if ($maybe !== '' && preg_match('~^[A-Za-z0-9_\-\.]+$~', $maybe)) {
                $tok = $maybe;
            }
        }

        if ($tok === '') {
            $cut = mb_substr((string)$resp['raw'], 0, 500, 'UTF-8');
            throw new RuntimeException("Не получили токен от simpleSignIn (HTTP {$resp['code']}): {$cut}");
        }

        if (!$this->isTokenValidLocal($tok)) {
            throw new RuntimeException('Получен токен, но он не прошёл локальную проверку exp/inn — проверьте подпись/ИНН.');
        }

        $this->saveToken($tok);
        return $tok;
    }

    /**
     * GET с Bearer (внутренний помощник).
     */
    private function apiGetBearer(string $path): array {
        $token = $this->getStoredToken();
        if (!$token) {
            throw new RuntimeException('ЧЗ: отсутствует валидный токен.');
        }
        $url = $this->host . $this->prefix . '/' . ltrim($path, '/');
        return $this->http($url, 'GET', [
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Authorization: Bearer ' . $token,
        ]);
    }

    /**
     * Получить info по КИЗ. Пробуем несколько путей (в разных контурах отличаются).
     * Возвращаем JSON первого удачного ответа (HTTP 200).
     */
    public function fetchCisInfo(string $cis): array {
        $cis = trim($cis);
        if ($cis === '') {
            throw new InvalidArgumentException('empty CIS');
        }

        $candidates = [
            "cis/{$cis}/info",
            "cis/{$cis}",
            "code/{$cis}",
            "codes/{$cis}/info",
            "products/cis/{$cis}",
        ];

        $lastErr = null;
        foreach ($candidates as $path) {
            try {
                $resp = $this->apiGetBearer($path);
                $this->log("[cis_info] try {$path} => HTTP {$resp['code']}");
                if ($resp['code'] === 200 && is_array($resp['json'])) {
                    return $resp['json'];
                }
                // 204/404 — пробуем следующий
                $lastErr = "HTTP {$resp['code']}, body_cut=" . mb_substr((string)$resp['raw'], 0, 400, 'UTF-8');
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        throw new RuntimeException('Не удалось получить CIS info: ' . ($lastErr ?? 'unknown'));
    }
  
 public function cisesInfo(array $cisList): array {
    $token = $this->getStoredToken();
    if (!$token) throw new RuntimeException('Нет валидного токена ЧЗ');

    // только непустые уникальные строки
    $cisList = array_values(array_unique(array_filter(array_map('trim', $cisList), fn($v)=>$v!=='')));
    if (!$cisList) return [];

    $url = $this->host . $this->prefix . '/cises/info';
    $resp = $this->http(
        $url,
        'POST',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Accept-Encoding: gzip',
        ],
        $cisList  // <-- именно массив строк в корне JSON
    );

    if ($resp['code'] !== 200 || !is_array($resp['json'])) {
        $cut = mb_substr((string)$resp['raw'], 0, 800, 'UTF-8');
        throw new RuntimeException("cises/info HTTP {$resp['code']}: {$cut}");
    }
    return $resp['json'];
}



    /**
     * Достаём срок годности из произвольного ответа ЧЗ.
     * Возвращаем 'YYYY-MM-DD' или null.
     */
    public function extractExpirationDate(array $json): ?string {
        // частые прямые поля
        $keys = ['expirationDate','expiration_date','expiryDate','expiry_date','expDate','shelfLife','shelf_life'];
        foreach ($keys as $k) {
            if (!empty($json[$k]) && is_string($json[$k])) {
                $d = $this->normalizeDate($json[$k]);
                if ($d) return $d;
            }
        }

        // иногда дата лежит в под-объектах
        $stack = [$json];
        while ($stack) {
            $node = array_pop($stack);
            if (!is_array($node)) continue;

            foreach ($node as $k => $v) {
                if (is_string($v)) {
                    if (stripos((string)$k, 'exp') !== false || stripos((string)$k, 'srok') !== false) {
                        $d = $this->normalizeDate($v);
                        if ($d) return $d;
                    }
                } elseif (is_array($v)) {
                    $stack[] = $v;
                }
            }
        }

        return null;
    }

    private function normalizeDate(string $s): ?string {
        $s = trim($s);
        if ($s === '') return null;

        // 1) ISO или ISO с временем: 2025-10-05 / 2025-10-05T12:34:56Z
        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        // 2) склеенная: 20251005
        if (preg_match('~^(\d{4})(\d{2})(\d{2})$~', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        // 3) русская: 05.10.2025
        if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})$~', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        return null;
    }

    public function __destruct() {
        if ($this->db) { $this->db->close(); }
    }
}
