<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Messages\WhatsAppPacing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WhatsAppPacingTest extends TestCase
{
    use RefreshDatabase;

    public function testFormulaGrowsEveryFortyMessagesUpToNinety(): void
    {
        $expected = [0 => 3, 39 => 3, 40 => 6, 79 => 6, 80 => 12, 119 => 12, 120 => 24, 159 => 24, 160 => 48, 199 => 48, 200 => 90, 500 => 90];

        foreach ($expected as $sequencia => $seconds) {
            self::assertSame($seconds, WhatsAppPacing::secondsFor($sequencia), "sequencia {$sequencia}");
        }
    }

    public function testTwoHundredFiftyMessagesWalkThroughEveryStep(): void
    {
        $now = CarbonImmutable::parse('2026-11-04 12:00:00');
        $pacing = WhatsAppPacing::current();
        $seen = [];

        for ($i = 0; $i < 250; $i++) {
            $seen[$pacing->delaySeconds($now, 30)] = true;
            $pacing->registerSent($now, 30);
            $now = $now->addSeconds(3);
        }

        self::assertSame([3, 6, 12, 24, 48, 90], array_keys($seen));
        self::assertSame(250, WhatsAppPacing::current()->sequencia);
    }

    public function testSequenceResetsAfterIdleMinutes(): void
    {
        $now = CarbonImmutable::parse('2026-11-04 12:00:00');
        $pacing = WhatsAppPacing::current();
        $pacing->update(['sequencia' => 85, 'ultimo_envio' => $now->subMinutes(29)]);
        self::assertSame(12, $pacing->delaySeconds($now, 30));

        $pacing->update(['sequencia' => 85, 'ultimo_envio' => $now->subMinutes(30)]);
        self::assertSame(3, $pacing->delaySeconds($now, 30));
    }

    public function testRegisterSentIncrementsAndRestartsAfterIdle(): void
    {
        $now = CarbonImmutable::parse('2026-11-04 12:00:00');
        $pacing = WhatsAppPacing::current();

        $pacing->registerSent($now, 30);
        $pacing->registerSent($now->addSeconds(3), 30);
        self::assertSame(2, WhatsAppPacing::current()->sequencia);

        $pacing->registerSent($now->addHour(), 30);
        self::assertSame(1, WhatsAppPacing::current()->sequencia);
    }
}
