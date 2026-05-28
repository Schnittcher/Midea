<?php
declare(strict_types=1);

/**
 * Befehlsstruktur und Antwort-Parser für Midea-Geräte.
 * Portiert aus midea-beautiful-air (Python) von nbogojevic.
 *
 * Byte-Offsets nach dem 10-Byte-Befehlsheader (nach 0xAA, Länge, Typ, ...):
 * data[0]=0x41/0x48, data[1]=Flags, data[2]=Mode, data[3]=FanSpeed, ...
 */

class MideaCommands
{
    private static int $sequence = 0;

    private static function nextSequence(): int
    {
        self::$sequence = (self::$sequence + 1) & 0xFF;
        return self::$sequence;
    }

    /**
     * Fügt Sequenznummer, CRC8 und Prüfsumme ein und gibt fertige Befehlsbytes zurück.
     * sequenceIdx = -1 bedeutet kein Sequenzbyte (für MideaCommand ohne Sequenz).
     */
    private static function finalize(string $data, int $sequenceIdx = -1): string
    {
        $len = strlen($data);
        if ($sequenceIdx >= 0 && $sequenceIdx < $len) {
            $data[$sequenceIdx] = chr(self::nextSequence());
        }
        // CRC8 über Bytes [10 .. len-3] (= data[10:-2])
        $crc = MideaCrypto::crc8(substr($data, 10, $len - 12));
        $data[$len - 2] = chr($crc);
        // Prüfsumme = (~sum(data[1:-1]) + 1) & 0xFF
        $sum = 0;
        for ($i = 1; $i < $len - 1; $i++) {
            $sum += ord($data[$i]);
        }
        $data[$len - 1] = chr((~$sum + 1) & 0xFF);
        return $data;
    }

    // ── Entfeuchter (0xA1) ────────────────────────────────────────────────

    /**
     * Statusabfrage-Befehl für Entfeuchter. 33 Bytes:
     *  [0-9]  Header, [10-17] Payload-Start, [18-29] 12x 0x00,
     *  [30] MessageID, [31] CRC8, [32] Checksum.
     */
    public static function dehumidifierStatusCommand(): string
    {
        $data = "\xAA\x20\xA1\x00\x00\x00\x00\x00\x00\x03"
              . "\x41\x81\x00\xFF\x03\xFF\x00\x00"
              . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
              . "\x00\x00\x00";
        // Byte 30 = MessageID, 31 = CRC8, 32 = Checksum → gesamt 33 Bytes
        return self::finalize($data, 30);
    }

    /**
     * Set-Befehl für Entfeuchter. 33 Bytes.
     * state-Schlüssel: running, mode, fanSpeed, targetHumidity, ionMode,
     *   pump, pumpSwitchFlag, sleepSwitch, verticalSwing, beepPrompt, tankWarningLevel.
     */
    public static function dehumidifierSetCommand(array $state): string
    {
        // [0-9] Header (0xAA, 0x20, 0xA1, 0, 0, 0, 0, 0, 0x03, 0x02)
        // [10] 0x48 Schreib-Typ
        // [11] Flags: running=bit0, beepPrompt=bit6
        // [12] Mode (low nibble)
        // [13] FanSpeed (low 7 Bit)
        // [14-16] 3x 0x00
        // [17] targetHumidity (low 7 Bit)
        // [18] 0x00
        // [19] ionMode=bit6, sleepSwitch=bit5, pumpSwitchFlag=bit4, pumpSwitch=bit3
        // [20] verticalSwing=bit5
        // [21-22] 2x 0x00
        // [23] tankWarningLevel
        // [24-29] 6x 0x00
        // [30] MessageID, [31] CRC8, [32] Checksum → 33 Bytes
        $data = "\xAA\x20\xA1\x00\x00\x00\x00\x00\x03\x02"
              . "\x48\x00\x01\x32"
              . "\x00\x00\x00"
              . "\x00"
              . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

        $d = str_split($data);

        $flags = 0;
        if (!empty($state['running']))    { $flags |= 0x01; }
        if (!empty($state['beepPrompt'])) { $flags |= 0x40; }
        $d[11] = chr($flags);

        $d[12] = chr(isset($state['mode']) ? ((int)$state['mode'] & 0x0F) : 0x01);
        $d[13] = chr(isset($state['fanSpeed']) ? ((int)$state['fanSpeed'] & 0x7F) : 0x32);

        $hum = isset($state['targetHumidity']) ? min(100, max(0, (int)$state['targetHumidity'])) : 60;
        $d[17] = chr($hum & 0x7F);

        $ctrl = 0;
        if (!empty($state['ionMode']))        { $ctrl |= 0x40; }
        if (!empty($state['sleepSwitch']))    { $ctrl |= 0x20; }
        if (!empty($state['pumpSwitchFlag'])) { $ctrl |= 0x10; }
        if (!empty($state['pump']))           { $ctrl |= 0x08; }
        $d[19] = chr($ctrl);

        $swing = 0;
        if (!empty($state['verticalSwing'])) { $swing |= 0x20; }
        $d[20] = chr($swing);

        if (isset($state['tankWarningLevel'])) {
            $d[23] = chr((int)$state['tankWarningLevel'] & 0xFF);
        }

        return self::finalize(implode('', $d), 30);
    }

