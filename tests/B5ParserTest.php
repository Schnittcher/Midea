<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prüft parseB5Response() an echten B5-Antworten.
 *
 * Die Frames stammen aus Mitschnitten echter Geräte, keiner ist nachgebaut. Übergeben wird die
 * Nutzlast ab 0xB5, so wie sie im Debug von DetectCapabilities() steht.
 */
class B5ParserTest extends TestCase
{
    private const MODULE = '{B4F7E2A9-8C3D-4F6B-9E1A-2D5C7F8B3E4A}';

    // Comfee-Entfeuchter 0xA1, V3, am 20.10.2026 per DetectCapabilities() mitgeschnitten
    private const COMFEE_A1 = 'b503100201071f02010120020101cb5c';

    // Zweiter Comfee gleichen Modells (41377), am 23.10.2026 per DetectCapabilities() mitgeschnitten.
    // Die App bietet Leise (10), Trocknen (4) und Auto (11). 0x2e ist unbekannt und wird übergangen.
    private const COMFEE_A1_SILENT = 'b50410020107170201022e02010114020107797f';

    // Midea U-Shaped Window Unit (2022), aus den Tests von msmart-ng (test_capabilities_4).
    // Der Eintrag 0x0225 trägt 7 Bytes, danach folgt noch 0x0224 (Display).
    private const AC_U_SHAPED = 'b50912020102130201001402010015020100170201021a02010010020101250207203c203c203c00240201010102a1a0';

    // Klimagerät aus mill1000/midea-ac-py#425, aus den Tests von msmart-ng.
    // Neben 0x0225 (7 Bytes) steht 0x00e3 mit 2 Bytes.
    private const AC_ISSUE_425 = 'b51012020100180001001402010115020101160201041a020101100201011f020103250207203c203c203c0551000101e30002080867000102c200010098000101950001019d00010101008b43';

    private MideaAir $module;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
        $this->module = IPS\InstanceManager::getInstanceInterface(IPS_CreateInstance(self::MODULE));
    }

    public function testComfeeDehumidifier(): void
    {
        $this->assertEquals([
            'fan_speed'       => true,
            'fan_speed_value' => 7,
            'humidity'        => true,
            'dry_clothes'     => true,
        ], $this->parse(self::COMFEE_A1, MideaAir::DEVICE_DEHUMIDIFIER));
    }

    public function testComfeeDehumidifierWithModeValue(): void
    {
        $this->assertEquals([
            'fan_speed'       => true,
            'fan_speed_value' => 7,
            'filter'          => true,
            'mode'            => true,
            'mode_value'      => 7,
        ], $this->parse(self::COMFEE_A1_SILENT, MideaAir::DEVICE_DEHUMIDIFIER));
    }

    public function testEntryAfterSevenByteValueIsRead(): void
    {
        $this->assertEquals([
            'eco'             => true,
            'heat_8'          => false,
            'mode'            => false,
            'mode_value'      => 0,
            'fan_swing'       => false,
            'filter_reminder' => true,
            'strong_fan'      => false,
            'fan_speed'       => true,
            'fan_speed_value' => 1,
            'screen_display'  => true,
        ], $this->parse(self::AC_U_SHAPED, MideaAir::DEVICE_AIRCON));
    }

    public function testEntriesWithTwoAndSevenByteValues(): void
    {
        $this->assertEquals([
            'eco'             => false,
            'mode'            => true,
            'mode_value'      => 1,
            'fan_swing'       => true,
            'electricity'     => true,
            'strong_fan'      => true,
            'fan_speed'       => true,
            'fan_speed_value' => 1,
            'humidity'        => true,
        ], $this->parse(self::AC_ISSUE_425, MideaAir::DEVICE_AIRCON));
    }

    private function parse(string $hex, string $deviceType): array
    {
        return (new ReflectionMethod($this->module, 'parseB5Response'))->invoke($this->module, hex2bin($hex), $deviceType);
    }
}
