<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prüft die Stellbefehle des Entfeuchters ohne Gerät.
 *
 * An 0.0.0.0 scheitert die Verbindung sofort (Windows: Adresse nicht verfügbar, Linux: abgelehnt).
 * sendCommand() bekommt keine Antwort, und das Modul setzt den gesendeten Wert optimistisch in die
 * Variable. Daran lässt sich ablesen, was an das Gerät gegangen wäre.
 */
class DehumidifierActionTest extends TestCase
{
    private const MODULE = '{B4F7E2A9-8C3D-4F6B-9E1A-2D5C7F8B3E4A}';

    private int $id;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
        $this->id = IPS_CreateInstance(self::MODULE);
        IPS_SetProperty($this->id, 'IPAddress', '0.0.0.0');
        IPS_ApplyChanges($this->id);
    }

    public function testSilentAndAutoAreSentUnchanged(): void
    {
        // Rohwerte am Comfee CDDKE-20DEN7(EU) in der App gemessen: Leise 10, Auto 11
        foreach ([10, 11, 4] as $mode) {
            IPS_RequestAction($this->id, 'Mode', $mode);
            $this->assertSame($mode, GetValue(IPS_GetObjectIDByIdent('Mode', $this->id)));
        }
    }

    public function testFanSpeedIsRejectedInSilentMode(): void
    {
        // Am Comfee CDDKE-20DEN7(EU) weist das Bedienfeld die Lüfterstufe im Leise-Modus ab. Über das Netz
        // meldet das Gerät die neue Stufe zurück, der Lüfter bleibt aber leise (Hörtest 23.10.2026).
        $fanSpeed = IPS_GetObjectIDByIdent('FanSpeed', $this->id);
        IPS_RequestAction($this->id, 'FanSpeed', 40);
        IPS_RequestAction($this->id, 'Mode', 10);

        $gesendet = $this->transportMessages();
        $this->requestCapturingWarnings('FanSpeed', 80);
        $this->assertSame(40, GetValue($fanSpeed));
        $this->assertSame($gesendet, $this->transportMessages(), 'Im Leise-Modus darf kein Befehl an das Gerät gehen');

        // In den übrigen Modi bleibt die Stufe einstellbar
        IPS_RequestAction($this->id, 'Mode', 11);
        IPS_RequestAction($this->id, 'FanSpeed', 80);
        $this->assertSame(80, GetValue($fanSpeed));
    }

    public function testModeSwitchSendsFixedFanSpeed(): void
    {
        // Trocknen: beide Comfee stellen den Lüfter selbst auf 80 (Mitschnitte in tests/fixtures).
        // Leise: Das Gerät übernimmt die mitgeschickte Stufe in seine Meldung, die App schickt 40.
        $fanSpeed = IPS_GetObjectIDByIdent('FanSpeed', $this->id);
        IPS_RequestAction($this->id, 'FanSpeed', 60);

        IPS_RequestAction($this->id, 'Mode', 4);
        $this->assertSame(80, GetValue($fanSpeed), 'Trocknen');

        IPS_RequestAction($this->id, 'Mode', 10);
        $this->assertSame(40, GetValue($fanSpeed), 'Leise nach Trocknen');

        // Ohne feste Stufe bleibt die zuletzt gemeldete
        IPS_RequestAction($this->id, 'Mode', 11);
        $this->assertSame(40, GetValue($fanSpeed), 'Auto');
    }

    public function testFanSpeedIsRejectedInDryMode(): void
    {
        $fanSpeed = IPS_GetObjectIDByIdent('FanSpeed', $this->id);
        IPS_RequestAction($this->id, 'Mode', 4);

        $gesendet = $this->transportMessages();
        $this->requestCapturingWarnings('FanSpeed', 60);
        $this->assertSame(80, GetValue($fanSpeed));
        $this->assertSame($gesendet, $this->transportMessages(), 'Im Modus Trocknen darf kein Befehl an das Gerät gehen');
    }

    public function testRejectedFanSpeedWarnsCaller(): void
    {
        IPS_RequestAction($this->id, 'Mode', 10);

        $this->assertSame(
            [[E_USER_WARNING, 'Fan speed cannot be set in this mode']],
            $this->requestCapturingWarnings('FanSpeed', 80)
        );
    }

    public function testTargetHumidityIsLimitedToSliderRange(): void
    {
        // 35 bis 85 % wie der Slider, die Comfee-App und die HA-Integration (MIN/MAX_TARGET_HUMIDITY)
        foreach ([30 => 35, 35 => 35, 50 => 50, 85 => 85, 90 => 85] as $requested => $sent) {
            IPS_RequestAction($this->id, 'TargetHumidity', $requested);
            $this->assertSame($sent, GetValue(IPS_GetObjectIDByIdent('TargetHumidity', $this->id)), "angefordert $requested");
        }
    }

    private function requestCapturingWarnings(string $ident, int $value): array
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings)
        {
            $warnings[] = [$errno, $errstr];
            return true;
        });
        try {
            IPS_RequestAction($this->id, $ident, $value);
        } finally {
            restore_error_handler();
        }
        return $warnings;
    }

    // Jeder Sendeversuch hinterlässt eine Meldung von sendV2/sendV3 im Debug
    private function transportMessages(): int
    {
        return count(array_filter(
            IPS\DebugServer::getDebugMessages($this->id),
            fn (array $d) => str_starts_with($d['Message'], 'send')
        ));
    }
}
