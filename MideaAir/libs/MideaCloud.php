<?php

declare(strict_types=1);

/**
 * Midea Cloud-API-Client für Token-Ermittlung und Geräteliste.
 * Portiert aus midea-beautiful-air (Python) von nbogojevic.
 *
 * Unterstützte Apps: NetHome Plus, Midea Air, Ariston Clima.
 */
class MideaCloud
{
    // Bekannte Apps und ihre Zugangsdaten
    // EU-Varianten nutzen mapp-eu.appsmb.com statt mapp.appsmb.com
    private const APPS = [
        'NetHome Plus' => [
            'appkey'  => '3742e9e5842d4ad59c2db887e12449f9',
            'appid'   => 1017,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
        'NetHome Plus (EU)' => [
            'appkey'  => '3742e9e5842d4ad59c2db887e12449f9',
            'appid'   => 1017,
            'apiurl'  => 'https://mapp-eu.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
        'Midea Air' => [
            'appkey'  => 'ff0cf6f5f0c3471de36341cab3f7a9af',
            'appid'   => 1117,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
        'Midea Air (EU)' => [
            'appkey'  => 'ff0cf6f5f0c3471de36341cab3f7a9af',
            'appid'   => 1117,
            'apiurl'  => 'https://mapp-eu.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
        'Ariston Clima' => [
            'appkey'  => '434a209a5ce141c3b726de067835d7f0',
            'appid'   => 1005,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
    ];

    public int $maxRetries = 3;
    public float $requestTimeout = 10.0;

    private string $account;
    private string $password;
    private string $appName;
    private array $appConfig;
    private MideaCrypto $crypto;

    private string $loginId = '';
    private string $sessionId = '';
    private string $dataKey = '';
    private string $deviceId;

    // Logger-Callback für IP-Symcon
    private $logger = null;

    public function __construct(string $account, string $password, string $appName = 'NetHome Plus')
    {
        if (!isset(self::APPS[$appName])) {
            throw new InvalidArgumentException("Unbekannte App: $appName. Gültig: " . implode(', ', array_keys(self::APPS)));
        }
        $this->account = $account;
        $this->password = $password;
        $this->appName = $appName;
        $this->appConfig = self::APPS[$appName];

        $this->crypto = new MideaCrypto($this->appConfig['appkey'], $this->appConfig['signkey']);
        $this->deviceId = md5(uniqid('midea', true));
    }

    /**
     * Setzt einen Logger-Callback für Meldungen.
     * @param callable $callback Funktion(string $message, int $level)
     */
    public function setLogger(callable $callback): void
    {
        $this->logger = $callback;
    }

    // ── Authentifizierung ─────────────────────────────────────────────────

    /** Führt den kompletten Login-Prozess durch. */
    public function authenticate(): void
    {
        $this->log('authenticate() START: app=' . $this->appName);

        if (empty($this->loginId)) {
            $this->loginId = $this->getLoginId();
        }

        $session = $this->login();
        $this->sessionId = (string) ($session['sessionId'] ?? '');

        if (!empty($session['accessToken'])) {
            $this->dataKey = $this->crypto->deriveDataKey($session['accessToken']);
        }

        if (empty($this->sessionId)) {
            throw new RuntimeException('Kein sessionId in Login-Antwort. Passwort falsch?');
        }

        $this->log('authenticate() FERTIG');
    }

    // ── Token-Ermittlung ──────────────────────────────────────────────────

    /**
     * Ermittelt Token und Key für eine UDP-ID.
     * Gibt [token, key] zurück oder ['', ''] wenn nicht gefunden.
     */
    public function getToken(string $udpId, string $applianceId = ''): array
    {
        $url = $this->appConfig['apiurl'] . '/v1/iot/secure/getToken';
        $extra = [
            'udpid'     => $udpId,
            'sessionId' => $this->sessionId,
        ];
        if ($applianceId !== '') {
            $extra['applianceCodes'] = $applianceId;
        }
        $params = $this->buildBaseParams($extra);

        $this->log("getToken(): udpId=$udpId");
        $result = $this->apiRequest('POST', $url, $params);
        $this->log('getToken() Antwort: ' . json_encode($result));

        if (isset($result['tokenlist']) && is_array($result['tokenlist'])) {
            foreach ($result['tokenlist'] as $entry) {
                if (isset($entry['udpId']) && $entry['udpId'] === $udpId) {
                    return [$entry['token'] ?? '', $entry['key'] ?? ''];
                }
            }
            // Kein exakter Treffer – ersten Eintrag nehmen
            if (!empty($result['tokenlist'][0])) {
                $first = $result['tokenlist'][0];
                return [$first['token'] ?? '', $first['key'] ?? ''];
            }
        }

        $this->log('getToken(): kein Token gefunden');
        return ['', ''];
    }

    // ── Geräteliste ───────────────────────────────────────────────────────

    /** Gibt alle registrierten Geräte des Accounts zurück. */
    public function listAppliances(): array
    {
        $url = $this->appConfig['apiurl'] . '/v1/appliance/user/list/get';
        $params = $this->buildBaseParams(['sessionId' => $this->sessionId]);
        $result = $this->apiRequest('POST', $url, $params);

        $appliances = [];
        foreach ($result['list'] ?? [] as $item) {
            $sn = '';
            if (!empty($item['sn']) && $this->dataKey !== '') {
                $sn = $this->crypto->decryptDataString($item['sn'], $this->dataKey);
            }
            // EU-Server liefern token/key direkt im Listeneintrag
            $this->log('listAppliances() Gerät: id=' . ($item['id'] ?? '') .
                ' token=' . (empty($item['token']) ? '(leer)' : '(vorhanden)') .
                ' key=' . (empty($item['key']) ? '(leer)' : '(vorhanden)'));
            $appliances[] = [
                'id'    => (string) ($item['id'] ?? ''),
                'name'  => (string) ($item['name'] ?? ''),
                'sn'    => $sn,
                'type'  => (string) ($item['type'] ?? ''),
                'model' => (string) ($item['modelNumber'] ?? ''),
                'token' => (string) ($item['token'] ?? ''),
                'key'   => (string) ($item['key'] ?? ''),
            ];
        }
        return $appliances;
    }

    private function log(string $message, int $level = KL_MESSAGE): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, "[MideaCloud] $message", $level);
        }
    }

    private function getLoginId(): string
    {
        $url = $this->appConfig['apiurl'] . '/v1/user/login/id/get';
        $params = $this->buildBaseParams(['loginAccount' => $this->account]);
        $result = $this->apiRequest('POST', $url, $params);

        if (empty($result['loginId'])) {
            throw new RuntimeException('loginId nicht in Antwort: ' . json_encode($result));
        }

        return (string) $result['loginId'];
    }

    private function login(): array
    {
        $url = $this->appConfig['apiurl'] . '/v1/user/login';
        $encPwd = $this->crypto->encryptPassword($this->loginId, $this->password);
        $params = $this->buildBaseParams([
            'loginAccount' => $this->account,
            'password'     => $encPwd,
        ]);

        return $this->apiRequest('POST', $url, $params);
    }

    // ── HTTP-Hilfsmethoden ────────────────────────────────────────────────

    private function buildBaseParams(array $extra = []): array
    {
        $params = [
            'appId'      => (string) $this->appConfig['appid'],
            'format'     => '2',
            'clientType' => '1',
            'language'   => 'en_US',
            'src'        => (string) $this->appConfig['appid'],
            'stamp'      => date('YmdHis'),
            'deviceId'   => 'c1acad8939ac0d7d',
        ];
        return array_merge($params, $extra);
    }

    /**
     * Sendet eine signierte API-Anfrage und gibt das result-Feld zurück.
     * @throws RuntimeException bei Netzwerk- oder API-Fehlern
     */
    private function apiRequest(string $method, string $url, array $params): array
    {
        $params['sign'] = $this->crypto->sign($url, $params);

        $lastError = '';
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            try {
                $responseJson = $this->httpRequest($method, $url, $params);
                $response = json_decode($responseJson, true);

                if (!is_array($response)) {
                    throw new RuntimeException('Ungültige JSON-Antwort: ' . substr($responseJson, 0, 300));
                }

                $errorCode = $response['errorCode'] ?? $response['code'] ?? '0';
                if ((string) $errorCode !== '0') {
                    $msg = $response['msg'] ?? $response['message'] ?? "Fehler $errorCode";
                    throw new RuntimeException("Cloud-API Fehler $errorCode: $msg");
                }

                return $response['result'] ?? $response['data'] ?? [];

            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                if ($attempt < $this->maxRetries - 1) {
                    usleep(500000 * ($attempt + 1));
                }
            }
        }
        throw new RuntimeException("Cloud-API nach {$this->maxRetries} Versuchen: $lastError");
    }

    private function httpRequest(string $method, string $url, array $params): string
    {
        $ch = curl_init();

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ceil($this->requestTimeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Dalvik/2.1.0 (Linux; U; Android 7.0)',
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException("HTTP-Fehler ($errno): $error");
        }

        return (string) $response;
    }
}

/**
 * Midea SmartHome Cloud (mp-prod.appsmb.com) mit HMAC-SHA256-Signing.
 * Portiert aus msmart (mill1000/midea-msmart) – SmartHomeCloud-Klasse.
 *
 * Login-Body: data={platform:2, deviceId}, iotData ohne format/language/platformId,
 * aber mit pushToken (token_urlsafe(120)-Äquivalent). format/clientType als Integer.
 * accessToken aus mdata.accessToken im Header aller Folge-Requests.
 */
class MideaSmartHomeCloud
{
    private const APP_ID   = '1010';
    private const APP_KEY  = 'ac21b9f9cbfe4ca5a88562ef25e2b768';
    private const API_BASE = 'https://mp-prod.appsmb.com/mas/v5/app/proxy?alias=';
    private const IOT_KEY  = 'meicloud';
    private const HMAC_KEY = 'PROD_VnoClJI9aikS8dyy';

