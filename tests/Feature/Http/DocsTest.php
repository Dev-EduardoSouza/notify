<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Docs\ApiDocumentation;
use Tests\TestCase;

final class DocsTest extends TestCase
{
    public function testDocsAreServedWhenEnabled(): void
    {
        config(['notifications.docs_enabled' => true]);

        $this->get('/docs')->assertOk()->assertSee('elements-api', false);
        $spec = $this->getJson('/docs/openapi.json')->assertOk();

        $spec->assertJsonPath('components.securitySchemes.bearerAuth.scheme', 'bearer');
        $spec->assertJsonPath('paths./health.get.security', []);
        self::assertSame(
            ['201', '202', '400', '401', '403', '413', '422', '429', '502'],
            collect(array_keys($spec->json('paths./v1/notifications.post.responses')))->map(fn ($code): string => (string) $code)->sort()->values()->all(),
        );
    }

    public function testPortugueseDocsAreFullyTranslated(): void
    {
        config(['notifications.docs_enabled' => true]);

        $this->get('/docs/pt')->assertOk()->assertSee('<title>Serviço de Notificações</title>', false);
        $spec = $this->getJson('/docs/pt/openapi.json')->assertOk();

        $spec->assertJsonPath('info.title', 'Serviço de Notificações');
        $spec->assertJsonPath('paths./v1/notifications.post.summary', 'Enviar uma notificação');
        $spec->assertJsonPath('paths./v1/notifications/{id}.get.responses.404.description', 'Não encontrada ou criada por outra chave.');

        $english = array_keys(ApiDocumentation::PORTUGUESE);
        foreach ($english as $text) {
            self::assertStringNotContainsString((string) json_encode($text), (string) $spec->getContent());
        }
        $this->getJson('/docs/openapi.json')->assertJsonPath('paths./v1/notifications.post.summary', 'Send a notification');
    }

    public function testDocsAreHiddenWhenDisabled(): void
    {
        config(['notifications.docs_enabled' => false]);

        $this->get('/docs')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->get('/docs/openapi.json')->assertNotFound();
        $this->get('/docs/pt')->assertNotFound();
        $this->get('/docs/pt/openapi.json')->assertNotFound();
    }
}
