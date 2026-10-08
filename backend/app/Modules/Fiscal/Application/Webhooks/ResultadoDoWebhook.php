<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;

/** O segredo só existe aqui, na resposta da criação. */
final readonly class ResultadoDoWebhook
{
    public function __construct(public WebhookEndpoint $modelo, public string $segredo) {}
}
