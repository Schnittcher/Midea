<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prüft, dass das Konfigurationsformular nur die manuelle Konfiguration des gewählten Gerätetyps zeigt.
 */
class ConfigurationFormTest extends TestCase
{
    private const MODULE = '{B4F7E2A9-8C3D-4F6B-9E1A-2D5C7F8B3E4A}';

    private int $id;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
        $this->id = IPS_CreateInstance(self::MODULE);
    }

    public function testDehumidifierShowsOnlyItsPanel(): void
    {
        $this->assertSame(['PanelAircon' => false, 'PanelDehumidifier' => true], $this->panelVisibility());
    }

    public function testAirConditionerShowsOnlyItsPanel(): void
    {
        IPS_SetProperty($this->id, 'DeviceType', MideaAir::DEVICE_AIRCON);
        IPS_ApplyChanges($this->id);

        $this->assertSame(['PanelAircon' => true, 'PanelDehumidifier' => false], $this->panelVisibility());
    }

    public function testUnknownDeviceTypeShowsAirConditionerPanel(): void
    {
        // Wie ApplyChanges: alles außer dehumidifier gilt als Klimaanlage
        IPS_SetProperty($this->id, 'DeviceType', '');
        IPS_ApplyChanges($this->id);

        $this->assertSame(['PanelAircon' => true, 'PanelDehumidifier' => false], $this->panelVisibility());
    }

    public function testDeviceTypeSelectSwitchesPanels(): void
    {
        $select = $this->element('DeviceType');
        $this->assertStringContainsString("IPS_RequestAction(\$id, 'DeviceType', \$DeviceType)", $select['onChange'] ?? '');
    }

    private function panelVisibility(): array
    {
        return [
            'PanelAircon'       => $this->element('PanelAircon')['visible'] ?? true,
            'PanelDehumidifier' => $this->element('PanelDehumidifier')['visible'] ?? true,
        ];
    }

    private function element(string $name): array
    {
        $form = json_decode(IPS_GetConfigurationForm($this->id), true);
        foreach ($form['elements'] ?? [] as $element) {
            if (($element['name'] ?? '') === $name) {
                return $element;
            }
        }
        $this->fail("Formularfeld $name fehlt");
    }
}
