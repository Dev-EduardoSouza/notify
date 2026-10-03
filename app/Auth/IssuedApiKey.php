<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\ApiKey;

final class IssuedApiKey
{
    public function __construct(
        public readonly ApiKey $model,
        public readonly string $plainKey,
    ) {
    }
}
