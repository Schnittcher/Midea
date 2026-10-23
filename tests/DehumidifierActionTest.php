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
        // Rohwerte am zweiten Comfee in der App gemessen: Leise 10, Auto 11
        foreach ([10, 11, 4] as $mode) {
            IPS_RequestAction($this->id, 'Mode', $mode);
            $this->assertSame($mode, GetValue(IPS_GetObjectIDByIdent('Mode', $this->id)));
        }
    }

    public function testTargetHumidityIsLimitedToSliderRange(): void
    {
        // 35 bis 85 % wie der Slider, die Comfee-App und die HA-Integration (MIN/MAX_TARGET_HUMIDITY)
        foreach ([30 => 35, 35 => 35, 50 => 50, 85 => 85, 90 => 85] as $requested => $sent) {
            IPS_RequestAction($this->id, 'TargetHumidity', $requested);
            $this->assertSame($sent, GetValue(IPS_GetObjectIDByIdent('TargetHumidity', $this->id)), "angefordert $requested");
        }
    }
}
