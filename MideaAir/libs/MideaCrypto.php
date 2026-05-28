<?php
declare(strict_types=1);

/**
 * Kryptographie und Protokoll-Werkzeuge für das Midea-LAN-Protokoll.
 * Portiert aus midea-beautiful-air (Python) von nbogojevic.
 */
class MideaCrypto
{
    // Nachrichtentypen für das 8370-Protokoll (V3)
    const MSGTYPE_HANDSHAKE_REQUEST  = 0x0;
    const MSGTYPE_ENCRYPTED_RESPONSE = 0x3;
    const MSGTYPE_ENCRYPTED_REQUEST  = 0x6;

    const HDR_8370 = "\x83\x70";
    const HDR_ZZ   = "\x5A\x5A";

    // CRC8-Lookup-Tabelle (Polynom 0x854)
    private const CRC8_TABLE = [
        0x00, 0x5E, 0xBC, 0xE2, 0x61, 0x3F, 0xDD, 0x83,
        0xC2, 0x9C, 0x7E, 0x20, 0xA3, 0xFD, 0x1F, 0x41,
        0x9D, 0xC3, 0x21, 0x7F, 0xFC, 0xA2, 0x40, 0x1E,
        0x5F, 0x01, 0xE3, 0xBD, 0x3E, 0x60, 0x82, 0xDC,
        0x23, 0x7D, 0x9F, 0xC1, 0x42, 0x1C, 0xFE, 0xA0,
        0xE1, 0xBF, 0x5D, 0x03, 0x80, 0xDE, 0x3C, 0x62,
        0xBE, 0xE0, 0x02, 0x5C, 0xDF, 0x81, 0x63, 0x3D,
        0x7C, 0x22, 0xC0, 0x9E, 0x1D, 0x43, 0xA1, 0xFF,
        0x46, 0x18, 0xFA, 0xA4, 0x27, 0x79, 0x9B, 0xC5,
        0x84, 0xDA, 0x38, 0x66, 0xE5, 0xBB, 0x59, 0x07,
        0xDB, 0x85, 0x67, 0x39, 0xBA, 0xE4, 0x06, 0x58,
        0x19, 0x47, 0xA5, 0xFB, 0x78, 0x26, 0xC4, 0x9A,
        0x65, 0x3B, 0xD9, 0x87, 0x04, 0x5A, 0xB8, 0xE6,
        0xA7, 0xF9, 0x1B, 0x45, 0xC6, 0x98, 0x7A, 0x24,
        0xF8, 0xA6, 0x44, 0x1A, 0x99, 0xC7, 0x25, 0x7B,
        0x3A, 0x64, 0x86, 0xD8, 0x5B, 0x05, 0xE7, 0xB9,
        0x8C, 0xD2, 0x30, 0x6E, 0xED, 0xB3, 0x51, 0x0F,
        0x4E, 0x10, 0xF2, 0xAC, 0x2F, 0x71, 0x93, 0xCD,
        0x11, 0x4F, 0xAD, 0xF3, 0x70, 0x2E, 0xCC, 0x92,
        0xD3, 0x8D, 0x6F, 0x31, 0xB2, 0xEC, 0x0E, 0x50,
        0xAF, 0xF1, 0x13, 0x4D, 0xCE, 0x90, 0x72, 0x2C,
        0x6D, 0x33, 0xD1, 0x8F, 0x0C, 0x52, 0xB0, 0xEE,
        0x32, 0x6C, 0x8E, 0xD0, 0x53, 0x0D, 0xEF, 0xB1,
        0xF0, 0xAE, 0x4C, 0x12, 0x91, 0xCF, 0x2D, 0x73,
        0xCA, 0x94, 0x76, 0x28, 0xAB, 0xF5, 0x17, 0x49,
        0x08, 0x56, 0xB4, 0xEA, 0x69, 0x37, 0xD5, 0x8B,
        0x57, 0x09, 0xEB, 0xB5, 0x36, 0x68, 0x8A, 0xD4,
        0x95, 0xCB, 0x29, 0x77, 0xF4, 0xAA, 0x48, 0x16,
        0xE9, 0xB7, 0x55, 0x0B, 0x88, 0xD6, 0x34, 0x6A,
        0x2B, 0x75, 0x97, 0xC9, 0x4A, 0x14, 0xF6, 0xA8,
        0x74, 0x2A, 0xC8, 0x96, 0x15, 0x4B, 0xA9, 0xF7,
        0xB6, 0xE8, 0x0A, 0x54, 0xD7, 0x89, 0x6B, 0x35,
    ];

    private string $appkey;
    private string $signkey;
    private string $encKey;   // MD5(signkey), 16 Bytes → AES-128-Schlüssel
    private string $iv;       // 16 Null-Bytes als CBC-IV
    private string $tcpKey = '';
    private int    $requestCount  = 0;
    private int    $responseCount = 0;

