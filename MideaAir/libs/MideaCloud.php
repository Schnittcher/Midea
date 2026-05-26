<?php
declare(strict_types=1);

/**
 * Midea Cloud-API-Client für Token-Ermittlung und Geräteliste.
 * Portiert aus midea-beautiful-air (Python) von nbogojevic.
 *
 * Unterstützte Apps: NetHome Plus, Midea Air, Ariston Clima, MSmartHome.
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
            'proxied' => null,
            'iotkey'  => null,
            'hmackey' => null,
        ],
        'Midea Air' => [
            'appkey'  => 'ff0cf6f5f0c3471de36341cab3f7a9af',
            'appid'   => 1117,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
            'proxied' => null,
            'iotkey'  => null,
            'hmackey' => null,
        ],
        'Ariston Clima' => [
            'appkey'  => '434a209a5ce141c3b726de067835d7f0',
            'appid'   => 1005,
            'apiurl'  => 'https://mapp.appsmb.com',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
            'proxied' => null,
            'iotkey'  => null,
            'hmackey' => null,
        ],
        'MSmartHome' => [
            'appkey'  => 'ac21b9f9cbfe4ca5a88562ef25e2b768',
            'appid'   => 1010,
            'apiurl'  => 'https://mp-prod.appsmb.com/mas/v5/app/proxy?alias=',
            'signkey' => 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S',
            'proxied' => 'v5',
            'iotkey_encrypted'  => 'f4dcd1511147af45775d7e680ac5312b',
            'hmackey_encrypted' => '5018e65c32bcec087e6c01631d8cf55398308fc19344d3e130734da81ac2e162',
        ],
    ];

    // AES-Schlüssel zur Dekodierung der MSmartHome-Secrets
    private const INTERNAL_KEY = 'c8aa6c57402cac8b5674db84acc89be82c704362da72b5ff21544b483b50d39c';

    private string      $account;
    private string      $password;
    private string      $appName;
    private array       $appConfig;
    private MideaCrypto $crypto;

    private string $loginId   = '';
    private string $sessionId = '';
    private string $dataKey   = '';
    private string $deviceId;
    private string $loginTimestamp = '';  // Für konsistente Timestamps zwischen getLoginId und login

    // Proxied (MSmartHome) spezifisch
    private string $uid = '';
    private string $iotkey = '';
    private string $hmackey = '';
    private string $headerAccessToken = '';

    // Logger-Callback für IP-Symcon
    private $logger = null;

    public int   $maxRetries      = 3;
    public float $requestTimeout  = 10.0;

    /**
     * Setzt einen Logger-Callback für Meldungen
     * @param callable $callback Funktion(string $message, int $level)
     */
    public function setLogger(callable $callback): void
    {
        $this->logger = $callback;
    }

    private function log(string $message, int $level = KL_MESSAGE): void
    {
        // Versuche logger callback wenn registriert
        if ($this->logger !== null) {
            call_user_func($this->logger, "[MideaCloud] $message", $level);
        }

        // Fallback: Versuche IPS_LogMessage direkt (wenn in IP-Symcon Modul)
        if (function_exists('IPS_LogMessage')) {
            IPS_LogMessage("[MideaCloud] DEBUG", $message);
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

        // MSmartHome: Keys dekodieren
        if ($appName === 'MSmartHome' && isset($this->appConfig['iotkey_encrypted'])) {
            try {
                $this->appConfig['iotkey']  = $this->decryptInternal($this->appConfig['iotkey_encrypted']);
                $this->appConfig['hmackey'] = $this->decryptInternal($this->appConfig['hmackey_encrypted']);

                // WICHTIG: Überprüfe dass Keys Hex-Strings sind
                $iotkey_valid = !empty($this->appConfig['iotkey']) && ctype_xdigit($this->appConfig['iotkey']);
                $hmackey_valid = !empty($this->appConfig['hmackey']) && ctype_xdigit($this->appConfig['hmackey']);

                $msg = "✓ MSmartHome Keys dekodiert: " .
                       "iotkey=" . strlen($this->appConfig['iotkey']) . " chars (valid=$iotkey_valid) " .
                       "hmackey=" . strlen($this->appConfig['hmackey']) . " chars (valid=$hmackey_valid) " .
                       "iotkey_start=" . substr($this->appConfig['iotkey'], 0, 16) . "... " .
                       "hmackey_start=" . substr($this->appConfig['hmackey'], 0, 16) . "...";
                $this->log($msg);

                if (!$iotkey_valid || !$hmackey_valid) {
                    throw new RuntimeException("Keys sind keine gültigen Hex-Strings! iotkey=$iotkey_valid hmackey=$hmackey_valid");
                }
            } catch (Exception $e) {
                $errMsg = "✗ Dekryption Fehler: " . $e->getMessage();
                $this->log($errMsg, KL_ERROR);
                throw $e;
            }
        }

        $this->crypto    = new MideaCrypto($this->appConfig['appkey'], $this->appConfig['signkey']);
        $this->deviceId  = md5(uniqid('midea', true));
    }

    /** Dekodiert MSmartHome-Keys mit AES-256-ECB + PKCS7, gibt Hex-String zurück (wie Python) */
    private function decryptInternal(string $hexData): string
    {
        // DEBUG: Überprüfe Input
        if (strlen($hexData) % 2 !== 0) {
            throw new RuntimeException("✗ Hex-String ungerade Länge: " . strlen($hexData));
        }

        $encrypted = @hex2bin($hexData);
        if ($encrypted === false) {
            throw new RuntimeException("✗ hex2bin() fehlgeschlagen für: " . substr($hexData, 0, 32) . "...");
        }

        $key = @hex2bin(self::INTERNAL_KEY);
        if ($key === false) {
            throw new RuntimeException("✗ hex2bin() für INTERNAL_KEY fehlgeschlagen");
        }

        // Überprüfe Längen
        if (strlen($key) !== 32) {
            throw new RuntimeException("✗ Key ist nicht 32 Bytes: " . strlen($key));
        }

        if (strlen($encrypted) % 16 !== 0) {
            throw new RuntimeException("✗ Encrypted ist nicht Vielfaches von 16 Bytes: " . strlen($encrypted));
        }

        // Versuche AES-256-ECB mit OPENSSL_RAW_DATA (kein auto-unpadding)
        $decrypted = @openssl_decrypt($encrypted, 'aes-256-ecb', $key, OPENSSL_RAW_DATA);
        if ($decrypted === false) {
            // Fallback: Versuche mit flag=0
            $decrypted = @openssl_decrypt($encrypted, 'aes-256-ecb', $key, 0);
            if ($decrypted === false) {
                throw new RuntimeException("✗ openssl_decrypt() fehlgeschlagen: " . openssl_error_string());
            }
        }

        if (strlen($decrypted) === 0) {
            throw new RuntimeException("✗ Dekryption ergab leeren String");
        }

        // PKCS7-Unpadding: Das letzte Byte ist die Padding-Länge
        $paddingLength = ord($decrypted[strlen($decrypted) - 1]);

        // Sicherheitscheck: Padding sollte zwischen 1 und 16 sein
        if ($paddingLength > 0 && $paddingLength <= 16 && $paddingLength <= strlen($decrypted)) {
            $unpadded = substr($decrypted, 0, -$paddingLength);
            return bin2hex($unpadded);
        }

        // Fallback: Falls kein gültiges Padding, gibt den gesamten String zurück
        return bin2hex($decrypted);
    }

    /** Gibt die Liste der unterstützten App-Namen zurück. */
    public static function supportedApps(): array
    {
        return array_keys(self::APPS);
    }

    // ── Authentifizierung ─────────────────────────────────────────────────

    /**
     * Führt den kompletten Login-Prozess durch.
     * Wählt automatisch zwischen Standard und Proxied (MSmartHome).
     */
    public function authenticate(): void
    {
        try {
            $this->log("✓ authenticate() START: app=" . $this->appName . " proxied=" . ($this->appConfig['proxied'] ?? 'no'));

            // WICHTIG: Für MSmartHome auch region-basierte API-URL abrufen
            if ($this->appConfig['proxied'] === 'v5') {
                $this->log("→ Ermittle regionale API-URL für MSmartHome...");
                $this->getRegionUrl();
            }

            if ($this->appConfig['proxied'] === 'v5') {
                $this->log("→ Verwende authenticateProxied (MSmartHome)");
                $this->authenticateProxied();
            } else {
                $this->log("→ Verwende authenticateStandard");
                $this->authenticateStandard();
            }

            $this->log("✓ authenticate() FERTIG");
        } catch (Exception $e) {
            $this->log("✗ authenticate() EXCEPTION: " . $e->getMessage(), KL_ERROR);
            throw $e;
        }
    }

    /**
     * Ermittelt die regionale API-URL via /v1/multicloud/platform/user/route
     * (Wichtig für Deutschland/EU!)
     * WICHTIG: authenticate=false, da dies WÄHREND authenticate() aufgerufen wird!
     */
    private function getRegionUrl(): void
    {
        try {
            $url = 'https://mapp.appsmb.com/v1/multicloud/platform/user/route';
            $params = [
                'appId'      => (string)$this->appConfig['appid'],
                'format'     => '2',
                'clientType' => '1',
                'language'   => 'en_US',
                'src'        => (string)$this->appConfig['appid'],
                'stamp'      => date('YmdHis'),
                'userName'   => $this->account,
            ];

            $this->log("→ getRegionUrl: " . $url . " userName=" . $this->account);

            // WICHTIG: Direkter HTTP-Request OHNE apiRequest(), da wir noch nicht authentifiziert sind!
            $responseJson = $this->httpRequest('POST', $url, $params);
            $response = json_decode($responseJson, true);

            if (!is_array($response)) {
                throw new RuntimeException('Ungültige JSON-Antwort');
            }

            $result = $response['result'] ?? $response;

            if (!empty($result['masUrl'])) {
                $newUrl = (string)$result['masUrl'];
                $this->log("✓ Region API-URL aktualisiert: " . $newUrl);
                // Überschreibe die API-URL mit der regionalen URL
                $this->appConfig['apiurl'] = $newUrl;
            }

            if (!empty($result['countryCode'])) {
                $this->log("✓ Country Code: " . $result['countryCode']);
            }
        } catch (Exception $e) {
            $this->log("⚠ getRegionUrl fehlgeschlagen: " . $e->getMessage());
            // Nicht kritisch - verwende Standard-URL weiter
        }
    }

    private function authenticateStandard(): void
    {
        $this->log("✓ authenticateStandard() START");

        // Rufe getLoginId() nur auf, wenn noch keine vorhanden ist (wie Python)
        if (empty($this->loginId)) {
            $this->loginId = $this->getLoginId();
        }

        $session         = $this->login();

        $this->log("Login Response Keys: " . implode(', ', array_keys($session)));
        $this->log("Login Response: " . json_encode($session));

        $this->sessionId = (string)($session['sessionId'] ?? '');
        $this->log("  sessionId: " . (empty($this->sessionId) ? "(LEER)" : substr($this->sessionId, 0, 20) . "..."));

        if (!empty($session['accessToken'])) {
            $accessToken = $session['accessToken'];
            $this->log("  accessToken Länge: " . strlen($accessToken) . " chars, Anfang: " . substr($accessToken, 0, 20));
            $this->dataKey = $this->crypto->deriveDataKey($accessToken);
            $this->log("  ✓ dataKey abgeleitet: " . strlen($this->dataKey) . " bytes, Hex: " . bin2hex($this->dataKey));
        } else {
            $this->log("  ✗ accessToken FEHLT in Response!");
        }

        if (empty($this->sessionId)) {
            throw new RuntimeException('Kein sessionId in Login-Antwort. Passwort falsch?');
        }
        $this->log("✓ authenticateStandard() FERTIG");
    }

    private function authenticateProxied(): void
    {
        // MSmartHome v5 Proxied Authentifizierung
        $this->log("✓ authenticateProxied() START");
        $this->loginId = $this->getLoginId();
        $this->log("→ loginId ermittelt: " . $this->loginId);

        $response = $this->loginProxied();
        $this->log("→ loginProxied response erhalten");

        $this->uid = (string)($response['uid'] ?? '');

        // Header-AccessToken kommt aus mdata.accessToken (wie Python)
        if (!empty($response['mdata']) && is_array($response['mdata'])) {
            $this->headerAccessToken = (string)($response['mdata']['accessToken'] ?? '');
        } else {
            $this->headerAccessToken = (string)($response['accessToken'] ?? '');
        }

        // iotkey und hmackey sind bereits dekryptiert und in appConfig
        $this->iotkey = (string)($this->appConfig['iotkey'] ?? '');
        $this->hmackey = (string)($this->appConfig['hmackey'] ?? '');

        if (empty($this->uid) || empty($this->iotkey) || empty($this->hmackey)) {
            throw new RuntimeException('Proxied Login fehlgeschlagen: uid=' . $this->uid . ' iotkey_len=' . strlen($this->iotkey) . ' hmackey_len=' . strlen($this->hmackey));
        }
    }

    private function getLoginId(): string
    {
        try {
            $this->log("✓ getLoginId() START: account=" . $this->account);

            // Speichere den timestamp für Konsistenz zwischen getLoginId und login
            $this->loginTimestamp = date('YmdHis');

            // Wichtig: /v1/ Endpunkte verwenden NICHT den MSmartHome proxy!
            // MSmartHome proxy ist NUR für /mj/ Endpunkte
            $baseUrl = 'https://mapp.appsmb.com';  // Standard API Server
            if ($this->appConfig['proxied'] === 'v5') {
                // Für MSmartHome: Verwende Standard API Server für /v1/ Endpunkte
                $url = $baseUrl . '/v1/user/login/id/get';
            } else {
                $url = $this->appConfig['apiurl'] . '/v1/user/login/id/get';
            }
            $this->log("→ URL: $url (proxied=" . ($this->appConfig['proxied'] ?? 'no') . ")");

            $params = $this->buildBaseParams(['loginAccount' => $this->account]);
            // Verwende gespeicherten timestamp statt neuen
            $params['stamp'] = $this->loginTimestamp;
            $this->log("→ params: " . json_encode($params));

            $result = $this->apiRequest('POST', $url, $params);
            $this->log("→ apiRequest result: " . json_encode($result));

            if (empty($result['loginId'])) {
                throw new RuntimeException(
                    'loginId nicht in Antwort. Raw: ' . json_encode($result)
                );
            }

            $loginId = (string)$result['loginId'];
            $this->log("✓ getLoginId() FERTIG: loginId=" . $loginId);
            return $loginId;
        } catch (Exception $e) {
            $this->log("✗ getLoginId() EXCEPTION: " . $e->getMessage(), KL_ERROR);
            throw $e;
        }
    }

    private function login(): array
    {
        $url     = $this->appConfig['apiurl'] . '/v1/user/login';
        $encPwd  = $this->crypto->encryptPassword($this->loginId, $this->password);

        // WICHTIG: Wie Python - NICHT loginId senden, nur die essentiellen Params!
        $params  = $this->buildBaseParams([
            'loginAccount' => $this->account,
            'password'     => $encPwd,
        ]);

        $this->log("✓ login() START: loginId=" . $this->loginId);
        $this->log("  URL: $url");
        $this->log("  params: " . json_encode($params));

        $result = $this->apiRequest('POST', $url, $params);

        $this->log("  response: " . json_encode($result));
        return $result;
    }

    private function loginProxied(): array
    {
        // MSmartHome v5 Login mit IAM-Passwort
        $this->log("✓ loginProxied() START: loginId=" . $this->loginId);

        $iamPwd = $this->crypto->encryptIamPassword($this->loginId, $this->password);
        $encPwd = $this->crypto->encryptPassword($this->loginId, $this->password);

        $reqId = bin2hex(random_bytes(16));
        $stamp = date('YmdHis');
        $random = (string)time();  // Unix-Timestamp wie Python!

        // Komplette Payload mit allen Feldern (wie Python _login_proxied)
        // WICHTIG: Diese hat bereits reqId, daher werden appVNum etc. in api_request NICHT hinzugefügt!
        $data = [
            'data' => [
                'appKey'   => $this->appConfig['appkey'],
                'appVersion' => '2.22.0',
                'osVersion' => '8.1.0',
                'deviceId' => 'c1acad8939ac0d7d',
                'platform' => '2',
            ],
            'iotData' => [
                'appId'         => (string)$this->appConfig['appid'],
                'appVNum'       => '2.22.0',
                'appVersion'    => '2.22.0',
                'clientType'    => '1',
                'clientVersion' => '2.22.0',
                'format'        => '2',
                'language'      => 'en_US',
                'iampwd'        => $iamPwd,
                'loginAccount'  => $this->account,
                'password'      => $encPwd,
                'pushToken'     => bin2hex(random_bytes(60)),
                'pushType'      => '4',
                'reqId'         => $reqId,
                'retryCount'    => '3',
                'src'           => '10',
                'stamp'         => $stamp,
            ],
            'reqId' => $reqId,
            'stamp' => $stamp,
        ];

        // Signatur wird über die KOMPLETTE data berechnet (ohne appVNum auf Top-Level, da reqId vorhanden ist)
        $dataJson = json_encode($data);

        // Signatur: HMAC-SHA256(iotkey + dataJson + random, hmackey)
        $iotkey = $this->appConfig['iotkey'] ?? '';
        $hmackey = $this->appConfig['hmackey'] ?? '';

        if (empty($iotkey) || empty($hmackey)) {
            $debug = "✗ LoginProxied FEHLER: iotkey_len=" . strlen($iotkey) . " hmackey_len=" . strlen($hmackey) .
                     " random=$random dataJson_len=" . strlen($dataJson);
            $this->log($debug, KL_ERROR);
            throw new RuntimeException($debug);
        }

        $msgForSign = $iotkey . $dataJson . $random;
        $sign = hash_hmac('sha256', $msgForSign, $hmackey);

        $this->log("✓ LoginProxied Signatur: msgLen=" . strlen($msgForSign) . " iotkey=" . strlen($iotkey) .
                   " dataJson=" . strlen($dataJson) . " random=$random sign=" . strlen($sign) . " signHex=" . substr($sign, 0, 16) . "...");

        if (empty($sign)) {
            throw new RuntimeException("✗ Signatur LEER! iotkey_len=" . strlen($iotkey) . " hmackey_len=" . strlen($hmackey));
        }

        // Proxied-Anfrage mit vollständigen Headers senden
        $url = $this->appConfig['apiurl'] . '/mj/user/login';
        return $this->httpRequestProxied('POST', $url, $data, $sign, $random);
    }

    // ── Token-Ermittlung ──────────────────────────────────────────────────

    /**
     * Ermittelt Token und Key für eine UDP-ID.
     * Gibt [token, key] zurück oder ['', ''] wenn nicht gefunden.
     * Unterstützt Standard und Proxied APIs.
     */
    public function getToken(string $udpId): array
    {
        if ($this->appConfig['proxied'] === 'v5') {
            return $this->getTokenProxied($udpId);
        } else {
            return $this->getTokenStandard($udpId);
        }
    }

    private function getTokenStandard(string $udpId): array
    {
        $url    = $this->appConfig['apiurl'] . '/v1/iot/secure/getToken';
        $params = $this->buildBaseParams([
            'udpid'     => $udpId,
            'sessionId' => $this->sessionId,
        ]);

        $this->log("✓ getTokenStandard() START: udpId=$udpId");
        $this->log("→ URL: $url");
        $this->log("→ params: " . json_encode($params));

        $result = $this->apiRequest('POST', $url, $params);

        $this->log("→ API Response: " . json_encode($result));

        if (isset($result['tokenlist']) && is_array($result['tokenlist'])) {
            $this->log("→ tokenlist vorhanden: " . count($result['tokenlist']) . " Einträge");

            foreach ($result['tokenlist'] as $entry) {
                $this->log("  - Eintrag: " . json_encode($entry));
                if (isset($entry['udpId']) && $entry['udpId'] === $udpId) {
                    $this->log("✓ Gefunden: token=" . substr($entry['token'] ?? '', 0, 20) . "... key=" . substr($entry['key'] ?? '', 0, 20) . "...");
                    return [$entry['token'] ?? '', $entry['key'] ?? ''];
                }
            }

            if (!empty($result['tokenlist'][0])) {
                $first = $result['tokenlist'][0];
                $this->log("✓ Erste Token verwendet (keine exakte Übereinstimmung)");
                return [$first['token'] ?? '', $first['key'] ?? ''];
            }
        } else {
            $this->log("✗ tokenlist nicht im Response oder kein Array!");
        }

        $this->log("✗ getTokenStandard() KEIN TOKEN GEFUNDEN");
        return ['', ''];
    }

    private function getTokenProxied(string $udpId): array
    {
        // MSmartHome v5 Token-Ermittlung
        $random = (string)time();  // Unix-Timestamp wie Python

        $reqId = bin2hex(random_bytes(16));
        $payload = [
            'udpid' => $udpId,
            // Für Signatur benötigte Felder (wie Python api_request hinzufügt)
            'appVNum'       => '2.22.0',
            'appVersion'    => '2.22.0',
            'clientVersion' => '2.22.0',
            'platformId'    => '1',
            'reqId'         => $reqId,
            'retryCount'    => '3',
            'uid'           => $this->uid,
            'userType'      => '0',
        ];

        $dataJson = json_encode($payload);
        $sign = hash_hmac('sha256', $this->iotkey . $dataJson . $random, $this->hmackey);

        $url = $this->appConfig['apiurl'] . '/mj/iot/secure/getToken';
        $result = $this->httpRequestProxiedAuthed('POST', $url, $payload, $sign, $random);

        if (isset($result['tokenlist']) && is_array($result['tokenlist'])) {
            foreach ($result['tokenlist'] as $entry) {
                if (isset($entry['udpId']) && $entry['udpId'] === $udpId) {
                    return [$entry['token'] ?? '', $entry['key'] ?? ''];
                }
            }
            if (!empty($result['tokenlist'][0])) {
                $first = $result['tokenlist'][0];
                return [$first['token'] ?? '', $first['key'] ?? ''];
            }
        }
        return ['', ''];
    }

    // ── Geräteliste ───────────────────────────────────────────────────────

    /**
     * Gibt alle registrierten Geräte zurück.
     * Unterstützt Standard und Proxied APIs.
     */
    public function listAppliances(): array
    {
        if ($this->appConfig['proxied'] === 'v5') {
            return $this->listAppliancesProxied();
        } else {
            return $this->listAppliancesStandard();
        }
    }

    private function listAppliancesStandard(): array
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

    private function listAppliancesProxied(): array
    {
        // MSmartHome v5 Geräteliste
        $random = (string)time();  // Unix-Timestamp wie Python

        $reqId = bin2hex(random_bytes(16));
        $payload = [
            // Für Signatur benötigte Felder (wie Python api_request hinzufügt)
            'appVNum'       => '2.22.0',
            'appVersion'    => '2.22.0',
            'clientVersion' => '2.22.0',
            'platformId'    => '1',
            'reqId'         => $reqId,
            'retryCount'    => '3',
            'uid'           => $this->uid,
            'userType'      => '0',
        ];

        $dataJson = json_encode($payload);
        $sign = hash_hmac('sha256', $this->iotkey . $dataJson . $random, $this->hmackey);

        $url = $this->appConfig['apiurl'] . '/mj/appliance/user/list/get';
        $result = $this->httpRequestProxiedAuthed('POST', $url, $payload, $sign, $random);

        $appliances = [];
        foreach ($result['list'] ?? [] as $item) {
            $appliances[] = [
                'id'    => (string)($item['id'] ?? ''),
                'name'  => (string)($item['name'] ?? ''),
                'sn'    => (string)($item['sn'] ?? ''),
                'type'  => (string)($item['type'] ?? ''),
                'model' => (string)($item['modelNumber'] ?? ''),
            ];
        }
        return $appliances;
    }

    // ── Cloud-Befehl-Senden (für Cloud-Relay Geräte) ─────────────────────

    /**
     * Sendet einen Befehl über die Cloud an ein Gerät.
     * Funktioniert für Geräte, die nur über Cloud-Relay erreichbar sind.
     *
     * @param string $applianceId Geräte-ID
     * @param string $cmdBytes Der Befehl (z.B. aus MideaCommands)
     * @return string|null Die Antwort-Payload oder null bei Fehler
     */
    public function sendCommandViaCloud(string $applianceId, string $cmdBytes): ?string
    {
        if (empty($this->dataKey) && empty($this->sessionId)) {
            $this->log("✗ sendCommandViaCloud: dataKey und sessionId sind leer - Login erforderlich");
            return null;
        }

        $this->log("sendCommandViaCloud START: appId=$applianceId cmdLen=" . strlen($cmdBytes));
        $this->log("  sessionId: " . (empty($this->sessionId) ? "(LEER!)" : substr($this->sessionId, 0, 20) . "..."));
        $this->log("  dataKey Länge: " . strlen($this->dataKey) . " Bytes");

        // WICHTIG: Wie Python - konvertiere Bytes zu CSV ZUERST!
        require_once __DIR__ . '/MideaCommands.php';
        $encoded = encodeAsCSV($cmdBytes);  // z.B. "-86,51,1,..." statt rohe Bytes!
        $this->log("  Encoded (CSV): " . substr($encoded, 0, 80) . "...");

        // Verschlüssele den CSV-String mit AES-128-ECB + PKCS7 Padding (wie Python!)
        $crypto = new MideaCrypto();
        $crypto->setEncryptionKey($this->dataKey);  // Setze den richtigen Schlüssel!
        $encrypted = $crypto->aesEncryptWithPadding($encoded);  // Verschlüssele CSV-String, nicht Bytes!

        $this->log("  Encrypted: " . strlen($encrypted) . " Bytes (mit PKCS7 Padding)");

        // Sende an Cloud API
        $url = $this->appConfig['apiurl'] . '/v1/appliance/transparent/send';
        $params = $this->buildBaseParams([
            'applianceId' => $applianceId,
            'funId'       => '0000',
            'order'       => bin2hex($encrypted),  // Als Hex für JSON
            'sessionId'   => $this->sessionId,      // WICHTIG: sessionId hinzufügen!
        ]);

        $this->log("  URL: $url");
        $this->log("  order (Hex): " . substr(bin2hex($encrypted), 0, 80) . "...");

        $result = $this->apiRequest('POST', $url, $params);

        if (!isset($result['reply'])) {
            $this->log("✗ Keine 'reply' in Cloud-Antwort");
            return null;
        }

        // Dekryptiere die Antwort mit AES-128-ECB + PKCS7 Unpadding (wie Python!)
        $replyEncrypted = hex2bin($result['reply']);
        $replyCSV = $crypto->aesDecryptWithPadding($replyEncrypted);  // Gibt CSV-String zurück!

        // Dekodiere CSV zurück zu Bytes (wie Python: _decode_from_csv)
        $reply = decodeFromCSV($replyCSV);
        $this->log("  Decoded (CSV): " . strlen($reply) . " Bytes");

        // Python entfernt erste 40 Bytes (nach dem Header)
        if (strlen($reply) > 40) {
            $reply = substr($reply, 40);
        }

        $this->log("✓ sendCommandViaCloud FERTIG: payload=" . strlen($reply) . " Bytes");
        return $reply;
    }

    // ── HTTP-Hilfsmethoden ────────────────────────────────────────────────

    private function buildBaseParams(array $extra = []): array
    {
        // EXAKT wie Python: midea_beautiful/cloud.py
        $params = [
            'appId'      => (string)$this->appConfig['appid'],
            'format'     => '2',
            'clientType' => '1',
            'language'   => 'en_US',
            'src'        => (string)$this->appConfig['appid'],
            'stamp'      => date('YmdHis'),
            'deviceId'   => 'c1acad8939ac0d7d',  // ← KRITISCH! Python: CLOUD_API_DEVICE_ID
        ];
        return array_merge($params, $extra);
    }

    /**
     * Signatur für MSmartHome v5 API — nutzt Standard-Methode wie andere APIs.
     */
    private function signMSmartHome(string $url, array $params): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        ksort($params);
        // Exakt wie Standard-APIs: url_encode -> url_decode
        $queryStr = urldecode(http_build_query($params));
        // Standard SHA256 wie andere APIs
        return hash('sha256', $path . $queryStr . $this->appConfig['appkey']);
    }

    /**
     * Sendet eine API-Anfrage und gibt das result-Feld der Antwort zurück.
     *
     * @throws RuntimeException bei Netzwerk- oder API-Fehlern
     */
    private function apiRequest(string $method, string $url, array $params): array
    {
        // Signatur hinzufügen
        if ($this->appConfig['proxied'] === 'v5') {
            // MSmartHome v5 API: Signatur berechnen
            $params['sign'] = $this->signMSmartHome($url, $params);
        } else {
            // Standard Midea APIs: SHA256 Signatur
            $params['sign'] = $this->crypto->sign($url, $params);
        }

        // DEBUG: Signatur prüfen
        if (empty($params['sign'])) {
            throw new RuntimeException(
                'Signatur ist leer! Appkey: ' . $this->appConfig['appkey']
            );
        }

        $lastError = '';
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            try {
                $responseJson = $this->httpRequest($method, $url, $params);
                $response     = json_decode($responseJson, true);

                if (!is_array($response)) {
                    throw new RuntimeException(
                        'Ungültige JSON-Antwort: ' . substr($responseJson, 0, 300)
                    );
                }

                // Fehlercode prüfen
                $errorCode = $response['errorCode'] ?? $response['code'] ?? '0';
                if ((string)$errorCode !== '0') {
                    $msg = $response['msg'] ?? $response['message'] ?? "Fehler $errorCode";
                    throw new RuntimeException("Cloud-API Fehler $errorCode: $msg");
                }

                return $response['result'] ?? $response['data'] ?? [];

            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                if ($attempt < $this->maxRetries - 1) {
                    usleep(500000 * ($attempt + 1)); // 0.5s, 1s, ...
                }
            }
        }
        throw new RuntimeException("Cloud-API nach {$this->maxRetries} Versuchen: $lastError");
    }

    private function httpRequestProxied(string $method, string $url, array $payload, string $sign, string $random): array
    {
        // MSmartHome v5 Login Request (ohne auth noch vorhanden)
        $ch = curl_init();

        // DEBUG - alle Info in Exception-Meldung
        if (empty($sign)) {
            throw new RuntimeException("✗ httpRequestProxied: SIGN LEER! url=$url random=$random");
        }

        // Authorization Header wie Python: Basic base64(appkey:iotkey_hex_string)
        $iotkey = $this->appConfig['iotkey'] ?? '';
        if (empty($iotkey)) {
            throw new RuntimeException("✗ httpRequestProxied: iotkey nicht dekryptiert!");
        }

        // iotkey ist Hex-String (wie in Python), verwende ihn direkt
        $authBasic = base64_encode($this->appConfig['appkey'] . ':' . $iotkey);

        $headers = [
            'Content-Type: application/json',
            'x-recipe-app: ' . $this->appConfig['appid'],
            'Authorization: Basic ' . $authBasic,
            'sign: ' . $sign,
            'secretVersion: 1',
            'random: ' . $random,
            'version: 2.22.0',
            'systemVersion: 8.1.0',
            'platform: 0',
            'Accept-Encoding: identity',
            'uid: guest',
        ];

        // DEBUG: Alle Headers loggen
        $headerNames = array_map(function($h) { $parts = explode(': ', $h); return $parts[0] . '=' . (strlen($parts[1] ?? '') > 0 ? 'YES' : 'NO'); }, $headers);
        $this->log("✓ httpRequestProxied Headers: " . implode(" | ", $headerNames));
        $this->log("✓ httpRequestProxied: sign_hex=" . substr($sign, 0, 16) . "... sign_len=" . strlen($sign) . " random=$random authBasic_len=" . strlen($authBasic));

        $this->sendProxiedRequest($ch, $url, $payload, $headers);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException("HTTP-Fehler ($errno): $error");
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new RuntimeException('Ungültige JSON-Antwort vom Proxied Server');
        }

        $errorCode = $result['code'] ?? '0';
        if ((string)$errorCode !== '0') {
            $msg = $result['msg'] ?? $result['message'] ?? "Fehler $errorCode";
            throw new RuntimeException("Proxied-API Fehler $errorCode: $msg");
        }

        return $result['data'] ?? [];
    }

    private function httpRequestProxiedAuthed(string $method, string $url, array $payload, string $sign, string $random): array
    {
        // MSmartHome v5 Request mit bestehender Authentifizierung
        $ch = curl_init();

        // Authorization Header wie Python: Basic base64(appkey:iotkey_hex_string)
        $iotkey = $this->appConfig['iotkey'] ?? '';
        if (empty($iotkey)) {
            throw new RuntimeException("✗ httpRequestProxiedAuthed: iotkey nicht gesetzt!");
        }

        // iotkey ist Hex-String (wie in Python)
        $authBasic = base64_encode($this->appConfig['appkey'] . ':' . $iotkey);

        $headers = [
            'Content-Type: application/json',
            'x-recipe-app: ' . $this->appConfig['appid'],
            'Authorization: Basic ' . $authBasic,
            'sign: ' . $sign,
            'secretVersion: 1',
            'random: ' . $random,
            'version: 2.22.0',
            'systemVersion: 8.1.0',
            'platform: 0',
            'Accept-Encoding: identity',
            'uid: ' . $this->uid,
            'accessToken: ' . $this->headerAccessToken,
        ];

        $this->sendProxiedRequest($ch, $url, $payload, $headers);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException("HTTP-Fehler ($errno): $error");
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new RuntimeException('Ungültige JSON-Antwort');
        }

        $errorCode = $result['code'] ?? '0';
        if ((string)$errorCode !== '0') {
            $msg = $result['msg'] ?? $result['message'] ?? "Fehler $errorCode";
            throw new RuntimeException("Proxied-API Fehler $errorCode: $msg");
        }

        return $result['data'] ?? [];
    }

    private function sendProxiedRequest($ch, string $url, array $payload, array $headers): void
    {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)ceil($this->requestTimeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    }

    private function httpRequest(string $method, string $url, array $params): string
    {
        $ch = curl_init();

        if ($method === 'GET') {
            // MSmartHome URLs enthalten bereits '?alias=...', daher '&' statt '?' verwenden
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $fullUrl = $url . $separator . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            $postData = http_build_query($params);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
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
