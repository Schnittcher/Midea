<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prüft, welche Modi und Variablen ein Entfeuchter nach dem gespeicherten B5-Ergebnis bekommt.
 *
 * Grundlage ist der Comfee-Mitschnitt aus B5ParserTest. Das alte Attribut ist die Ausgabe des
 * Parsers aus Bibliothek 1.0.2 (53a9fdf) für genau diesen Frame.
 */
class DehumidifierCapabilitiesTest extends TestCase
{
    private const MODULE = '{B4F7E2A9-8C3D-4F6B-9E1A-2D5C7F8B3E4A}';

    private const COMFEE_A1 = 'b503100201071f02010120020101cb5c';

    // Attribut 'Capabilities', wie es 1.0.2 für den Comfee-Frame gespeichert hat
    private const COMFEE_A1_STORED_BY_1_0_2 = '{"fan_speed":true,"fan_speed_value":7,"humidity":true}';

    private const OPTIONAL_VARIABLES = ['IonMode', 'Pump', 'VerticalSwing', 'TankLevel'];

    private int $id;
    private MideaAir $module;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
        $this->id = IPS_CreateInstance(self::MODULE);
        $this->module = IPS\InstanceManager::getInstanceInterface($this->id);

        // Ausgangslage wie unter 1.0.2: alle Variablen vorhanden
        $this->registerVariables();
        foreach (self::OPTIONAL_VARIABLES as $ident) {
            $this->assertTrue($this->exists($ident));
        }
    }

    public function testCapabilitiesFromOlderVersionCountAsNotDetected(): void
    {
        (new ReflectionMethod($this->module, 'WriteAttributeString'))->invoke($this->module, 'Capabilities', self::COMFEE_A1_STORED_BY_1_0_2);
        $this->registerVariables();

        $this->assertSame([1, 2, 3, 4, 6, 7], $this->modeValues());
        foreach (self::OPTIONAL_VARIABLES as $ident) {
            $this->assertTrue($this->exists($ident), "$ident darf nicht gelöscht werden");
        }
    }

    public function testDetectedCapabilitiesLimitModesAndVariables(): void
    {
        $this->detect(self::COMFEE_A1);

        $this->assertSame([1, 2, 3, 4], $this->modeValues());
        $this->assertSame([40, 60, 80], $this->fanSpeedValues());
        foreach (self::OPTIONAL_VARIABLES as $ident) {
            $this->assertFalse($this->exists($ident), "$ident meldet der Comfee nicht");
        }
    }

    public function testDetectedCapabilitiesCanBeOverridden(): void
    {
        $this->detect(self::COMFEE_A1);

        // Gerät meldet Smart, hat es aber nicht; Pumpe meldet es nicht, ist aber vorhanden
        IPS_SetProperty($this->id, 'DehumModeSmart', false);
        IPS_SetProperty($this->id, 'DehumHasPump', true);
        IPS_ApplyChanges($this->id);
        $this->registerVariables();

        $this->assertSame([1, 2, 4], $this->modeValues());
        $this->assertTrue($this->exists('Pump'));
        $this->assertFalse($this->exists('IonMode'));
    }

    // Was DetectCapabilities() nach der Antwort des Geräts macht, ohne Gerät
    private function detect(string $b5Hex): void
    {
        $caps = (new ReflectionMethod($this->module, 'parseB5Response'))->invoke($this->module, hex2bin($b5Hex), MideaAir::DEVICE_DEHUMIDIFIER);
        (new ReflectionMethod($this->module, 'storeCapabilities'))->invoke($this->module, $caps);
        (new ReflectionMethod($this->module, 'applyB5ToDehumidifierProperties'))->invoke($this->module, $caps);
        IPS_ApplyChanges($this->id);
        $this->registerVariables();
    }

    // Der Teil von ApplyChanges(), der ohne Gerät auskommt
    private function registerVariables(): void
    {
        (new ReflectionMethod($this->module, 'RegisterDehumidifierVariables'))->invoke($this->module);
    }

    private function fanSpeedValues(): array
    {
        $presentation = IPS_GetVariable(IPS_GetObjectIDByIdent('FanSpeed', $this->id))['VariablePresentation'];
        return array_column(json_decode($presentation['OPTIONS'], true), 'Value');
    }

    private function modeValues(): array
    {
        $presentation = IPS_GetVariable(IPS_GetObjectIDByIdent('Mode', $this->id))['VariablePresentation'];
        return array_column(json_decode($presentation['OPTIONS'], true), 'Value');
    }

    private function exists(string $ident): bool
    {
        return @IPS_GetObjectIDByIdent($ident, $this->id) !== false;
    }
}
