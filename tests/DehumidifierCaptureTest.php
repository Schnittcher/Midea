<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prüft Setzbefehl, Antwort-Parser und Übernahme der Antwort an Mitschnitten zweier Comfee-Entfeuchter.
 *
 * Die Fixtures unter tests/fixtures entstanden am 23.10.2026: Jedes Gerät wurde durch alle Modi und
 * Lüfterstufen geschaltet, je Schritt mit Setzbefehl, Antwort darauf und anschließender Statusabfrage.
 * Beobachtet: Im Modus Trocknen (4) stellen beide Geräte den Lüfter selbst auf 80. Modi, die der
 * MDDF-20DENEN7-WF nicht kennt (6, 7, 10, 11), beantwortet er mit Trocknen.
 */
class DehumidifierCaptureTest extends TestCase
{
    private const MODULE = '{B4F7E2A9-8C3D-4F6B-9E1A-2D5C7F8B3E4A}';

    private const FIXTURES = ['comfee_mddf-20denen7-wf.json', 'comfee_cddke-20den7-eu.json'];

    public static function steps(): array
    {
        $cases = [];
        foreach (self::FIXTURES as $file) {
            foreach (self::fixture($file)['schritte'] as $nr => $step) {
                $cases[$file . ' Schritt ' . ($nr + 1)] = [$step];
            }
        }
        return $cases;
    }

    public static function idleStates(): array
    {
        $cases = [];
        foreach (self::FIXTURES as $file) {
            $cases[$file] = [self::fixture($file)['leerlauf']];
        }
        return $cases;
    }

    public static function unknownModeSteps(): array
    {
        $cases = [];
        foreach (self::fixture('comfee_mddf-20denen7-wf.json')['unbekannte_modi']['schritte'] as $step) {
            $cases['Modus ' . $step['state']['mode'] . ' Lüfter ' . $step['state']['fanSpeed']] = [$step];
        }
        return $cases;
    }

    #[DataProvider('steps')]
    #[DataProvider('unknownModeSteps')]
    public function testSetCommandMatchesCapture(array $step): void
    {
        // Ohne Sequenznummer (Byte 30) und die beiden Prüfbytes dahinter
        $this->assertSame(
            substr($step['befehl'], 0, 60),
            substr(bin2hex(MideaCommands::dehumidifierSetCommand($step['state'])), 0, 60)
        );
    }

    #[DataProvider('steps')]
    public function testResponsesReportRequestedState(array $step): void
    {
        $state = $step['state'];
        $fanSpeed = $state['running'] && $state['mode'] === 4 ? 80 : $state['fanSpeed'];
        foreach (['antwort', 'status'] as $frame) {
            $response = new DehumidifierResponse(hex2bin($step[$frame]));
            $this->assertSame($state['running'], $response->runStatus, $frame);
            $this->assertSame($state['mode'], $response->mode, $frame);
            $this->assertSame($fanSpeed, $response->fanSpeed, $frame);
            $this->assertSame((float) $state['targetHumidity'], $response->targetHumidity, $frame);
        }
    }

    #[DataProvider('unknownModeSteps')]
    public function testUnknownModeIsAnsweredWithDry(array $step): void
    {
        foreach (['antwort', 'status'] as $frame) {
            $response = new DehumidifierResponse(hex2bin($step[$frame]));
            $this->assertTrue($response->runStatus, $frame);
            $this->assertSame(4, $response->mode, $frame);
            $this->assertSame(80, $response->fanSpeed, $frame);
            $this->assertSame((float) $step['state']['targetHumidity'], $response->targetHumidity, $frame);
        }
    }

    #[DataProvider('idleStates')]
    public function testStatusMatchesModuleDisplay(array $idle): void
    {
        $response = new DehumidifierResponse(hex2bin($idle['status']));

        $this->assertSame($idle['running'], $response->runStatus);
        $this->assertSame($idle['mode'], $response->mode);
        $this->assertSame($idle['fanSpeed'], $response->fanSpeed);
        $this->assertSame((float) $idle['targetHumidity'], $response->targetHumidity);
        $this->assertSame((float) $idle['currentHumidity'], $response->currentHumidity);
        $this->assertSame((float) $idle['indoorTemperature'], $response->indoorTemperature);
    }

    public function testModuleTakesFanSpeedFromResponse(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $id = IPS_CreateInstance(self::MODULE);
        $module = IPS\InstanceManager::getInstanceInterface($id);
        (new ReflectionMethod($module, 'RegisterDehumidifierVariables'))->invoke($module);

        // Trocknen mit Lüfter 40 angefordert, das Gerät antwortet mit 80
        $step = self::fixture('comfee_cddke-20den7-eu.json')['schritte'][0];
        $this->assertSame(['running' => true, 'mode' => 4, 'fanSpeed' => 40, 'targetHumidity' => 50], $step['state']);
        (new ReflectionMethod($module, 'applyDehumidifierState'))->invoke($module, new DehumidifierResponse(hex2bin($step['antwort'])));

        $this->assertSame(4, GetValue(IPS_GetObjectIDByIdent('Mode', $id)));
        $this->assertSame(80, GetValue(IPS_GetObjectIDByIdent('FanSpeed', $id)));
    }

    private static function fixture(string $file): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $file), true);
    }
}
