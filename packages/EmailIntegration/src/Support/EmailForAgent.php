<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class EmailForAgent
{
    private const int BODY_LIMIT = 20_000;

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
                ->filter(fn (EmailParticipant $participant): bool => $this->isListed($participant->role, $tier, $ownsMailbox))
                ->map(fn (EmailParticipant $participant): array => [
                    'role' => $participant->role->value,
                    'name' => $participant->name,
                    'email' => $participant->email_address,
                ])
                ->all()),
        ];
    }

    /** @return array<string, mixed>|null */
    public function detail(Email $email, User $viewer): ?array
    {
        $summary = $this->summary($email, $viewer);

        if ($summary === null) {
            return null;
        }

        $showsBody = EmailPrivacyTier::from($summary['access'])->showsBody();
        $body = $showsBody ? $this->bodyText($email) : null;

        return [
            ...$summary,
            'body_text' => $body === null ? null : mb_substr($body, 0, self::BODY_LIMIT),
            'body_truncated' => $body !== null && mb_strlen($body) > self::BODY_LIMIT,
            'attachments' => $showsBody
                ? $email->downloadAttachments()
                    ->map(fn (EmailAttachment $attachment): array => [
                        'filename' => $attachment->filename,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function isListed(EmailParticipantRole $role, EmailPrivacyTier $tier, bool $ownsMailbox): bool
    {
        return match ($role) {
            EmailParticipantRole::BCC => $ownsMailbox,
            EmailParticipantRole::CC => $tier->showsBody(),
            default => true,
        };
    }

    private function bodyText(Email $email): ?string
    {
        if ($email->body === null) {
            return null;
        }

        if (filled($email->body->body_text)) {
            return $email->body->body_text;
        }

        return $this->textFromHtml((string) $email->body->body_html);
    }

    private function textFromHtml(string $html): string
    {
        $text = Str::of($html)
            ->replaceMatches('#<(style|script)\b[^>]*>.*?</\1>#is', '')
            ->replaceMatches('#<br\s*/?>|</(?:p|div|li|tr|h[1-6])>#i', "\n")
            ->stripTags()
            ->pipe(fn (string $stripped): string => html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replaceMatches('/[ \t]+/', ' ');

        $lines = $text
            ->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->implode("\n");

        return Str::of($lines)
            ->replaceMatches('/\n{3,}/', "\n\n")
            ->trim()
            ->toString();
    }
}
