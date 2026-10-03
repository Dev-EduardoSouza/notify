<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function testHealthReturnsOnlyStatusOk(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function testUnknownRouteReturnsJson404(): void
    {
        $this->get('/nope')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
