<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

final readonly class AgentEmailBody
{
    public function __construct(private EmailTemplateRenderService $renderer) {}

    public function forDraft(string $markdown, ConnectedAccount $account, bool $includeSignature): string
    {
        $signature = $includeSignature
            ? EmailSignature::query()->defaultFor((string) $account->getKey())->first()
            : null;

        return $this->renderer->applySignatureBlock($this->html($markdown), $signature);
    }

    public function forSending(string $markdown, ConnectedAccount $account, bool $includeSignature): string
    {
        return $this->renderer->renderForSending($this->forDraft($markdown, $account, $includeSignature));
    }

    private function html(string $markdown): string
    {
        return trim(Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br />\n"],
        ]));
    }
}