    // ── Klimaanlage (0xAC) ────────────────────────────────────────────────

    /**
     * Statusabfrage-Befehl für Klimaanlage. 33 Bytes.
     * Byte 17 = 0x02 = Indoor-Temperaturanforderung.
     */
    public static function airConditionerStatusCommand(): string
    {
        $data = "\xAA\x20\xAC\x00\x00\x00\x00\x00\x00\x03"
              . "\x41\x81\x00\xFF\x03\xFF\x00\x02"
              . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
              . "\x00\x00\x00";
        return self::finalize($data, 30);
    }

    /**
     * Set-Befehl für Klimaanlage. 36 Bytes.
     * [0-9]  Header (0xAA, 0x23, 0xAC, 0, 0, 0, 0, 0, 0, 0x02)
     * [10]   0x40 Schreib-Typ
     * [11]   Flags: running=bit0, beepPrompt=bit6
     * [12]   (mode<<5)|(tempDec<<4)|(tempInt-16)
     * [13]   FanSpeed
     * [14-16] 3x 0x00
     * [17]   Swing: vertikalBits=3-2, horizontalBits=1-0
     * [18]   turboFan=bit5, comfortSleepVal=bits1-0
     * [19]   eco=bit7, purifier=bit5, dryer=bit2
     * [20]   comfortSleep=bit7, fahrenheit=bit2, turbo=bit1
     * [21-29] 9x 0x00
     * [30]   MessageID
     * [31]   frostProtect=bit7
     * [32]   comfortMode=bit0
     * [33]   0x00
     * [34]   CRC8, [35] Checksum → 36 Bytes
     */
    public static function airConditionerSetCommand(array $state): string
    {
        // 36 Bytes: [0-9] Header, [10-13] Typ+Flags+Mode+Fan,
        // [14-16] Padding, [17-20] Swing+Ctrl, [21-30] Padding+MessageID,
        // [31-33] FrostProtect+ComfortMode+Pad, [34] CRC8, [35] Checksum
        $data = "\xAA\x23\xAC\x00\x00\x00\x00\x00\x00\x02"
              . "\x40\x00\x00\x00"
              . "\x00\x00\x00"
              . "\x00\x00\x00\x00"
              . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
              . "\x00\x00\x00\x00\x00";

        $d = str_split($data);

        // [11] Flags
        $flags = 0;
        if (!empty($state['running']))    { $flags |= 0x01; }
        if (!empty($state['beepPrompt'])) { $flags |= 0x40; }
        $d[11] = chr($flags);

        // [12] Mode + Temperatur
        $mode = isset($state['mode']) ? ((int)$state['mode'] & 0x07) : 0;
        $temp = isset($state['temperature']) ? (float)$state['temperature'] : 22.0;
        $temp = max(16.0, min(31.0, $temp));
        $tempInt = (int)$temp;
        $tempDec = ($temp - $tempInt >= 0.5) ? 1 : 0;
        $d[12] = chr(($mode << 5) | ($tempDec << 4) | (($tempInt - 16) & 0x0F));

        // [13] FanSpeed
        $d[13] = chr(isset($state['fanSpeed']) ? ((int)$state['fanSpeed'] & 0x7F) : 102);

        // [17] Swing
        $swing = 0;
        if (!empty($state['verticalSwing']))   { $swing |= 0x3C; }
        if (!empty($state['horizontalSwing'])) { $swing |= 0x03; }
        $d[17] = chr($swing);

        // [18] TurboFan, ComfortSleep-Wert
        $b18 = 0;
        if (!empty($state['turboFan']))     { $b18 |= 0x20; }
        if (!empty($state['comfortSleep'])) { $b18 |= 0x03; }
        $d[18] = chr($b18);

        // [19] Eco, Purifier, Dryer
        $b19 = 0;
        if (!empty($state['ecoMode']))  { $b19 |= 0x80; }
        if (!empty($state['purifier'])) { $b19 |= 0x20; }
        if (!empty($state['dryer']))    { $b19 |= 0x04; }
        $d[19] = chr($b19);

        // [20] Fahrenheit, Turbo, ComfortSleep
        $b20 = 0;
        if (!empty($state['fahrenheit']))   { $b20 |= 0x04; }
        if (!empty($state['turbo']))        { $b20 |= 0x02; }
        if (!empty($state['comfortSleep'])) { $b20 |= 0x80; }
        $d[20] = chr($b20);

        // [31] FrostProtect
        $d[31] = chr(!empty($state['frostProtect']) ? 0x80 : 0x00);

        // [32] ComfortMode
        $d[32] = chr(!empty($state['comfortMode']) ? 0x01 : 0x00);

        return self::finalize(implode('', $d), 30);
    }

