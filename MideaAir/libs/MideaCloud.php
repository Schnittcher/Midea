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
    private const APPS = [
        'NetHome Plus' => [
            'appkey'  => '3742e9e5842d4ad59c2db887e12449f9',
            'appid'   => 1017,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
        'Midea Air' => [
            'appkey'  => 'ff0cf6f5f0c3471de36341cab3f7a9af',
            'appid'   => 1117,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
        'Ariston Clima' => [
            'appkey'  => '434a209a5ce141c3b726de067835d7f0',
            'appid'   => 1005,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
        ],
    ];

    private string      $account;
    private string      $password;
    private string      $appName;
    private array       $appConfig;
    private MideaCrypto $crypto;

    private string $loginId   = '';
    private string $sessionId = '';
    private string $dataKey   = '';
    private string $deviceId;

    // Logger-Callback für IP-Symcon
    private $logger = null;

    public int   $maxRetries      = 3;
    public float $requestTimeout  = 10.0;

    /**
     * Setzt einen Logger-Callback für Meldungen.
     * @param callable $callback Funktion(string $message, int $level)
     */
    public function setLogger(callable $callback): void
    {
        $this->logger = $callback;
    }

    private function log(string $message, int $level = KL_MESSAGE): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, "[MideaCloud] $message", $level);
        }
    }

    public function __construct(string $account, string $password, string $appName = 'NetHome Plus')
    {
        if (!isset(self::APPS[$appName])) {
            throw new InvalidArgumentException("Unbekannte App: $appName. Gültig: " . implode(', ', array_keys(self::APPS)));
        }
        $this->account   = $account;
        $this->password  = $password;
        $this->appName   = $appName;
        $this->appConfig = self::APPS[$appName];

        $this->crypto   = new MideaCrypto($this->appConfig['appkey'], $this->appConfig['signkey']);
        $this->deviceId = md5(uniqid('midea', true));
    }

    // ── Authentifizierung ─────────────────────────────────────────────────

    /** Führt den kompletten Login-Prozess durch. */
    public function authenticate(): void
    {
        $this->log("authenticate() START: app=" . $this->appName);

        if (empty($this->loginId)) {
            $this->loginId = $this->getLoginId();
        }

        $session         = $this->login();
        $this->sessionId = (string)($session['sessionId'] ?? '');

        if (!empty($session['accessToken'])) {
            $this->dataKey = $this->crypto->deriveDataKey($session['accessToken']);
        }

        if (empty($this->sessionId)) {
            throw new RuntimeException('Kein sessionId in Login-Antwort. Passwort falsch?');
        }

        $this->log("authenticate() FERTIG");
    }

    private function getLoginId(): string
    {
        $url    = $this->appConfig['apiurl'] . '/v1/user/login/id/get';
        $params = $this->buildBaseParams(['loginAccount' => $this->account]);
        $result = $this->apiRequest('POST', $url, $params);

        if (empty($result['loginId'])) {
            throw new RuntimeException('loginId nicht in Antwort: ' . json_encode($result));
        }

        return (string)$result['loginId'];
    }

    private function login(): array
    {
        $url    = $this->appConfig['apiurl'] . '/v1/user/login';
        $encPwd = $this->crypto->encryptPassword($this->loginId, $this->password);
        $params = $this->buildBaseParams([
            'loginAccount' => $this->account,
            'password'     => $encPwd,
        ]);

        return $this->apiRequest('POST', $url, $params);
    }

    // ── Token-Ermittlung ──────────────────────────────────────────────────

    /**
     * Ermittelt Token und Key für eine UDP-ID.
     * Gibt [token, key] zurück oder ['', ''] wenn nicht gefunden.
     */
    public function getToken(string $udpId): array
    {
        $url    = $this->appConfig['apiurl'] . '/v1/iot/secure/getToken';
        $params = $this->buildBaseParams([
            'udpid'     => $udpId,
            'sessionId' => $this->sessionId,
        ]);

        $this->log("getToken(): udpId=$udpId");
        $result = $this->apiRequest('POST', $url, $params);
        $this->log("getToken() Antwort: " . json_encode($result));

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

        $this->log("getToken(): kein Token gefunden");
        return ['', ''];
    }

    // ── Geräteliste ───────────────────────────────────────────────────────

    /** Gibt alle registrierten Geräte des Accounts zurück. */
    public function listAppliances(): array
    {
        $url    = $this->appConfig['apiurl'] . '/v1/appliance/user/list/get';
        $params = $this->buildBaseParams(['sessionId' => $this->sessionId]);
        $result = $this->apiRequest('POST', $url, $params);

        $appliances = [];
        foreach ($result['list'] ?? [] as $item) {
            $sn = '';
            if (!empty($item['sn']) && $this->dataKey !== '') {
                $sn = $this->crypto->decryptDataString($item['sn'], $this->dataKey);
            }
            $appliances[] = [
                'id'    => (string)($item['id'] ?? ''),
                'name'  => (string)($item['name'] ?? ''),
                'sn'    => $sn,
                'type'  => (string)($item['type'] ?? ''),
                'model' => (string)($item['modelNumber'] ?? ''),
            ];
        }
        return $appliances;
    }

    // ── HTTP-Hilfsmethoden ────────────────────────────────────────────────

    private function buildBaseParams(array $extra = []): array
    {
        $params = [
            'appId'      => (string)$this->appConfig['appid'],
            'format'     => '2',
            'clientType' => '1',
            'language'   => 'en_US',
            'src'        => (string)$this->appConfig['appid'],
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
                $response     = json_decode($responseJson, true);

                if (!is_array($response)) {
                    throw new RuntimeException('Ungültige JSON-Antwort: ' . substr($responseJson, 0, 300));
                }

                $errorCode = $response['errorCode'] ?? $response['code'] ?? '0';
                if ((string)$errorCode !== '0') {
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
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)ceil($this->requestTimeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Dalvik/2.1.0 (Linux; U; Android 7.0)',
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException("HTTP-Fehler ($errno): $error");
        }

        return (string)$response;
    }
}
