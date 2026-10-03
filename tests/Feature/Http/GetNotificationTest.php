<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ApiKeyManager;
use App\Models\Delivery;
use App\Notifications\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GetNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function testOwnerSeesItsDeliveryAndOthersGet404(): void
    {
        config(['notifications.channels.telegram.recipients' => 'eduardo:1']);
        $keys = $this->app->make(ApiKeyManager::class);
        $owner = $keys->issue('Owner', ['eduardo']);
        $other = $keys->issue('Other', ['eduardo']);
        $delivery = Delivery::create([
            'client_ref' => $owner->model->clientRef(),
            'api_key_id' => $owner->model->id,
            'request_hash' => str_repeat('0', 64),
            'channel' => 'telegram',
            'recipient' => 'eduardo',
            'status' => DeliveryStatus::Sent,
            'attempts' => 1,
            'parts_total' => 1,
            'delivered_parts' => 1,
        ]);

        $this->getJson('/v1/notifications/' . $delivery->id, ['Authorization' => 'Bearer ' . $owner->plainKey])
            ->assertOk()
            ->assertJsonPath('id', $delivery->id)
            ->assertJsonPath('status', 'sent')
            ->assertJsonStructure(['id', 'status', 'attempts', 'created_at', 'updated_at', 'sent_at']);
        $this->getJson('/v1/notifications/' . strtoupper($delivery->id), ['Authorization' => 'Bearer ' . $owner->plainKey])->assertOk();

        foreach ([[$other->plainKey, $delivery->id], [$owner->plainKey, 'abc'], [$owner->plainKey, '01m3zzzzzzzzzzzzzzzzzzzzzz']] as [$key, $id]) {
            $this->getJson('/v1/notifications/' . $id, ['Authorization' => 'Bearer ' . $key])
                ->assertNotFound()
                ->assertExactJson(['code' => 'not_found', 'message' => 'Notification not found.']);
        }
    }
}
