<?php

declare(strict_types=1);

namespace App\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sequencia
 * @property CarbonImmutable|null $ultimo_envio
 */
final class WhatsAppPacing extends Model
{
    public const BASE_SECONDS = 3;
    public const MAX_SECONDS = 90;
    public const STEP = 40;

    public $timestamps = false;

    protected $table = 'whatsapp_pacing';

    protected $fillable = ['id', 'sequencia', 'ultimo_envio'];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1], ['sequencia' => 0]);
    }

    public static function secondsFor(int $sequencia): int
    {
        return min(self::MAX_SECONDS, self::BASE_SECONDS * (2 ** intdiv(max(0, $sequencia), self::STEP)));
    }

    public function delaySeconds(CarbonImmutable $now, int $idleResetMinutes): int
    {
        $this->resetIfIdle($now, $idleResetMinutes);

        return self::secondsFor($this->sequencia);
    }

    public function registerSent(CarbonImmutable $now, int $idleResetMinutes): void
    {
        $this->resetIfIdle($now, $idleResetMinutes);
        $this->sequencia++;
        $this->ultimo_envio = $now;
        $this->save();
    }

    private function resetIfIdle(CarbonImmutable $now, int $idleResetMinutes): void
    {
        if ($this->ultimo_envio !== null && $this->ultimo_envio->addMinutes($idleResetMinutes)->lessThanOrEqualTo($now)) {
            $this->sequencia = 0;
        }
    }

    protected function casts(): array
    {
        return [
            'sequencia' => 'integer',
            'ultimo_envio' => 'immutable_datetime',
        ];
    }
}