    public function __construct(
        string $appkey  = '3742e9e5842d4ad59c2db887e12449f9',
        string $signkey = 'xhdiwjnchekd4d512chdjx5d8e4c394D2D7S'
    ) {
        $this->appkey  = $appkey;
        $this->signkey = $signkey;
        $this->encKey  = md5($signkey, true);      // 16 Bytes Binär
        $this->iv      = str_repeat("\x00", 16);
    }

    // ── CRC8 ──────────────────────────────────────────────────────────────

    public static function crc8(string $data): int
    {
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc = self::CRC8_TABLE[$crc ^ ord($data[$i])];
        }
        return $crc;
    }

    // ── AES-128-ECB (mit PKCS7, für LAN-Pakete) ───────────────────────────

    public function aesEncrypt(string $data): string
    {
        $result = openssl_encrypt($data, 'aes-128-ecb', $this->encKey, OPENSSL_RAW_DATA);
        if ($result === false) {
            throw new RuntimeException('AES-ECB Verschlüsselung fehlgeschlagen: ' . openssl_error_string());
        }
        return $result;
    }

    public function aesDecrypt(string $data): string
    {
        $result = openssl_decrypt($data, 'aes-128-ecb', $this->encKey, OPENSSL_RAW_DATA);
        if ($result === false) {
            throw new RuntimeException('AES-ECB Entschlüsselung fehlgeschlagen: ' . openssl_error_string());
        }
        return $result;
    }

    // ── AES-CBC (ohne Padding, für 8370-Protokoll) ────────────────────────

    private function getCbcCipher(string $key): string
    {
        switch (strlen($key)) {
            case 16: return 'aes-128-cbc';
            case 24: return 'aes-192-cbc';
            case 32: return 'aes-256-cbc';
            default: throw new RuntimeException('Ungültige AES-Schlüssellänge: ' . strlen($key));
        }
    }

    public function aesCbcEncrypt(string $data, string $key): string
    {
        $cipher = $this->getCbcCipher($key);
        $result = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
        if ($result === false) {
            throw new RuntimeException('AES-CBC Verschlüsselung fehlgeschlagen: ' . openssl_error_string());
        }
        return $result;
    }

    public function aesCbcDecrypt(string $data, string $key): string
    {
        $cipher = $this->getCbcCipher($key);
        $result = openssl_decrypt($data, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
        if ($result === false) {
            throw new RuntimeException('AES-CBC Entschlüsselung fehlgeschlagen: ' . openssl_error_string());
        }
        return $result;
    }

    // ── Hilfsfunktionen ───────────────────────────────────────────────────

    public function md5fingerprint(string $data): string
    {
        return md5($data . $this->signkey, true);
    }

    private function strxor(string $a, string $b): string
    {
        $len    = strlen($a);
        $lenB   = strlen($b);
        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $result .= chr(ord($a[$i]) ^ ord($b[$i % $lenB]));
        }
        return $result;
    }

    // ── TCP-Schlüssel (V3-Handshake) ──────────────────────────────────────

    public function deriveTcpKey(string $response, string $deviceKey): string
    {
        if ($response === 'ERROR') {
            throw new RuntimeException('Authentifizierung fehlgeschlagen: ERROR-Paket erhalten');
        }
        if (strlen($response) !== 64) {
            throw new RuntimeException('Paketlänge falsch: ' . strlen($response) . ' statt 64 Bytes');
        }
        $payload = substr($response, 0, 32);
        $sign    = substr($response, 32, 32);
        $plain   = $this->aesCbcDecrypt($payload, $deviceKey);
        if (hash('sha256', $plain, true) !== $sign) {
            throw new RuntimeException('Paketsignatur stimmt nicht überein');
        }
        $this->tcpKey         = $this->strxor($plain, $deviceKey);
        $this->requestCount   = 0;
        $this->responseCount  = 0;
        return $this->tcpKey;
    }

    // ── 8370-Protokoll-Kodierung (V3) ─────────────────────────────────────

    public function encode8370(string $data, int $msgtype): string
    {
        $header = self::HDR_8370;
        $size   = strlen($data);
        $pad    = 0;

        $isEncrypted = in_array($msgtype, [self::MSGTYPE_ENCRYPTED_RESPONSE, self::MSGTYPE_ENCRYPTED_REQUEST], true);

        if ($isEncrypted) {
            if (($size + 2) % 16 !== 0) {
                $pad   = 16 - (($size + 2) & 0xF);
                $size += $pad + 32;
                $data .= random_bytes($pad);
            }
        }

        // 2 Bytes Größe Big-Endian
        $header .= chr(($size >> 8) & 0xFF) . chr($size & 0xFF);
        $header .= chr(0x20) . chr(($pad << 4) | $msgtype);

        if ($this->requestCount >= 0xFFF) {
            $this->requestCount = 0;
        }
        $data = chr(($this->requestCount >> 8) & 0xFF) . chr($this->requestCount & 0xFF) . $data;
        $this->requestCount++;

        if ($isEncrypted) {
            if ($this->tcpKey === '') {
                throw new RuntimeException('TCP-Schlüssel fehlt für V3-Kommunikation');
            }
            $sign = hash('sha256', $header . $data, true);
            $data = $this->aesCbcEncrypt($data, $this->tcpKey) . $sign;
        }

        return $header . $data;
    }

    public function decode8370(string $buf): array
    {
        if (strlen($buf) < 6) {
            return [[], $buf];
        }
        $header = substr($buf, 0, 6);
        if ($header[0] !== "\x83" || $header[1] !== "\x70") {
            throw new RuntimeException('Kein gültiges V3 (8370) Paket');
        }

        $size = ((ord($header[2]) << 8) | ord($header[3])) + 8;

        if (strlen($buf) < $size) {
            return [[], $buf];   // Noch nicht genug Daten
        }

        $leftover = null;
        if (strlen($buf) > $size) {
            $leftover = substr($buf, $size);
            $buf      = substr($buf, 0, $size);
        }

        if ($header[4] !== "\x20") {
            throw new RuntimeException('Byte 4 ist nicht 0x20');
        }

        $pad     = (ord($header[5]) >> 4) & 0xF;
        $msgtype = ord($header[5]) & 0xF;
        $data    = substr($buf, 6);

        $isEncrypted = in_array($msgtype, [self::MSGTYPE_ENCRYPTED_RESPONSE, self::MSGTYPE_ENCRYPTED_REQUEST], true);

        if ($isEncrypted) {
            $sign = substr($data, -32);
            $data = substr($data, 0, -32);
            $data = $this->aesCbcDecrypt($data, $this->tcpKey);
            if (hash('sha256', $header . $data, true) !== $sign) {
                throw new RuntimeException('Signatur stimmt nicht mit Nutzlast überein');
            }
            if ($pad > 0) {
                $data = substr($data, 0, -$pad);
            }
        }

        $this->responseCount = (ord($data[0]) << 8) | ord($data[1]);
        $data                = substr($data, 2);

        if ($leftover !== null) {
            [$packets, $incomplete] = $this->decode8370($leftover);
            return [array_merge([$data], $packets), $incomplete];
        }
        return [[$data], ''];
    }

    // ── Cloud-API-Kryptographie ────────────────────────────────────────────

    public function encryptPassword(string $loginId, string $password): string
    {
        $sha1      = hash('sha256', $password);
        $loginHash = $loginId . $sha1 . $this->appkey;
        return hash('sha256', $loginHash);
    }

    public function sign(string $url, array $payload): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        ksort($payload);
        // Exakt wie Python: url_encode -> url_decode
        $queryStr = urldecode(http_build_query($payload));
        return hash('sha256', $path . $queryStr . $this->appkey);
    }

    /**
     * Leitet den Datenschlüssel durch Dekryption des AccessToken ab.
     * Wie Python: midea-beautiful-air/crypto.py
     *
     * @param string $accessTokenHex Der accessToken als Hex-String
     * @return string Der dekryptierte DataKey als STRING (nicht binary!)
     */
    public function deriveDataKey(string $accessTokenHex): string
    {
        // Schritt 1: Berechne md5appkey = erste 16 chars von md5(appkey)
        $md5Full = md5($this->appkey);
        $md5appkey = substr($md5Full, 0, 16);  // z.B. "ff0cf6f5f0c347"

        // Schritt 2: Dekryptiere accessToken mit md5appkey als Schlüssel
        // Der Schlüssel wird als UTF-8 STRING behandelt (nicht binary!)
        $accessTokenBin = hex2bin($accessTokenHex);

        // AES-128-ECB mit UTF-8 String als Key (wie Python: key.encode("utf-8"))
        // Der Key ist ein String, den PHP als Bytes interpretiert
        $decrypted = openssl_decrypt($accessTokenBin, 'aes-128-ecb', $md5appkey, OPENSSL_RAW_DATA);

        if ($decrypted === false) {
            throw new RuntimeException('AccessToken-Dekryption fehlgeschlagen mit md5appkey');
        }

        // Entferne PKCS7 Padding
        $dataKey = $this->unpadPKCS7($decrypted);

        return $dataKey;  // Gib als STRING zurück (kein binary!)
    }

    /** Entfernt PKCS7 Padding von dekryptierten Daten. */
    private function unpadPKCS7(string $data): string
    {
        $len = strlen($data);
        $pad = ord($data[$len - 1]);
        if ($pad > 0 && $pad <= 16) {
            return substr($data, 0, $len - $pad);
        }
        return $data;
    }

    /** Entschlüsselt einen hexkodierten String mit dem Datenschlüssel (ECB). */
    public function decryptDataString(string $hexData, string $dataKey): string
    {
        $bin    = hex2bin($hexData);
        $result = openssl_decrypt($bin, 'aes-128-ecb', $dataKey, OPENSSL_RAW_DATA);
        if ($result === false) {
            return '';
        }
        return $result;
    }

}
