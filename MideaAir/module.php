<?php
declare(strict_types=1);

require_once __DIR__ . '/libs/MideaCrypto.php';
require_once __DIR__ . '/libs/MideaCommands.php';
require_once __DIR__ . '/libs/MideaCloud.php';

/**
 * IP-Symcon Modul für Midea Klimaanlagen und Entfeuchter.
 *
 * Unterstützt lokale LAN-Kommunikation (V2 ohne Token / V3 mit Token+Key)
 * sowie Cloud-Token-Ermittlung über den Midea-Account.
 *
 * VERSION: 2026-05-26 17:00 UTC (GMP Integer Fix)
 */
class MideaAir extends IPSModule
{
    // Gerätetypen
    const DEVICE_DEHUMIDIFIER = 'dehumidifier';
    const DEVICE_AIRCON       = 'aircon';

    // Netzwerk
    const TCP_PORT       = 6444;
    const SOCKET_TIMEOUT = 5;

    // Dehumidifier: 3 levels only
    const DEHUMIDIFIER_FAN_SPEED_OPTIONS = [
        ['Value' => 40,  'Caption' => 'Silent', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ['Value' => 80,  'Caption' => 'Medium', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ['Value' => 127, 'Caption' => 'High',   'IconActive' => false, 'IconValue' => '', 'Color' => -1],
    ];

    const SWING_OPTIONS = [
        ['Value' => 0, 'Caption' => 'Off',     'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ['Value' => 1, 'Caption' => 'Level 1', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ['Value' => 2, 'Caption' => 'Level 2', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ['Value' => 3, 'Caption' => 'Level 3', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
    ];

    // ── Modul-Lifecycle ───────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        // Gerät
        $this->RegisterPropertyString('DeviceType', self::DEVICE_DEHUMIDIFIER);

        // Netzwerk
        $this->RegisterPropertyString('IPAddress', '');
        $this->RegisterPropertyInteger('Port', self::TCP_PORT);

        // Authentifizierung (V3)
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('Key', '');

        // Geräteinformationen
        $this->RegisterPropertyString('ApplianceID', '');
        $this->RegisterPropertyString('SerialNumber', '');

        // Capabilities (vom Gerät ermittelt, JSON)
        $this->RegisterAttributeString('Capabilities', '{}');

        // Verfügbare Modi (Klimaanlage) – standardmäßig alle aktiv
        $this->RegisterPropertyBoolean('ModeAuto',  true);
        $this->RegisterPropertyBoolean('ModeCool',  true);
        $this->RegisterPropertyBoolean('ModeDry',   true);
        $this->RegisterPropertyBoolean('ModeHeat',  true);
        $this->RegisterPropertyBoolean('ModeFan',   true);

        // Manuelle Konfiguration (nicht per B5 erkennbar)
        $this->RegisterPropertyBoolean('HasVerticalSwing',   false);
        $this->RegisterPropertyBoolean('HasHorizontalSwing', false);
        $this->RegisterPropertyBoolean('FanSpeedSilent',     false);  // Leise (20): selten, manuell aktivieren
        $this->RegisterPropertyBoolean('FanSpeedMedium',     true);   // Mittel (60): meist vorhanden
        $this->RegisterPropertyBoolean('HasTurbo',           false);
        $this->RegisterPropertyBoolean('HasEcoMode',         false);
        $this->RegisterPropertyBoolean('HasPurifier',        false);
        $this->RegisterPropertyBoolean('HasDryer',           false);
        $this->RegisterPropertyBoolean('HasFrostProtect',    false);
        $this->RegisterPropertyBoolean('HasShowScreen',      false);
        $this->RegisterPropertyBoolean('HasIndoorHumidity',  false);
        $this->RegisterPropertyBoolean('HasCurrentPower',    false);

        // Polling
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Cloud-Login für Token-Ermittlung
        $this->RegisterPropertyString('CloudApp', 'NetHome Plus');
        $this->RegisterPropertyString('CloudAccount', '');
        $this->RegisterPropertyString('CloudPassword', '');

        // Update-Timer
        $this->RegisterTimer('UpdateTimer', 0, 'MA_Update($_IPS["TARGET"]);');
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $ip = $this->ReadPropertyString('IPAddress');
        if (empty($ip)) {
            $this->SetStatus(104);  // Konfiguration unvollständig
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $deviceType = $this->ReadPropertyString('DeviceType');
        if ($deviceType === self::DEVICE_DEHUMIDIFIER) {
            $this->RegisterDehumidifierVariables();
        } else {
            $this->RegisterAirConditionerVariables();
        }

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);

        $this->SetStatus(102);  // Aktiv
        $this->Update();
    }

    // ── Öffentliche Methoden (Timer + Buttons) ────────────────────────────

    public function Update(): void
    {
        $this->SendDebug("Update", "START", 0);

        $deviceType = $this->ReadPropertyString('DeviceType');
        $appId      = $this->ReadPropertyString('ApplianceID');
        $ip         = $this->ReadPropertyString('IPAddress');
        $port       = $this->ReadPropertyInteger('Port');
        $token      = $this->ReadPropertyString('Token');
        $key        = $this->ReadPropertyString('Key');

        $proto = (!empty($token) && !empty($key)) ? 'V3' : 'V2';
        $this->SendDebug("Update", "Gerät: $appId | Typ: $deviceType | IP: $ip:$port | Protokoll: $proto", 0);

        try {
            if ($deviceType === self::DEVICE_DEHUMIDIFIER) {
                $this->SendDebug("Update", "→ Sende Entfeuchter Statusanfrage", 0);
                try {
                    $cmd = MideaCommands::dehumidifierStatusCommand();
                    $this->SendDebug("Update", "Befehl generiert: " . strlen($cmd) . " Bytes", 0);
                } catch (Exception $e) {
                    $this->LogMessage("Befehl-Fehler: " . $e->getMessage(), KL_ERROR);
                    return;
                }

                $payload = $this->sendCommand($cmd);
                $this->SendDebug("Update", "sendCommand() result: " . ($payload === null ? "null" : strlen($payload) . " Bytes"), 0);

                if ($payload !== null) {
                    $state = new DehumidifierResponse($payload);
                    $this->applyDehumidifierState($state);
                    $this->SendDebug("Update", "✓ Entfeuchter Status aktualisiert", 0);
                } else {
                    $this->SendDebug("Update", "✗ Keine Antwort vom Gerät", 0);
                }
            } else {
                $this->SendDebug("Update", "→ Sende AC Statusanfrage", 0);
                try {
                    $cmd = MideaCommands::airConditionerStatusCommand();
                    $this->SendDebug("Update", "Befehl generiert: " . strlen($cmd) . " Bytes", 0);
                } catch (Exception $e) {
                    $this->LogMessage("Befehl-Fehler: " . $e->getMessage(), KL_ERROR);
                    return;
                }

                $payload = $this->sendCommand($cmd);
                $this->SendDebug("Update", "sendCommand() result: " . ($payload === null ? "null" : strlen($payload) . " Bytes"), 0);

                if ($payload !== null) {
                    $state = new AirConditionerResponse($payload);
                    $this->applyAirConditionerState($state);
                    $this->SendDebug("Update", "✓ AC Status aktualisiert", 0);
                } else {
                    $this->SendDebug("Update", "✗ Keine Antwort vom Gerät", 0);
                }

                // Stromverbrauch abfragen wenn Variable vorhanden
                if (@IPS_GetObjectIDByIdent('CurrentPower', $this->InstanceID) > 0) {
                    $elCmd     = MideaCommands::airConditionerElectricityCommand();
                    $elPayload = $this->sendCommand($elCmd);
                    if ($elPayload !== null) {
                        $this->SendDebug("Update", "Electricity raw: " . bin2hex($elPayload), 0);
                        $el = new AirConditionerElectricityResponse($elPayload);
                        $this->SetValue('CurrentPower', $el->power);
                        $this->SendDebug("Update", "✓ Leistung: " . $el->power . " W", 0);
                    }
                }
            }

            $this->SendDebug("Update", "FERTIG", 0);

        } catch (Exception $e) {
            $this->LogMessage('Update-Fehler: ' . $e->getMessage(), KL_ERROR);
            $this->LogMessage('Stack: ' . $e->getTraceAsString(), KL_ERROR);
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        $deviceType = $this->ReadPropertyString('DeviceType');
        try {
            if ($deviceType === self::DEVICE_DEHUMIDIFIER) {
                $this->handleDehumidifierAction($Ident, $Value);
            } else {
                $this->handleAirConditionerAction($Ident, $Value);
            }
        } catch (Exception $e) {
            $this->LogMessage('RequestAction-Fehler: ' . $e->getMessage(), KL_ERROR);
        }
    }

    /**
     * Ermittelt Token und Key über den Cloud-Login und schreibt sie in die Konfiguration.
     * Wird über Button in form.json aufgerufen.
     */
    public function DiscoverToken(): string
    {
        $account  = $this->ReadPropertyString('CloudAccount');
        $password = $this->ReadPropertyString('CloudPassword');
        $appName  = $this->ReadPropertyString('CloudApp');
        $applianceId = $this->ReadPropertyString('ApplianceID');

        if (empty($account) || empty($password)) {
            return 'Fehler: Cloud-Account und Passwort erforderlich';
        }

        if (empty($applianceId)) {
            return 'Fehler: ApplianceID erforderlich';
        }

        try {
            $this->SendDebug("Token", "=== START ===", 0);

            // Cloud-Client
            $cloud = new MideaCloud($account, $password, $appName);
            $cloud->setLogger(function($msg, $level) {
                $this->SendDebug("Cloud", $msg, 0);
            });

            // Authentifiziere
            $this->SendDebug("Token", "Authentifiziere mit $appName...", 0);
            $cloud->authenticate();
            $this->SendDebug("Token", "✓ Authentifizierung erfolgreich", 0);

            // Versuche beide UDP-IDs
            $appId = (int)$applianceId;

            $this->SendDebug("Token", "Berechne UDP-IDs für ApplianceID: $applianceId", 0);

            // LE
            $leBytes = '';
            for ($i = 0; $i < 6; $i++) {
                $leBytes .= chr(($appId >> ($i * 8)) & 0xFF);
            }
            $leDigest = hash('sha256', $leBytes, true);
            $leFirst = substr($leDigest, 0, 16);
            $leSecond = substr($leDigest, 16, 16);
            $leResult = '';
            for ($i = 0; $i < 16; $i++) {
                $leResult .= chr(ord($leFirst[$i]) ^ ord($leSecond[$i]));
            }
            $leUdpId = bin2hex($leResult);
            $this->SendDebug("Token", "LE UDP-ID: $leUdpId", 0);

            // BE
            $beBytes = '';
            for ($i = 0; $i < 6; $i++) {
                $beBytes .= chr(($appId >> ((6 - 1 - $i) * 8)) & 0xFF);
            }
            $beDigest = hash('sha256', $beBytes, true);
            $beFirst = substr($beDigest, 0, 16);
            $beSecond = substr($beDigest, 16, 16);
            $beResult = '';
            for ($i = 0; $i < 16; $i++) {
                $beResult .= chr(ord($beFirst[$i]) ^ ord($beSecond[$i]));
            }
            $beUdpId = bin2hex($beResult);
            $this->SendDebug("Token", "BE UDP-ID: $beUdpId", 0);

            // Wie Python: Token holen UND sofort mit Gerät testen!
            $this->SendDebug("Token", "Versuche Token zu ermitteln (und direkt testen)...", 0);

            $ip   = $this->ReadPropertyString('IPAddress');
            $port = $this->ReadPropertyInteger('Port');

            foreach (['LE' => $leUdpId, 'BE' => $beUdpId] as $name => $udpId) {
                $this->SendDebug("Token", "[$name] Versuche UDP-ID: $udpId", 0);

                try {
                    [$token, $key] = $cloud->getToken($udpId);

                    if (empty($token) || empty($key)) {
                        $this->SendDebug("Token", "[$name] Kein Token von Cloud", 0);
                        continue;
                    }

                    $this->SendDebug("Token", "[$name] Token erhalten, teste Verbindung...", 0);

                    // DIREKT TESTEN: Genau wie Python _authenticate()
                    if ($this->testTokenConnection($ip, $port, $token, $key)) {
                        $this->SendDebug("Token", "[$name] ✓ TOKEN FUNKTIONIERT!", 0);

                        IPS_SetProperty($this->InstanceID, 'Token', $token);
                        IPS_SetProperty($this->InstanceID, 'Key', $key);
                        IPS_ApplyChanges($this->InstanceID);

                        $this->SendDebug("Token", "=== ERFOLG ===", 0);

                        // Capabilities direkt im Anschluss automatisch ermitteln
                        $this->SendDebug("Token", "Capabilities automatisch ermitteln...", 0);
                        $capsResult = $this->DetectCapabilities();

                        return "✓ Token ($name) ermittelt, getestet und gespeichert!\n\n" . $capsResult;
                    } else {
                        $this->SendDebug("Token", "[$name] ✗ Token funktioniert nicht, versuche nächste...", 0);
                    }
                } catch (Exception $e) {
                    $this->SendDebug("Token", "[$name] ✗ Fehler: " . $e->getMessage(), 0);
                }
            }

            $this->LogMessage("Token-Ermittlung: Kein Token funktioniert mit diesem Gerät", KL_ERROR);
            return "✗ Kein Token funktioniert mit diesem Gerät";

        } catch (Exception $e) {
            $this->LogMessage("Token-Ermittlung fehlgeschlagen: " . $e->getMessage(), KL_ERROR);
            return "✗ Fehler: " . $e->getMessage();
        }
    }

    /**
     * Fragt das Gerät nach seinen Capabilities (B5-Befehl).
     * Speichert die Capabilities und registriert die Variablen neu.
     */
    public function DetectCapabilities(): string
    {
        $ip   = $this->ReadPropertyString('IPAddress');
        $port = $this->ReadPropertyInteger('Port');

        if (empty($ip)) {
            return 'Fehler: IP-Adresse nicht konfiguriert';
        }

        $deviceType = $this->ReadPropertyString('DeviceType');
        $appType    = $deviceType === self::DEVICE_AIRCON ? 0xAC : 0xA1;

        try {
            // Nur den rohen Befehl erzeugen – sendCommand() übernimmt Paketierung und Verschlüsselung
            $cmd = MideaCommands::deviceCapabilitiesCommand($appType);
            $raw = $this->sendCommand($cmd);

            if ($raw === null) {
                return 'Fehler: Keine Antwort vom Gerät';
            }

            $this->SendDebug("B5", "Antwort (" . strlen($raw) . " Bytes): " . bin2hex($raw), 0);

            // B5-Antwort parsen
            $caps = $this->parseB5Response($raw, $deviceType);

            // Speichern
            $this->WriteAttributeString('Capabilities', json_encode($caps));
            $this->SendDebug("B5", "Capabilities erkannt: " . implode(', ', array_keys($caps)), 0);

            // Manuelle Properties aus B5-Ergebnis vorbelegen –
            // damit steuern die Checkboxen die Variablen, und der Nutzer kann sie anpassen
            if ($deviceType === self::DEVICE_AIRCON) {
                $this->applyB5ToProperties($caps);
            }

            // Variablen neu registrieren
            IPS_ApplyChanges($this->InstanceID);

            $lines = ["✅ Capabilities vom Gerät erkannt:\n"];
            foreach ($caps as $key => $value) {
                if (is_int($value)) {
                $lines[] = sprintf("  %-30s = %d", $key, $value);
            } else {
                $lines[] = sprintf("  %-30s = %s", $key, $value ? 'JA' : 'NEIN');
            }
            }
            return implode("\n", $lines);

        } catch (Exception $e) {
            return 'Fehler: ' . $e->getMessage();
        }
    }

    /**
     * Schreibt B5-Ergebnisse in die manuellen Boolean-Properties.
     * Läuft innerhalb von DetectCapabilities() vor IPS_ApplyChanges().
     * Danach steuern ausschließlich die Checkboxen die Variablenregistrierung.
     */
    private function applyB5ToProperties(array $caps): void
    {
        // Direktes Mapping Cap-Key → Property-Name
        $map = [
            'HasEcoMode'        => 'eco',
            'HasTurbo'          => 'strong_fan',
            'HasPurifier'       => 'anion',
            'HasDryer'          => 'no_fan_sense',
            'HasFrostProtect'   => 'heat_8',
            'HasShowScreen'     => 'screen_display',
            'HasIndoorHumidity' => 'has_indoor_humidity',
            'HasCurrentPower'   => 'electricity',
        ];
        foreach ($map as $property => $capKey) {
            IPS_SetProperty($this->InstanceID, $property, (bool)($caps[$capKey] ?? false));
        }

        // Vertikaler Swing: fan_swing ODER has_vertical_fan
        IPS_SetProperty($this->InstanceID, 'HasVerticalSwing',
            (bool)(($caps['fan_swing'] ?? false) || ($caps['has_vertical_fan'] ?? false)));

        // Horizontaler Swing
        IPS_SetProperty($this->InstanceID, 'HasHorizontalSwing',
            (bool)($caps['has_horizontal_fan'] ?? false));

        // FanSpeedSilent / FanSpeedMedium werden NICHT aus B5 gesetzt –
        // B5 liefert dafür keine zuverlässigen Werte, daher rein manuelle Steuerung.
    }

    /** Parst die B5-Capabilities-Antwort des Geräts. */
    private function parseB5Response(string $raw, string $deviceType): array
    {
        // B5-Capabilities-Mapping (wie Python B5_CAPABILITIES)
        $acCaps = [
            "\x10\x02" => 'fan_speed',
            "\x12\x02" => 'eco',
            "\x13\x02" => 'heat_8',
            "\x14\x02" => 'mode',
            "\x15\x02" => 'fan_swing',
            "\x16\x02" => 'electricity',
            "\x17\x02" => 'filter_reminder',
            "\x18\x02" => 'no_fan_sense',
            "\x19\x02" => 'ptc',
            "\x1a\x02" => 'strong_fan',
            "\x1e\x02" => 'anion',
            "\x1f\x02" => 'humidity',
            "\x22\x02" => 'fahrenheit',
            "\x24\x02" => 'screen_display',
            "\x2c\x02" => 'buzzer',
            "\x32\x02" => 'fan_straight',
            "\x33\x02" => 'fan_avoid',
            "\x39\x02" => 'self_clean',
            "\x09\x00" => 'has_vertical_fan',
            "\x0a\x00" => 'has_horizontal_fan',
            "\x15\x00" => 'has_indoor_humidity',
        ];

        $dehumCaps = [
            "\x1f\x02" => 'humidity',
            "\x14\x02" => 'mode',
            "\x1e\x02" => 'anion',
            "\x10\x02" => 'fan_speed',
            "\x22\x02" => 'fahrenheit',
            "\x15\x02" => 'fan_swing',
        ];

        $mapping = $deviceType === self::DEVICE_AIRCON ? $acCaps : $dehumCaps;

        // Finde 0xB5 in der Antwort
        $b5pos = strpos($raw, "\xB5");
        if ($b5pos === false) {
            throw new RuntimeException('Kein B5-Response gefunden');
        }

        $data = substr($raw, $b5pos);
        if (strlen($data) < 3) {
            throw new RuntimeException('B5-Response zu kurz');
        }

        $count = ord($data[1]);
        $caps  = [];
        $i = 2;

        for ($n = 0; $n < $count && $i + 3 <= strlen($data) - 1; $n++) {
            $key = substr($data, $i, 2);
            $val = ord($data[$i + 3]);
            if (isset($mapping[$key])) {
                $name = $mapping[$key];
                $caps[$name] = $val > 0;
                // Rohwert für fan_speed separat speichern (zur Stufenerkennung)
                if ($name === 'fan_speed') {
                    $caps['fan_speed_value'] = $val;
                    $this->SendDebug("B5", "fan_speed Rohwert: $val", 0);
                }
            }
            $i += 4;
        }

        return $caps;
    }

    /**
     * Ermittelt die ApplianceID über die Cloud-Geräteliste.
     * Falls nur ein Gerät vorhanden → automatisch setzen.
     * Falls mehrere → Auswahl anzeigen.
     */
    public function DiscoverApplianceId(): string
    {
        $account  = $this->ReadPropertyString('CloudAccount');
        $password = $this->ReadPropertyString('CloudPassword');
        $appName  = $this->ReadPropertyString('CloudApp');

        if (empty($account) || empty($password)) {
            return 'Fehler: Cloud-Account und Passwort erforderlich';
        }

        try {
            $cloud = new MideaCloud($account, $password, $appName);
            $cloud->setLogger(function($msg, $level) {
                $this->SendDebug("Cloud", $msg, 0);
            });

            $this->SendDebug("ApplianceID", "Authentifiziere für Geräteliste...", 0);
            $cloud->authenticate();

            $appliances = $cloud->listAppliances();

            if (empty($appliances)) {
                return "Keine Geräte im Cloud-Account gefunden.";
            }

            // Filtern: nur Klimaanlagen (0xAC) und Entfeuchter (0xA1)
            $relevant = array_filter($appliances, function($a) {
                $type = strtolower($a['type']);
                return in_array($type, ['0xac', '0xa1', 'ac', 'a1', '172', '161']);
            });

            // Falls nur ein relevantes Gerät → automatisch setzen
            if (count($relevant) === 1) {
                $device = array_values($relevant)[0];
                IPS_SetProperty($this->InstanceID, 'ApplianceID', $device['id']);
                IPS_ApplyChanges($this->InstanceID);
                $this->SendDebug("ApplianceID", "✓ automatisch gesetzt: {$device['id']}", 0);
                return "✓ ApplianceID automatisch ermittelt:\n" .
                       "  ID:   {$device['id']}\n" .
                       "  Name: {$device['name']}\n" .
                       "  Typ:  {$device['type']}\n" .
                       "  SN:   {$device['sn']}\n\n" .
                       "→ Jetzt 'Token ermitteln' klicken!";
            }

            // Mehrere Geräte → alle anzeigen
            $lines = ["Gefundene Geräte (ApplianceID manuell eintragen):\n"];
            foreach ($appliances as $dev) {
                $lines[] = sprintf(
                    "  ID: %-20s  Typ: %-6s  Name: %s",
                    $dev['id'], $dev['type'], $dev['name']
                );
                if (!empty($dev['sn'])) {
                    $lines[] = sprintf("      SN: %s", $dev['sn']);
                }
            }
            $lines[] = "\n→ ApplianceID oben im Feld 'Appliance ID' eintragen, dann 'Token ermitteln' klicken.";

            return implode("\n", $lines);

        } catch (Exception $e) {
            $this->LogMessage("ApplianceID-Ermittlung fehlgeschlagen: " . $e->getMessage(), KL_ERROR);
            return "✗ Fehler: " . $e->getMessage();
        }
    }

    // ── Variablen-Registrierung ───────────────────────────────────────────

    private function RegisterDehumidifierVariables(): void
    {
        $this->RegisterVariableBoolean('Running', $this->Translate('Running'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
        ], 10);

        $this->RegisterVariableInteger('Mode', $this->Translate('Mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($this->translateOptions([
                ['Value' => 1, 'Caption' => 'Auto',   'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 2, 'Caption' => 'Normal', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 3, 'Caption' => 'Fast',   'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 4, 'Caption' => 'Sleep',  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 6, 'Caption' => 'Dry',    'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 7, 'Caption' => 'Clean',  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ])),
        ], 20);

        $this->RegisterVariableInteger('FanSpeed', $this->Translate('Fan Speed'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($this->translateOptions(self::DEHUMIDIFIER_FAN_SPEED_OPTIONS)),
        ], 30);

        $this->RegisterVariableInteger('TargetHumidity', $this->Translate('Target Humidity'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 30,
            'MAX'          => 80,
            'STEP_SIZE'    => 5,
            'SUFFIX'       => ' %',
        ], 40);

        $this->RegisterVariableFloat('CurrentHumidity', $this->Translate('Current Humidity'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' %',
            'DIGITS'       => 1,
        ], 50);

        $this->RegisterVariableFloat('IndoorTemperature', $this->Translate('Room Temperature'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1,
        ], 60);

        $this->RegisterVariableBoolean('IonMode', $this->Translate('Ionization'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
        ], 70);

        $this->RegisterVariableBoolean('Pump', $this->Translate('Pump'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
        ], 80);

        $this->RegisterVariableBoolean('SleepMode', $this->Translate('Sleep Mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
        ], 90);

        $this->RegisterVariableBoolean('VerticalSwing', $this->Translate('Air Vanes'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
        ], 100);

        $this->RegisterVariableBoolean('TankFull', $this->Translate('Tank Full'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 110);

        $this->RegisterVariableInteger('TankLevel', $this->Translate('Tank Level'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' %',
        ], 120);

        $this->RegisterVariableBoolean('FilterIndicator', $this->Translate('Clean Filter'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 130);

        $this->RegisterVariableBoolean('Defrosting', $this->Translate('Defrosting'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 140);

        $this->RegisterVariableInteger('ErrorCode', $this->Translate('Error Code'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 150);

        $this->EnableAction('Running');
        $this->EnableAction('Mode');
        $this->EnableAction('FanSpeed');
        $this->EnableAction('TargetHumidity');
        $this->EnableAction('IonMode');
        $this->EnableAction('Pump');
        $this->EnableAction('SleepMode');
        $this->EnableAction('VerticalSwing');
    }

    private function RegisterAirConditionerVariables(): void
    {
        $caps    = json_decode($this->ReadAttributeString('Capabilities'), true) ?: [];
        $hasCaps = !empty($caps);

        // ── Core variables (always present) ──────────────────────────────
        $this->RegisterVariableBoolean('Running', $this->Translate('Running'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
        ], 10);

        // Build mode options dynamically (only enabled modes)
        $modeOptions = [];
        if ($this->ReadPropertyBoolean('ModeAuto')) $modeOptions[] = ['Value' => 1, 'Caption' => $this->Translate('Auto'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        if ($this->ReadPropertyBoolean('ModeCool')) $modeOptions[] = ['Value' => 2, 'Caption' => $this->Translate('Cool'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        if ($this->ReadPropertyBoolean('ModeDry'))  $modeOptions[] = ['Value' => 3, 'Caption' => $this->Translate('Dry'),  'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        if ($this->ReadPropertyBoolean('ModeHeat')) $modeOptions[] = ['Value' => 4, 'Caption' => $this->Translate('Heat'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        if ($this->ReadPropertyBoolean('ModeFan'))  $modeOptions[] = ['Value' => 5, 'Caption' => $this->Translate('Fan'),  'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        // Fallback: if all deselected, show all
        if (empty($modeOptions)) {
            $modeOptions = [
                ['Value' => 1, 'Caption' => $this->Translate('Auto'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 2, 'Caption' => $this->Translate('Cool'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 3, 'Caption' => $this->Translate('Dry'),  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 4, 'Caption' => $this->Translate('Heat'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 5, 'Caption' => $this->Translate('Fan'),  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ];
        }

        $this->RegisterVariableInteger('Mode', $this->Translate('Mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($modeOptions),
        ], 20);

        $this->RegisterVariableFloat('TargetTemperature', $this->Translate('Target Temperature'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 16.0,
            'MAX'          => 31.0,
            'STEP_SIZE'    => 0.5,
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1,
        ], 30);

        $this->RegisterVariableFloat('IndoorTemperature', $this->Translate('Indoor Temperature'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1,
        ], 50);

        $this->RegisterVariableInteger('ErrorCode', $this->Translate('Error Code'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 999);

        $this->EnableAction('Running');
        $this->EnableAction('Mode');
        $this->EnableAction('TargetTemperature');

        // ── Optional variables: shown for all when no caps yet, otherwise checkbox-driven ──
        $swingOptions = json_encode($this->translateOptions(self::SWING_OPTIONS));

        // Fan speed levels: manual checkbox control only
        $withSilent = $this->ReadPropertyBoolean('FanSpeedSilent');
        $withMedium = $this->ReadPropertyBoolean('FanSpeedMedium');
        $fanOptions = [];
        if ($withSilent)  { $fanOptions[] = ['Value' => 20,  'Caption' => $this->Translate('Silent'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1]; }
                            $fanOptions[] = ['Value' => 40,  'Caption' => $this->Translate('Low'),    'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        if ($withMedium)  { $fanOptions[] = ['Value' => 60,  'Caption' => $this->Translate('Medium'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1]; }
                            $fanOptions[] = ['Value' => 80,  'Caption' => $this->Translate('High'),   'IconActive' => false, 'IconValue' => '', 'Color' => -1];
                            $fanOptions[] = ['Value' => 102, 'Caption' => $this->Translate('Auto'),   'IconActive' => false, 'IconValue' => '', 'Color' => -1];
                            $fanOptions[] = ['Value' => 127, 'Caption' => $this->Translate('Full'),   'IconActive' => false, 'IconValue' => '', 'Color' => -1];

        $this->registerOptionalVariable(
            $this->cap($caps, $hasCaps, 'fan_speed'),
            'FanSpeed',
            fn() => $this->RegisterVariableInteger('FanSpeed', $this->Translate('Fan Speed'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => json_encode($fanOptions),
            ], 40),
            fn() => $this->EnableAction('FanSpeed'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasVerticalSwing'),
            'VerticalSwing',
            fn() => $this->RegisterVariableInteger('VerticalSwing', $this->Translate('Vertical Swing'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => $swingOptions,
            ], 70),
            fn() => $this->EnableAction('VerticalSwing'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasHorizontalSwing'),
            'HorizontalSwing',
            fn() => $this->RegisterVariableInteger('HorizontalSwing', $this->Translate('Horizontal Swing'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => $swingOptions,
            ], 80),
            fn() => $this->EnableAction('HorizontalSwing'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasEcoMode'),
            'EcoMode',
            fn() => $this->RegisterVariableBoolean('EcoMode', $this->Translate('Eco Mode'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            ], 90),
            fn() => $this->EnableAction('EcoMode'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasTurbo'),
            'Turbo',
            fn() => $this->RegisterVariableBoolean('Turbo', $this->Translate('Turbo'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            ], 100),
            fn() => $this->EnableAction('Turbo'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasPurifier'),
            'Purifier',
            fn() => $this->RegisterVariableBoolean('Purifier', $this->Translate('Air Purifier'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            ], 110),
            fn() => $this->EnableAction('Purifier'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasDryer'),
            'Dryer',
            fn() => $this->RegisterVariableBoolean('Dryer', $this->Translate('Dryer'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            ], 120),
            fn() => $this->EnableAction('Dryer'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasFrostProtect'),
            'FrostProtect',
            fn() => $this->RegisterVariableBoolean('FrostProtect', $this->Translate('Frost Protection'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            ], 130),
            fn() => $this->EnableAction('FrostProtect'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasShowScreen'),
            'ShowScreen',
            fn() => $this->RegisterVariableBoolean('ShowScreen', $this->Translate('Display'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            ], 150),
            fn() => $this->EnableAction('ShowScreen'));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasIndoorHumidity'),
            'IndoorHumidity',
            fn() => $this->RegisterVariableFloat('IndoorHumidity', $this->Translate('Indoor Humidity'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' %',
                'DIGITS'       => 1,
            ], 55));

        $this->registerOptionalVariable(
            !$hasCaps || $this->ReadPropertyBoolean('HasCurrentPower'),
            'CurrentPower',
            fn() => $this->RegisterVariableFloat('CurrentPower', $this->Translate('Power'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' W',
                'DIGITS'       => 1,
            ], 160));
    }

    /**
     * Registriert eine Variable nur wenn $supported = true.
     * Löscht sie (sofern vorhanden) wenn $supported = false.
     *
     * @param bool          $supported    Ob die Variable registriert werden soll
     * @param string        $ident        IPS-Variablen-Ident (für Löschung)
     * @param callable      $register     Closure: Registriert die Variable
     * @param callable|null $enableAction Closure: Aktiviert RequestAction
     */
    private function registerOptionalVariable(
        bool $supported,
        string $ident,
        callable $register,
        ?callable $enableAction = null
    ): void {
        if ($supported) {
            $register();
            if ($enableAction) {
                $enableAction();
            }
        } else {
            // Variable löschen wenn vorhanden aber nicht unterstützt
            $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varId !== false && $varId > 0) {
                IPS_DeleteVariable($varId);
                $this->SendDebug("Variables", "Variable '$ident' gelöscht (nicht unterstützt)", 0);
            }
        }
    }

    /** Gibt true zurück wenn Capability aktiv oder noch nicht ermittelt. */
    private function cap(array $caps, bool $hasCaps, string ...$keys): bool
    {
        if (!$hasCaps) {
            return true;
        }
        foreach ($keys as $key) {
            if ($caps[$key] ?? false) {
                return true;
            }
        }
        return false;
    }


    /** Übersetzt die Caption jedes Options-Eintrags via locale.json. */
    private function translateOptions(array $options): array
    {
        return array_map(function (array $opt): array {
            $opt['Caption'] = $this->Translate($opt['Caption']);
            return $opt;
        }, $options);
    }

    // ── Zustand auf IPS-Variablen schreiben ──────────────────────────────

    private function applyDehumidifierState(DehumidifierResponse $s): void
    {
        $this->SetValue('Running',          $s->runStatus);
        $this->SetValue('Mode',             $s->mode);
        $this->SetValue('FanSpeed',         $s->fanSpeed);
        $this->SetValue('TargetHumidity',   (int)round($s->targetHumidity));
        $this->SetValue('CurrentHumidity',  $s->currentHumidity);
        $this->SetValue('IndoorTemperature', $s->indoorTemperature);
        $this->SetValue('IonMode',          $s->ionMode);
        $this->SetValue('Pump',             $s->pumpSwitch);
        $this->SetValue('SleepMode',        $s->sleepSwitch);
        $this->SetValue('VerticalSwing',    $s->verticalSwing);
        $this->SetValue('TankFull',         $s->tankFull);
        $this->SetValue('TankLevel',        $s->tankLevel);
        $this->SetValue('FilterIndicator',  $s->filterIndicator);
        $this->SetValue('Defrosting',       $s->defrosting);
        $this->SetValue('ErrorCode',        $s->errCode);
    }

    private function applyAirConditionerState(AirConditionerResponse $s): void
    {
        // ── Basis-Variablen (immer vorhanden) ────────────────────────────
        $this->SetValue('Running',           $s->runStatus);
        $this->SetValue('Mode',              $s->mode);
        $this->SetValue('TargetTemperature', $s->targetTemperature);
        $this->SetValue('ErrorCode',         $s->errCode);

        if ($s->indoorTemperature !== null) {
            $this->SetValue('IndoorTemperature', $s->indoorTemperature);
        }

        // ── Optionale Variablen (nur setzen wenn Variable existiert) ─────
        $this->setSafeValue('FanSpeed',          $s->fanSpeed);
        $this->setSafeValue('VerticalSwing',      $s->verticalSwing);
        $this->setSafeValue('HorizontalSwing',    $s->horizontalSwing);
        $this->setSafeValue('EcoMode',            $s->eco);
        $this->setSafeValue('Turbo',              $s->turbo);
        $this->setSafeValue('Purifier',           $s->purifier);
        $this->setSafeValue('Dryer',              $s->dryer);
        $this->setSafeValue('FrostProtect',       $s->frostProtect);
        $this->setSafeValue('ShowScreen',         $s->showScreen);

        if ($s->outdoorTemperature !== null) {
            // Variable erst beim ersten echten Wert anlegen
            $this->RegisterVariableFloat('OutdoorTemperature', $this->Translate('Outdoor Temperature'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' °C',
                'DIGITS'       => 1,
            ], 60);
            $this->SetValue('OutdoorTemperature', $s->outdoorTemperature);
        }
        if (isset($s->indoorHumidity)) {
            $this->setSafeValue('IndoorHumidity', $s->indoorHumidity);
        }
    }

    // ── Steuer-Actions ────────────────────────────────────────────────────

    private function handleDehumidifierAction(string $ident, $value): void
    {
        $state = [
            'running'       => $this->getSafeValue('Running',       false),
            'mode'          => $this->getSafeValue('Mode',          1),
            'fanSpeed'      => $this->getSafeValue('FanSpeed',      0x32),
            'targetHumidity'=> $this->getSafeValue('TargetHumidity',60),
            'ionMode'       => $this->getSafeValue('IonMode',       false),
            'pump'          => $this->getSafeValue('Pump',          false),
            'sleepSwitch'   => $this->getSafeValue('SleepMode',     false),
            'verticalSwing' => $this->getSafeValue('VerticalSwing', false),
        ];

        // Mapping: Ident → internes state-Feld
        $mapping = [
            'Running'       => 'running',
            'Mode'          => 'mode',
            'FanSpeed'      => 'fanSpeed',
            'TargetHumidity'=> 'targetHumidity',
            'IonMode'       => 'ionMode',
            'Pump'          => 'pump',
            'SleepMode'     => 'sleepSwitch',
            'VerticalSwing' => 'verticalSwing',
        ];

        if (isset($mapping[$ident])) {
            $state[$mapping[$ident]] = $value;
        }

        $cmd     = MideaCommands::dehumidifierSetCommand($state);
        $payload = $this->sendCommand($cmd);
        if ($payload !== null) {
            $this->applyDehumidifierState(new DehumidifierResponse($payload));
        } else {
            // Optimistisch: Wert sofort setzen
            $this->SetValue($ident, $value);
        }
    }

    private function handleAirConditionerAction(string $ident, $value): void
    {
        $state = [
            'running'         => $this->getSafeValue('Running',          false),
            'mode'            => $this->getSafeValue('Mode',             2), // 2=Kühlen (1-basiert)
            'temperature'     => $this->getSafeValue('TargetTemperature',22.0),
            'fanSpeed'        => $this->getSafeValue('FanSpeed',         102),
            'verticalSwing'   => $this->getSafeValue('VerticalSwing',    0) > 0,
            'horizontalSwing' => $this->getSafeValue('HorizontalSwing',  0) > 0,
            'ecoMode'         => $this->getSafeValue('EcoMode',          false),
            'turbo'           => $this->getSafeValue('Turbo',            false),
            'purifier'        => $this->getSafeValue('Purifier',         false),
            'dryer'           => $this->getSafeValue('Dryer',            false),
            'frostProtect'    => $this->getSafeValue('FrostProtect',     false),
            'comfortMode'     => $this->getSafeValue('ComfortMode',      false),
        ];

        $mapping = [
            'Running'          => 'running',
            'Mode'             => 'mode',
            'TargetTemperature'=> 'temperature',
            'FanSpeed'         => 'fanSpeed',
            'EcoMode'          => 'ecoMode',
            'Turbo'            => 'turbo',
            'Purifier'         => 'purifier',
            'Dryer'            => 'dryer',
            'FrostProtect'     => 'frostProtect',
            'ComfortMode'      => 'comfortMode',
        ];

        if (isset($mapping[$ident])) {
            $state[$mapping[$ident]] = $value;
        }

        $cmd     = MideaCommands::airConditionerSetCommand($state);
        $payload = $this->sendCommand($cmd);
        if ($payload !== null) {
            $this->applyAirConditionerState(new AirConditionerResponse($payload));
        } else {
            $this->SetValue($ident, $value);
        }
    }

    // ── LAN-Kommunikation ─────────────────────────────────────────────────

    /**
     * Sendet einen Befehl über LAN und gibt die entschlüsselte Antwort-Nutzlast zurück,
     * oder null bei Fehler.
     */
    private function sendCommand(string $cmdBytes): ?string
    {
        $ip    = $this->ReadPropertyString('IPAddress');
        $port  = $this->ReadPropertyInteger('Port');
        $token = $this->ReadPropertyString('Token');
        $key   = $this->ReadPropertyString('Key');
        $appId = $this->ReadPropertyString('ApplianceID');

        $crypto    = new MideaCrypto();
        $lanPacket = $this->buildLanPacket($cmdBytes, $appId, $crypto);

        if (!empty($token) && !empty($key)) {
            return $this->sendV3($ip, $port, $token, $key, $lanPacket, $crypto);
        } else {
            return $this->sendV2($ip, $port, $lanPacket, $crypto);
        }
    }

    /**
     * Baut einen vollständigen LAN-Paket-Frame für das Gerät.
     * Header (40 Bytes) + AES-ECB-verschlüsselter Befehl + MD5-Prüfsumme (16 Bytes).
     */
    private function buildLanPacket(string $cmdData, string $applianceId, MideaCrypto $crypto): string
    {
        $now = new DateTime();

        // Gerät-ID als 8-Byte-Little-Endian (sicher für große Zahlen)
        $idBytes = $this->stringToBytes64LE($applianceId);

        // Zeitstempel
        $cs      = (int)($now->format('u') / 10000);  // Centisekunden
        $second  = (int)$now->format('s');
        $minute  = (int)$now->format('i');
        $hour    = (int)$now->format('G');
        $day     = (int)$now->format('j');
        $month   = (int)$now->format('n');
        $year2   = (int)$now->format('y');
        $century = (int)($now->format('Y') / 100);

        $packet  = chr(0x5A) . chr(0x5A)   // Header
                 . chr(0x01) . chr(0x11)   // Nachrichtentyp
                 . chr(0x00) . chr(0x00)   // Länge (wird unten gesetzt)
                 . chr(0x20) . chr(0x00)
                 . chr(0x00) . chr(0x00) . chr(0x00) . chr(0x00)  // MessageID
                 . chr($cs)  . chr($second) . chr($minute) . chr($hour)
                 . chr($day) . chr($month)  . chr($year2)  . chr($century)
                 . $idBytes
                 . str_repeat("\x00", 12);

        // Verschlüsselter Befehl anhängen
        $packet .= $crypto->aesEncrypt($cmdData);

        // Länge setzen (Little-Endian, inkl. 16 Bytes für den MD5-Footer)
        $totalLen   = strlen($packet) + 16;
        $packet[4]  = chr($totalLen & 0xFF);
        $packet[5]  = chr(($totalLen >> 8) & 0xFF);

        // MD5-Fingerabdruck anhängen
        $packet .= $crypto->md5fingerprint($packet);

        return $packet;
    }

    /** Sendet via V3-Protokoll (8370 mit Token/Key-Handshake). */
    private function sendV3(string $ip, int $port, string $token, string $key, string $lanPacket, MideaCrypto $crypto): ?string
    {
        $socket = @fsockopen($ip, $port, $errno, $errstr, self::SOCKET_TIMEOUT);
        if ($socket === false) {
            $this->SendDebug("sendV3", "Verbindung fehlgeschlagen $ip:$port – $errstr ($errno)", 0);
            return null;
        }

        try {
            stream_set_timeout($socket, self::SOCKET_TIMEOUT);

            // Handshake: Token als 8370-Paket senden
            $byteToken  = hex2bin($token);
            $byteKey    = hex2bin($key);
            $handshake  = $crypto->encode8370($byteToken, MideaCrypto::MSGTYPE_HANDSHAKE_REQUEST);
            fwrite($socket, $handshake);

            $hsResp = $this->readSocket($socket, 1024);
            if ($hsResp === null || strlen($hsResp) < 72) {
                $this->SendDebug("sendV3", "Handshake-Antwort fehlt oder zu kurz", 0);
                return null;
            }

            // Bytes [8:72] enthalten die eigentlichen 64 Handshake-Bytes
            $crypto->deriveTcpKey(substr($hsResp, 8, 64), $byteKey);

            // Kurze Pause nach Authentifizierung (Gerät braucht etwas Zeit)
            usleep(500000);

            // Eigentliche Anfrage senden
            $encoded  = $crypto->encode8370($lanPacket, MideaCrypto::MSGTYPE_ENCRYPTED_REQUEST);
            fwrite($socket, $encoded);

            $respBuf  = $this->readSocket($socket, 2048);
            if ($respBuf === null) {
                return null;
            }

            [$packets,] = $crypto->decode8370($respBuf);
            return $this->extractPayloadFromPackets($packets, $crypto);

        } catch (Exception $e) {
            $this->SendDebug("sendV3", "Fehler: " . $e->getMessage(), 0);
            return null;
        } finally {
            fclose($socket);
        }
    }

    /** Sendet via V2-Protokoll (direkte AES-ECB-Pakete ohne Handshake). */
    private function sendV2(string $ip, int $port, string $lanPacket, MideaCrypto $crypto): ?string
    {
        $socket = @fsockopen($ip, $port, $errno, $errstr, self::SOCKET_TIMEOUT);
        if ($socket === false) {
            $this->SendDebug("sendV2", "Verbindung fehlgeschlagen $ip:$port – $errstr ($errno)", 0);
            return null;
        }

        try {
            stream_set_timeout($socket, self::SOCKET_TIMEOUT);
            fwrite($socket, $lanPacket);

            $resp = $this->readSocket($socket, 2048);
            if ($resp === null) {
                return null;
            }

            // ZZ-Pakete (0x5A5A Header)
            if (strlen($resp) > 5 && substr($resp, 0, 2) === MideaCrypto::HDR_ZZ) {
                $packets = [];
                $offset  = 0;
                while ($offset < strlen($resp)) {
                    $size = ord($resp[$offset + 4]);  // Low-Byte der Paketlänge
                    if ($size < 40 || $offset + $size > strlen($resp)) {
                        break;
                    }
                    $chunk     = substr($resp, $offset, $size);
                    $encrypted = substr($chunk, 40, $size - 40 - 16);
                    if (strlen($encrypted) > 0) {
                        $packets[] = $crypto->aesDecrypt($encrypted);
                    }
                    $offset += $size;
                }
                return $this->extractPayloadFromRaw($packets);
            }

        } catch (Exception $e) {
            $this->SendDebug("sendV2", "Fehler: " . $e->getMessage(), 0);
        } finally {
            fclose($socket);
        }
        return null;
    }

    /**
     * Verarbeitet V3-Antwortpakete nach 8370-Dekodierung:
     * Jedes Paket ist der vollständige LAN-Frame. Bytes [40:-16] enthalten
     * die AES-ECB-verschlüsselte Befehlsantwort.
     */
    private function extractPayloadFromPackets(array $packets, MideaCrypto $crypto): ?string
    {
        foreach ($packets as $packet) {
            if (strlen($packet) > 56) {   // > 40 (Header) + 16 (MD5)
                $encrypted = substr($packet, 40, strlen($packet) - 56);
                try {
                    $decrypted = $crypto->aesDecrypt($encrypted);
                    $payload   = $this->stripCommandHeader($decrypted);
                    if ($payload !== null) {
                        return $payload;
                    }
                } catch (Exception $e) {
                    continue;
                }
            } elseif (strlen($packet) > 10) {
                $payload = $this->stripCommandHeader($packet);
                if ($payload !== null) {
                    return $payload;
                }
            }
        }
        return null;
    }

    /**
     * Verarbeitet V2-Rohpakete (bereits AES-ECB-entschlüsselt).
     */
    private function extractPayloadFromRaw(array $packets): ?string
    {
        foreach ($packets as $decrypted) {
            $payload = $this->stripCommandHeader($decrypted);
            if ($payload !== null) {
                return $payload;
            }
        }
        return null;
    }

    /**
     * Entfernt den 10-Byte-Befehlsheader (0xAA + Länge + Typ + ...).
     * Gibt die reine Nutzlast zurück, oder null wenn ungültig.
     */
    private function stripCommandHeader(string $data): ?string
    {
        if (strlen($data) <= 10) {
            return null;
        }
        // Befehlsantworten beginnen immer mit 0xAA
        if (ord($data[0]) === 0xAA) {
            return substr($data, 10);
        }
        // B5-Antworten sind bereits ohne Header
        return strlen($data) > 0 ? $data : null;
    }

    // ── Socket-Hilfsmethoden ──────────────────────────────────────────────

    /**
     * Liest ein vollständiges Paket vom Socket.
     * Unterstützt 8370-Pakete (0x83 0x70) mit bekannter Länge im Header.
     */
    private function readSocket($socket, int $maxBytes): ?string
    {
        $data    = '';
        $timeout = microtime(true) + self::SOCKET_TIMEOUT;

        while (microtime(true) < $timeout) {
            $chunk = @fread($socket, min(512, $maxBytes - strlen($data)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out'] || $meta['eof']) {
                    break;
                }
                usleep(5000);
                continue;
            }
            $data .= $chunk;

            // Bei 8370-Paketen: sobald genug Header da ist, Gesamtlänge prüfen
            if (strlen($data) >= 4 && ord($data[0]) === 0x83 && ord($data[1]) === 0x70) {
                $expected = ((ord($data[2]) << 8) | ord($data[3])) + 8;
                if (strlen($data) >= $expected) {
                    break;
                }
            }
            // Bei ZZ-Paketen (0x5A5A): Länge aus Bytes 4-5 prüfen
            elseif (strlen($data) >= 6 && ord($data[0]) === 0x5A && ord($data[1]) === 0x5A) {
                $expected = ((ord($data[5]) << 8) | ord($data[4]));  // Little-Endian
                if (strlen($data) >= $expected) {
                    break;
                }
            }
            // Andere Pakete: nach 100ms Pause abbrechen wenn genug Daten da
            elseif (strlen($data) >= 40) {
                usleep(100000);  // 100ms warten für weitere Pakete
                $chunk = @fread($socket, min(512, $maxBytes - strlen($data)));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
                break;
            }
        }

        return $data !== '' ? $data : null;
    }

    // ── Hilfsmethoden ────────────────────────────────────────────────────

    private function getSafeValue(string $ident, $default)
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($varId !== false && $varId > 0) {
            return GetValue($varId);
        }
        return $default;
    }

    /**
     * Setzt einen Variablenwert nur wenn die Variable existiert.
     * Für optionale Variablen die möglicherweise nicht registriert sind.
     * Nutzt @ um PHP-Warnings zu unterdrücken (GetIDForIdent wirft Warnings, keine Exceptions).
     */
    private function setSafeValue(string $ident, $value): void
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($varId !== false && $varId > 0) {
            $this->SetValue($ident, $value);
        }
    }

    /**
     * Konvertiert eine Dezimalzahl-String exakt zu 8-Byte-Little-Endian.
     * Nutzt GMP für Präzision bei großen ApplianceIDs (> PHP_INT_MAX nicht möglich,
     * aber GMP vermeidet Float-Rundungsfehler bei 64-Bit-nahen Werten).
     */
    private function stringToBytes64LE(string $numStr): string
    {
        // Leere oder ungültige ApplianceID → 8 Null-Bytes
        if (!preg_match('/^\d+$/', $numStr)) {
            return str_repeat("\x00", 8);
        }

        // Dezimal zu Hex konvertieren mit GMP
        $hex = gmp_strval(gmp_init($numStr), 16);

        // Auf 16 Zeichen (8 Bytes) padden mit führenden Nullen
        $hex = str_pad($hex, 16, '0', STR_PAD_LEFT);

        // Hex-Paare von rechts nach links extrahieren (Little-Endian)
        $bytes = '';
        for ($i = 14; $i >= 0; $i -= 2) {
            $hexPair = substr($hex, $i, 2);
            $bytes .= chr(hexdec($hexPair));
        }

        return $bytes;
    }

    /**
     * Testet ob Token+Key mit dem Gerät funktionieren.
     * Genau wie Python: _authenticate() in lan.py
     * Nur Handshake - kein eigentlicher Befehl.
     */
    private function testTokenConnection(string $ip, int $port, string $token, string $key): bool
    {
        $this->SendDebug("Token", "Teste Verbindung zu $ip:$port...", 0);

        $socket = @fsockopen($ip, $port, $errno, $errstr, self::SOCKET_TIMEOUT);
        if ($socket === false) {
            $this->SendDebug("Token", "✗ Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            return false;
        }

        try {
            stream_set_timeout($socket, self::SOCKET_TIMEOUT);

            $crypto = new MideaCrypto('', '');
            $byteToken = hex2bin($token);
            $byteKey   = hex2bin($key);

            // V3 Handshake senden
            $handshake = $crypto->encode8370($byteToken, MideaCrypto::MSGTYPE_HANDSHAKE_REQUEST);
            fwrite($socket, $handshake);

            // Antwort lesen
            $hsResp = $this->readSocket($socket, 1024);
            if ($hsResp === null || strlen($hsResp) < 72) {
                $this->SendDebug("Token", "✗ Handshake-Antwort fehlt (Token ungültig)", 0);
                return false;
            }

            $this->SendDebug("Token", "✓ Handshake erfolgreich! Token ist gültig.", 0);
            return true;

        } catch (Exception $e) {
            $this->SendDebug("Token", "✗ Fehler: " . $e->getMessage(), 0);
            return false;
        } finally {
            fclose($socket);
        }
    }

}