    // authorization: Basic base64(APP_KEY:IOT_KEY) – wie midea-local
    private const AUTH_BASE = 'YWMyMWI5ZjljYmZlNGNhNWE4ODU2MmVmMjVlMmI3Njg6bWVpY2xvdWQ=';

    private string $account;
    private string $password;
    private string $deviceId;
    private string $loginId     = '';
    private string $uid         = '';
    private string $accessToken = '';
    private string $apiBase     = self::API_BASE;

    private $logger = null;

    public function __construct(string $account, string $password)
    {
        $this->account  = $account;
        $this->password = $password;
        $this->deviceId = md5($account);
    }

    public function setLogger(callable $callback): void
    {
        $this->logger = $callback;
    }

    // ── Authentifizierung ─────────────────────────────────────────────────

    public function authenticate(): void
    {
        $this->log('SmartHome authenticate() START');
        // reRoute: ermittelt regionalen Server für diesen Account (wie midea-local)
        $masUrl = $this->reRoute();
        if ($masUrl !== null) {
            $this->apiBase = $masUrl;
            $this->log("reRoute → apiBase: {$this->apiBase}");
        } else {
            $this->log('reRoute: kein masUrl, bleibe bei ' . self::API_BASE);
        }
        $this->loginId = $this->fetchLoginId();
        $this->log("loginId erhalten");
        $this->login();
        $this->log("authenticate() FERTIG, uid={$this->uid}");
    }