    // ── Stromverbrauch (0xAC) ─────────────────────────────────────────────

    /**
     * Abfrage-Befehl für den Stromverbrauch (Electricity). 15 Bytes.
     * Payload: 0x41 = Lesebefehl, 0x42 = Electricity-Subtyp, 0x01.
     */
    public static function airConditionerElectricityCommand(): string
    {
        $data = "\xAA\x0E\xAC\x00\x00\x00\x00\x00\x03\x04"
              . "\x41\x42\x01\x00\x00";
        return self::finalize($data);
    }

    // ── B5-Fähigkeitsabfragen ─────────────────────────────────────────────

    /** Fähigkeitsabfrage-Befehl (B5). 15 Bytes, kein Sequenzbyte. */
    public static function deviceCapabilitiesCommand(int $applianceType): string
    {
        $data = "\xAA\x0E" . chr($applianceType) . "\x00\x00\x00\x00\x00\x03\x03"
              . "\xB5\x01\x00\x00\x00";
        return self::finalize($data);
    }
}

// ── Antwort-Parser ────────────────────────────────────────────────────────────

/**
 * Parst die Antwort-Nutzlast eines Entfeuchters (nach dem 10-Byte-Befehlsheader).
 * data[0]=0x41 (Statustyp), data[1]=Flags, data[2]=Mode, data[3]=FanSpeed, ...
 */
class DehumidifierResponse
{
    public bool  $fault;
    public bool  $runStatus;
    public bool  $iMode;
    public bool  $timingMode;
    public int   $mode;
    public int   $modeFc;
    public int   $fanSpeed;
    public bool  $onTimerSet;
    public int   $onTimerHour;
    public int   $onTimerMinutes;
    public bool  $offTimerSet;
    public int   $offTimerHour;
    public int   $offTimerMinutes;
    public float $targetHumidity;
    public bool  $filterIndicator;
    public bool  $ionMode;
    public bool  $sleepSwitch;
    public bool  $pumpSwitchFlag;
    public bool  $pumpSwitch;
    public bool  $defrosting;
    public int   $tankLevel;
    public bool  $tankFull;
    public float $currentHumidity;
    public float $indoorTemperature;
    public bool  $verticalSwing;
    public bool  $horizontalSwing;
    public int   $errCode;
    public int   $dustTime;
    public int   $pm25;

    public function __construct(string $data)
    {
        $b = array_values(unpack('C*', $data));

        $this->fault        = ($b[1] & 0x80) !== 0;
        $this->runStatus    = ($b[1] & 0x01) !== 0;
        $this->iMode        = ($b[1] & 0x04) !== 0;
        $this->timingMode   = ($b[1] & 0x10) !== 0;
        $this->mode         = $b[2] & 0x0F;
        $this->modeFc       = ($b[2] & 0xF0) >> 4;
        $this->fanSpeed     = $b[3] & 0x7F;

        $this->onTimerSet     = ($b[4] & 0x80) !== 0;
        $this->onTimerHour    = ($b[4] & 0x7C) >> 2;
        $this->onTimerMinutes = ($b[4] & 0x03) * 15 + (($b[6] & 0xF0) >> 4);
        $this->offTimerSet    = ($b[5] & 0x80) !== 0;
        $this->offTimerHour   = ($b[5] & 0x7C) >> 2;
        $this->offTimerMinutes = ($b[5] & 0x03) * 15 + ($b[6] & 0x0F);

        $this->targetHumidity = min(100.0, (float)$b[7]) + ($b[8] & 0x0F) * 0.0625;

        $this->filterIndicator = ($b[9] & 0x80) !== 0;
        $this->ionMode         = ($b[9] & 0x40) !== 0;
        $this->sleepSwitch     = ($b[9] & 0x20) !== 0;
        $this->pumpSwitchFlag  = ($b[9] & 0x10) !== 0;
        $this->pumpSwitch      = ($b[9] & 0x08) !== 0;

        $this->defrosting = ($b[10] & 0x80) !== 0;
        $this->tankLevel  = $b[10] & 0x7F;
        $this->tankFull   = $this->tankLevel >= 100;

        $this->dustTime = ($b[11] ?? 0) * 2;
        $this->pm25     = ($b[13] ?? 0) + (($b[14] ?? 0) * 256);

        $this->currentHumidity = (float)($b[16] ?? 0);

        $rawTemp = $b[17] ?? 128;
        $this->indoorTemperature = ($rawTemp - 50) / 2.0;
        $this->indoorTemperature = max(-20.0, min(50.0, $this->indoorTemperature));
        $tempDec = (($b[18] ?? 0) & 0x0F) * 0.1;
        $this->indoorTemperature += ($this->indoorTemperature >= 0) ? $tempDec : -$tempDec;

        $this->verticalSwing   = isset($b[19]) && ($b[19] & 0x20) !== 0;
        $this->horizontalSwing = isset($b[19]) && ($b[19] & 0x10) !== 0;
        $this->errCode         = $b[21] ?? 0;
    }
}

