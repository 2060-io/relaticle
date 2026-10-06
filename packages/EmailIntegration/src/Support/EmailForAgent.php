<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class EmailForAgent
{
    public function __construct(private PrivacyService $privacy) {}

    /**
     * @return array{
     *     id: string,
     *     thread_id: ?string,
     *     direction: string,
     *     sent_at: ?string,
     *     access: string,
     *     subject: ?string,
     *     snippet: ?string,
     *     has_attachments: bool,
     *     participants: list<array{role: string, name: ?string, email: string}>
     * }|null
     */
    public function summary(Email $email, User $viewer): ?array
    {
        $tier = $this->privacy->effectiveTier($email, $viewer);

        if (! $tier instanceof EmailPrivacyTier) {
            return null;
        }

        $ownsMailbox = $email->user_id === $viewer->getKey();

        return [
            'id' => (string) $email->getKey(),
            'thread_id' => $email->thread_id,
            'direction' => $email->direction->value,
            'sent_at' => $email->sent_at?->toIso8601String(),
            'access' => $tier->value,
            'subject' => $tier->showsSubject() ? $email->subject : null,
            'snippet' => $tier->showsBody() ? $email->snippet : null,
            'has_attachments' => (bool) $email->has_attachments,
            'participants' => array_values($email->participants
                ->filter(fn (EmailParticipant $participant): bool => $ownsMailbox || $participant->role !== EmailParticipantRole::BCC)
                ->map(fn (EmailParticipant $participant): array => [
                    'role' => $participant->role->value,
                    'name' => $participant->name,
                    'email' => $participant->email_address,
                ])
                ->all()),
        ];
    }
}
