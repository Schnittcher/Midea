<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prüft, was „Capabilities ermitteln“ nach der Antwort des Geräts meldet: jede Checkbox, die das
 * B5-Ergebnis vorbelegt, mit ihrer Beschriftung aus form.json und ✓ oder ✗.
 *
 * Der Stub übersetzt nicht, geprüft werden die englischen Texte. Die Frames stammen aus
 * B5ParserTest (Comfee 0xA1 und Midea U-Shaped Window Unit).
 */
class DetectionDialogTest extends TestCase
{
    private const MODULE = '{B4F7E2A9-8C3D-4F6B-9E1A-2D5C7F8B3E4A}';

    private const COMFEE_A1 = 'b503100201071f02010120020101cb5c';

    private const COMFEE_A1_SILENT = 'b50410020107170201022e02010114020107797f';

    private const AC_U_SHAPED = 'b50912020102130201001402010015020100170201021a02010010020101250207203c203c203c00240201010102a1a0';

    private int $id;
    private MideaAir $module;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
        $this->id = IPS_CreateInstance(self::MODULE);
        $this->module = IPS\InstanceManager::getInstanceInterface($this->id);
    }

    public function testDehumidifierDialogListsCheckboxes(): void
    {
        $dialog = $this->detect(self::COMFEE_A1, MideaAir::DEVICE_DEHUMIDIFIER);

        $this->assertStringContainsString('Manual Configuration (Dehumidifier)', $dialog);
        $this->assertSame([
            '✓ Target Humidity (1)',
            '✓ Continuous (2)',
            '✓ Smart (3)',
            '✓ Dry (4)',
            '✗ Purifier (6)',
            '✗ Antimould (7)',
            '✗ Silent (10)',
            '✗ Auto (11)',
            '✓ Fan Speed Medium (60) available',
            '✗ Ionizer available',
            '✗ Pump available',
            '✗ Air Vanes available',
            '✗ Tank Level Sensor available',
            '✗ Filter Indicator available',
        ], $this->checkboxLines($dialog));
        $this->assertFalse(IPS_GetProperty($this->id, 'DehumHasPump'));
        $this->assertTrue(IPS_GetProperty($this->id, 'DehumModeDry'));
    }

    public function testDehumidifierDialogForSilentAutoDevice(): void
    {
        $dialog = $this->detect(self::COMFEE_A1_SILENT, MideaAir::DEVICE_DEHUMIDIFIER);

        $this->assertSame([
            '✗ Target Humidity (1)',
            '✗ Continuous (2)',
            '✗ Smart (3)',
            '✓ Dry (4)',
            '✗ Purifier (6)',
            '✗ Antimould (7)',
            '✓ Silent (10)',
            '✓ Auto (11)',
            '✓ Fan Speed Medium (60) available',
            '✗ Ionizer available',
            '✗ Pump available',
            '✗ Air Vanes available',
            '✗ Tank Level Sensor available',
            '✓ Filter Indicator available',
        ], $this->checkboxLines($dialog));
    }

    public function testAirConditionerDialogListsCheckboxes(): void
    {
        IPS_SetProperty($this->id, 'DeviceType', MideaAir::DEVICE_AIRCON);
        IPS_ApplyChanges($this->id);

        $dialog = $this->detect(self::AC_U_SHAPED, MideaAir::DEVICE_AIRCON);

        $this->assertStringContainsString('Manual Configuration (Air Conditioner)', $dialog);
        $this->assertSame([
            '✗ Vertical Swing available',
            '✗ Horizontal Swing available',
            '✓ Eco Mode available',
            '✗ Turbo available',
            '✗ Air Purifier available',
            '✗ Dryer available',
            '✗ Frost Protection available',
            '✓ Display Control available',
            '✗ Indoor Humidity Sensor available',
            '✗ Power Measurement available',
        ], $this->checkboxLines($dialog));
        $this->assertTrue(IPS_GetProperty($this->id, 'HasEcoMode'));
        $this->assertFalse(IPS_GetProperty($this->id, 'HasTurbo'));
    }

    // Was DetectCapabilities() nach der Antwort des Geräts macht, ohne Gerät
    private function detect(string $b5Hex, string $deviceType): string
    {
        $caps = (new ReflectionMethod($this->module, 'parseB5Response'))->invoke($this->module, hex2bin($b5Hex), $deviceType);
        $dialog = (new ReflectionMethod($this->module, 'applyCapabilities'))->invoke($this->module, $caps, $deviceType);
        IPS_ApplyChanges($this->id);
        return $dialog;
    }

    private function checkboxLines(string $dialog): array
    {
        return array_values(preg_grep('/^[✓✗] /u', explode("\n", $dialog)));
    }
}