/**
 * Parst die Antwort-Nutzlast einer Klimaanlage (nach dem 10-Byte-Befehlsheader).
 */
class AirConditionerResponse
{
    public bool   $runStatus;
    public bool   $iMode;
    public bool   $timingMode;
    public bool   $applianceError;
    public int    $mode;
    public float  $targetTemperature;
    public int    $fanSpeed;
    public int    $verticalSwing;
    public int    $horizontalSwing;
    public bool   $turboFan;
    public bool   $comfortSleep;
    public bool   $eco;
    public bool   $purifier;
    public bool   $dryer;
    public bool   $turbo;
    public bool   $fahrenheit;
    public bool   $showScreen;
    public ?float $indoorTemperature;
    public ?float $outdoorTemperature;
    public int    $errCode;
    public ?int   $humidity;
    public bool   $frostProtect;
    public bool   $comfortMode;

    public function __construct(string $data)
    {
        $b = array_values(unpack('C*', $data));

        $this->runStatus      = ($b[1] & 0x01) !== 0;
        $this->iMode          = ($b[1] & 0x04) !== 0;
        $this->timingMode     = ($b[1] & 0x10) !== 0;
        $this->applianceError = ($b[1] & 0x80) !== 0;

        $this->mode              = ($b[2] & 0xE0) >> 5;
        $this->targetTemperature = ($b[2] & 0x0F) + 16.0 + (($b[2] & 0x10) ? 0.5 : 0.0);
        $this->fanSpeed          = $b[3] & 0x7F;

        $this->verticalSwing   = ($b[7] & 0x0C) >> 2;
        $this->horizontalSwing = $b[7] & 0x03;

        $this->turboFan     = ($b[8] & 0x20) !== 0;
        $this->comfortSleep = ($b[9] & 0x40) !== 0;
        $this->eco          = ($b[9] & 0x10) !== 0;
        $this->purifier     = ($b[9] & 0x20) !== 0;
        $this->dryer        = ($b[9] & 0x04) !== 0;
        $this->turbo        = ($b[10] & 0x02) !== 0;
        $this->fahrenheit   = ($b[10] & 0x04) !== 0;

        $screenBits       = isset($b[14]) ? (($b[14] >> 4) & 0x07) : 7;
        $this->showScreen = ($screenBits !== 7) && $this->runStatus;

        if (isset($b[11]) && $b[11] !== 0 && $b[11] !== 0xFF) {
            $t = ($b[11] - 50) / 2.0;
            $d = isset($b[15]) ? ($b[15] & 0x0F) * 0.1 : 0.0;
            $this->indoorTemperature = $t < 0 ? $t - $d : $t + $d;
        } else {
            $this->indoorTemperature = null;
        }

        if (isset($b[12]) && $b[12] !== 0 && $b[12] !== 0xFF) {
            $t = ($b[12] - 50) / 2.0;
            $d = isset($b[15]) ? (($b[15] & 0xF0) >> 4) * 0.1 : 0.0;
            $this->outdoorTemperature = $t < 0 ? $t - $d : $t + $d;
        } else {
            $this->outdoorTemperature = null;
        }

        $this->errCode  = $b[16] ?? 0;
        $this->humidity = isset($b[19]) ? $b[19] : null;

        if (isset($b[21])) {
            $this->frostProtect = ($b[21] & 0x80) !== 0;
        } else {
            $this->frostProtect = ($b[10] & 0x20) !== 0;
        }
        $this->comfortMode = isset($b[22]) ? ($b[22] & 0x01) !== 0 : false;
    }
}

/**
 * Parst die Antwort auf den Electricity-Befehl.
 * b[1..3] = Leistung in 0,1-W-Schritten (24-Bit Little-Endian).
 */
class AirConditionerElectricityResponse
{
    public float $power;   // Watt

    public function __construct(string $data)
    {
        $b           = array_values(unpack('C*', $data));
        $raw         = ($b[1] ?? 0) | (($b[2] ?? 0) << 8) | (($b[3] ?? 0) << 16);
        $this->power = $raw / 10.0;
    }
}
