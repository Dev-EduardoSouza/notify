<?php

declare(strict_types=1);

namespace App\Docs;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use SplObjectStorage;

final class ApiDocumentation
{
    public const ENGLISH_DESCRIPTION = 'Sends notifications from client apps through configured channels (Telegram today). Authenticate with `Authorization: Bearer <api key>`; keys are created with `php artisan keys:create`. Errors always return `{"code", "message"}`.'
        . "\n\nDocumentação em português: [/docs/pt](/docs/pt)";

    public const PORTUGUESE_DESCRIPTION = 'Envia notificações dos apps clientes pelos canais configurados (hoje, Telegram). Autentique com `Authorization: Bearer <chave de API>`; as chaves são criadas com `php artisan keys:create`. Erros sempre retornam `{"code", "message"}`.'
        . "\n\nEnglish version: [/docs](/docs)";

    public const PORTUGUESE = [
        'Health check' => 'Verificação de saúde',
        'Public endpoint used by uptime monitors. Requires no authentication.' => 'Endpoint público usado por monitores de disponibilidade. Não exige autenticação.',
        'Service is up.' => 'Serviço no ar.',
        'Send a notification' => 'Enviar uma notificação',
        'Delivers the text synchronously through the chosen channel. Long texts are split into several messages. Temporary failures (rate limit, channel outage) return 202 and are retried automatically.' => 'Entrega o texto de forma síncrona pelo canal escolhido. Textos longos são divididos em várias mensagens. Falhas temporárias (limite do canal, canal fora do ar) retornam 202 e são reenviadas automaticamente.',
        'Optional. Repeating the same key with the same body returns the original notification without sending again; a different body returns 422.' => 'Opcional. Repetir a mesma chave com o mesmo corpo devolve a notificação original sem reenviar; com outro corpo, retorna 422.',
        'Recipient alias allowed for this API key.' => 'Alias do destinatário permitido para esta chave de API.',
        'Delivery channel.' => 'Canal de entrega.',
        'Message text, up to 20000 characters.' => 'Texto da mensagem, até 20000 caracteres.',
        'plain (default) or html (Telegram HTML subset).' => 'plain (padrão) ou html (subconjunto de HTML do Telegram).',
        'Delivered.' => 'Entregue.',
        'Accepted; delivery will be retried automatically.' => 'Aceita; a entrega será tentada de novo automaticamente.',
        'Body is not a JSON object.' => 'O corpo não é um objeto JSON.',
        'Missing or invalid API key.' => 'Chave de API ausente ou inválida.',
        'Recipient not allowed for this API key.' => 'Destinatário não permitido para esta chave de API.',
        'Body too large.' => 'Corpo grande demais.',
        'Invalid body, unknown recipient, unsupported format or Idempotency-Key conflict.' => 'Corpo inválido, destinatário desconhecido, formato não suportado ou conflito de Idempotency-Key.',
        'Rate limit exceeded; see Retry-After.' => 'Limite de requisições excedido; veja Retry-After.',
        'The channel rejected the notification permanently.' => 'O canal recusou a notificação de forma definitiva.',
        'Get a notification' => 'Consultar uma notificação',
        'Returns the delivery status. Only the API key that created the notification can read it.' => 'Retorna o status da entrega. Só a chave de API que criou a notificação pode consultá-la.',
        'Current delivery status.' => 'Status atual da entrega.',
        'Not found or created by another key.' => 'Não encontrada ou criada por outra chave.',
    ];

    private const TRANSLATABLE = ['summary', 'description', 'title'];

    public static function register(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => $route->uri() === 'health' || str_starts_with($route->uri(), 'v1/'))
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                self::secure($openApi);
                $openApi->info->setDescription(self::ENGLISH_DESCRIPTION);
            });

        $config = (array) config('scramble');
        Scramble::registerApi('pt', [
            'info' => ['version' => $config['info']['version'] ?? '1.0.0', 'description' => self::PORTUGUESE_DESCRIPTION],
            'ui' => array_merge((array) ($config['ui'] ?? []), ['title' => 'Serviço de Notificações']),
        ])
            ->routes(fn (Route $route): bool => $route->uri() === 'health' || str_starts_with($route->uri(), 'v1/'))
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                self::secure($openApi);
                $openApi->info->title = 'Serviço de Notificações';
                $openApi->info->setDescription(self::PORTUGUESE_DESCRIPTION);
                self::translate($openApi, new SplObjectStorage());
            });

        Scramble::registerUiRoute('docs');
        Scramble::registerJsonSpecificationRoute('docs/openapi.json');
        Scramble::registerUiRoute('docs/pt', 'pt');
        Scramble::registerJsonSpecificationRoute('docs/pt/openapi.json', 'pt');
    }

    private static function secure(OpenApi $openApi): void
    {
        $openApi->secure(SecurityScheme::http('bearer')->as('bearerAuth'));
        foreach ($openApi->paths as $path) {
            if ($path->path === 'health') {
                foreach ($path->operations as $operation) {
                    $operation->security = [];
                }
            }
        }
    }

    /** @param SplObjectStorage<object, null> $visited */
    private static function translate(mixed $node, SplObjectStorage $visited): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                self::translate($child, $visited);
            }

            return;
        }

        if (!is_object($node) || $visited->contains($node)) {
            return;
        }
        $visited->attach($node);

        foreach (get_object_vars($node) as $property => $value) {
            if (is_string($value) && in_array($property, self::TRANSLATABLE, true)) {
                $node->{$property} = self::PORTUGUESE[$value] ?? $value;
            } elseif (is_array($value) || is_object($value)) {
                self::translate($value, $visited);
            }
        }
    }
}