    // ── Token-Ermittlung ──────────────────────────────────────────────────

    /** Gibt [token, key] für eine UDP-ID zurück oder ['', ''] wenn nicht gefunden. */
    public function getToken(string $udpId, string $applianceId = ''): array
    {
        $args = ['udpid' => $udpId];
        if ($applianceId !== '') {
            $args['applianceCodes'] = $applianceId;
        }

        $this->log("getToken(): udpId=$udpId");
        $result = $this->apiRequest('/v1/iot/secure/getToken', $args);
        $this->log('getToken() Antwort: ' . json_encode($result));

        foreach ($result['tokenlist'] ?? [] as $entry) {
            if (isset($entry['udpId']) && $entry['udpId'] === $udpId) {
                return [$entry['token'] ?? '', $entry['key'] ?? ''];
            }
        }
        if (!empty($result['tokenlist'][0])) {
            $first = $result['tokenlist'][0];
            return [$first['token'] ?? '', $first['key'] ?? ''];
        }

        $this->log('getToken(): kein Token gefunden');
        return ['', ''];
    }

    // ── Geräteliste ───────────────────────────────────────────────────────

    public function listAppliances(): array
    {
        $result = $this->apiRequest('/v1/appliance/user/list/get', []);
        $appliances = [];
        foreach ($result['list'] ?? [] as $item) {
            $this->log('SmartHome Gerät: id=' . ($item['id'] ?? '') .
                ' token=' . (empty($item['token']) ? '(leer)' : '(vorhanden)') .
                ' key=' . (empty($item['key']) ? '(leer)' : '(vorhanden)'));
            $appliances[] = [
                'id'    => (string) ($item['id'] ?? ''),
                'name'  => (string) ($item['name'] ?? ''),
                'sn'    => '',
                'type'  => (string) ($item['type'] ?? ''),
                'model' => (string) ($item['modelNumber'] ?? ''),
                'token' => (string) ($item['token'] ?? ''),
                'key'   => (string) ($item['key'] ?? ''),
            ];
        }
        return $appliances;
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────

    private function reRoute(): ?string
    {
        $body = [
            'userType'   => '0',
            'userName'   => $this->account,
            'clientType' => '1',
            'platformId' => '1',
            'stamp'      => date('YmdHis'),
        ];
        try {
            $result = $this->apiPostRaw('/v1/multicloud/platform/user/route', $body);
            $masUrl = trim($result['masUrl'] ?? '');
            if (strncmp($masUrl, 'http', 4) === 0) {
                return rtrim($masUrl, '/');
            }
        } catch (Exception $e) {
            $this->log('reRoute Fehler (ignoriert): ' . $e->getMessage());
        }
        return null;
    }

    private function fetchLoginId(): string
    {
        $result = $this->apiRequest('/v1/user/login/id/get', ['loginAccount' => $this->account]);
        if (empty($result['loginId'])) {
            throw new RuntimeException('SmartHome: loginId fehlt: ' . json_encode($result));
        }
        return (string) $result['loginId'];
    }

    private function login(): void
    {
        $stamp = date('YmdHis');
        $iotData = [
            'appId'        => self::APP_ID,
            'src'          => self::APP_ID,
            'clientType'   => 1,
            'loginAccount' => $this->account,
            'iampwd'       => $this->encryptIamPassword($this->loginId, $this->password),
            'password'     => $this->encryptPassword($this->loginId, $this->password),
            'pushToken'    => strtr(base64_encode(random_bytes(90)), '+/', '-_'),
            'stamp'        => $stamp,
            'reqId'        => bin2hex(random_bytes(16)),
        ];
        $body = [
            'data'    => ['platform' => 2, 'deviceId' => $this->deviceId],
            'iotData' => $iotData,
        ];

        $result = $this->apiPostRaw('/mj/user/login', $body);

        if (empty($result['uid'])) {
            throw new RuntimeException('SmartHome Login: uid fehlt. Antwort: ' . json_encode($result));
        }

        $this->uid         = (string) $result['uid'];
        $this->accessToken = (string) ($result['mdata']['accessToken']
                                    ?? $result['mdata']['tokenPwdInfo']['tokenPwd']
                                    ?? '');
        $this->log("Login OK: uid={$this->uid}, token=" . (empty($this->accessToken) ? '(leer)' : '(vorhanden)'));
    }

    private function encryptPassword(string $loginId, string $password): string
    {
        return hash('sha256', $loginId . hash('sha256', $password) . self::APP_KEY);
    }

    private function encryptIamPassword(string $loginId, string $password): string
    {
        return hash('sha256', $loginId . md5(md5($password)) . self::APP_KEY);
    }

    private function sign(string $jsonData, string $random): string
    {
        return hash_hmac('sha256', self::IOT_KEY . $jsonData . $random, self::HMAC_KEY);
    }

    /**
     * JSON-Serializer identisch zu Python json.dumps() mit Standard-Separatoren (", " und ": ").
     * Nötig weil der SmartHome-Server den Body re-serialisiert und dann den HMAC prüft.
     */
    private function jsonPython(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($value)) {
            if (empty($value)) {
                return array_is_list($value) ? '[]' : '{}';
            }
            if (array_is_list($value)) {
                return '[' . implode(', ', array_map(fn($v) => $this->jsonPython($v), $value)) . ']';
            }
            $items = [];
            foreach ($value as $k => $v) {
                $items[] = json_encode((string) $k) . ': ' . $this->jsonPython($v);
            }
            return '{' . implode(', ', $items) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Normaler API-Request: Basis-Felder (appId, format, clientType, …) werden
     * vorangestellt, dann $args gemergt. Entspricht msmart api_request() mit args=-Parameter.
     */
    private function apiRequest(string $endpoint, array $args): array
    {
        $data = [
            'appId'      => self::APP_ID,
            'format'     => 2,
            'clientType' => 1,
            'language'   => 'en_US',
            'src'        => self::APP_ID,
            'stamp'      => date('YmdHis'),
            'platformId' => '1',
            'deviceId'   => $this->deviceId,
            'uid'        => null,
        ];
        $data = array_merge($data, $args);
        if (empty($data['reqId'])) {
            $data['reqId'] = bin2hex(random_bytes(16));
        }

        return $this->apiPostRaw($endpoint, $data);
    }

    /**
     * POST mit genau dem übergebenen Body (kein Base-Merge).
     * Entspricht msmart api_request() mit data=-Parameter.
     */
    private function apiPostRaw(string $endpoint, array $body, int $retries = 0): array
    {
        if (empty($body['reqId'])) {
            $body['reqId'] = bin2hex(random_bytes(16));
        }

        $url      = $this->apiBase . $endpoint;
        $random   = bin2hex(random_bytes(16));
        // Python-kompatible Serialisierung (`: ` und `, ` wie json.dumps-Default)
        $jsonData = $this->jsonPython($body);
        $sign     = $this->sign($jsonData, $random);
        $this->log("POST $endpoint | body=" . substr($jsonData, 0, 200));

        $headers = [
            'content-type: application/json; charset=utf-8',
            'secretVersion: 1',
            'sign: ' . $sign,
            'random: ' . $random,
            'x-recipe-app: ' . self::APP_ID,
            'authorization: Basic ' . self::AUTH_BASE,
            'accessToken: ' . $this->accessToken,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException("SmartHome HTTP-Fehler ($errno): $error");
        }

        $this->log("SmartHome $endpoint → " . substr($response, 0, 400));

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SmartHome: Ungültige JSON-Antwort: ' . substr($response, 0, 300));
        }

        $code = (int) ($decoded['code'] ?? -1);
        if ($code !== 0) {
            $msg = $decoded['msg'] ?? $decoded['message'] ?? "Fehler $code";
            // Codes, die msmart ignoriert und per Retry auflöst (3004 = value is illegal, 9999 = system error)
            if (in_array($code, [3004, 9999], true) && $retries < 3) {
                $this->log("SmartHome $endpoint: Code $code ($msg) – Retry " . ($retries + 1) . '/3');
                return $this->apiPostRaw($endpoint, $body, $retries + 1);
            }
            throw new RuntimeException("SmartHome-API $endpoint: Code $code – $msg");
        }

        return $decoded['data'] ?? [];
    }

    private function log(string $message, int $level = KL_MESSAGE): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, "[SmartHomeCloud] $message", $level);
        }
    }
}
