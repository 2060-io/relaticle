<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class RecipientSuggestionService
{
    private const int RECENT_EMAIL_WINDOW = 1000;

    /**
     * Addresses the viewer may reuse in compose autocomplete, most recent first.
     *
     * Restricted to mail VisibleEmailScope would list, excluding drafts and
     * other people's BCC rows. Without those gates, private, protected,
     * internal, and BCC addresses leak into teammates' To/Cc/Bcc suggestions.
     *
     * @return list<string>
     */
    public function addressesFor(User $user, int $limit = 300): array
    {
        $newestEmailIds = Email::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->limit(self::RECENT_EMAIL_WINDOW)
            ->select('id');

        $recentVisibleEmails = Email::query()
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->whereIn('id', $newestEmailIds)
            ->where('status', '!=', EmailStatus::DRAFT)
            ->select(['id', 'user_id', 'sent_at']);

        /** @var list<string> */
        return EmailParticipant::query()
            ->joinSub($recentVisibleEmails, 'recent_emails', 'recent_emails.id', '=', 'email_participants.email_id')
            ->where(fn (Builder $participantQuery): Builder => $participantQuery
                ->where('email_participants.role', '!=', EmailParticipantRole::BCC)
                ->orWhere('recent_emails.user_id', $user->getKey()))
            ->whereNotNull('email_participants.email_address')
            ->groupBy('email_participants.email_address')
            ->orderByRaw('max(recent_emails.sent_at) desc, email_participants.email_address')
            ->limit($limit)
            ->pluck('email_participants.email_address')
            ->all();
    }
}
