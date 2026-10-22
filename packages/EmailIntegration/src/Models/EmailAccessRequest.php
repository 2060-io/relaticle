<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use App\Models\Workspace;
use Database\Factories\EmailAccessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;

/**
 * @property EmailAccessRequestStatus $status
 */
#[Fillable([
    'requester_id',
    'owner_id',
    'email_id',
    'emailable_type',
    'emailable_id',
    'tier_requested',
    'status',
    'workspace_id',
    'decided_at',
    'decided_by',
    'expires_at',
])]
final class EmailAccessRequest extends Model
{
    /**
     * @use HasFactory<EmailAccessRequestFactory>
     */
    use HasFactory, HasUlids;

    public const int EXPIRES_AFTER_DAYS = 30;

    protected static function newFactory(): EmailAccessRequestFactory
    {
        return EmailAccessRequestFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => EmailAccessRequestStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function pendingIncomingFor(Builder $query, User $owner): Builder
    {
        return $query
            ->where('owner_id', $owner->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('email', fn (Builder $email): Builder => $email->where('workspace_id', $owner->current_workspace_id));
    }

    public function decide(EmailAccessRequestStatus $status, User $actor): bool
    {
        $decidedAt = now();

        $decided = self::query()
            ->whereKey($this->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->where('expires_at', '>', $decidedAt)
            ->update([
                'status' => $status->value,
                'decided_at' => $decidedAt,
                'decided_by' => $actor->getKey(),
            ]);

        if ($decided === 0) {
            return false;
        }

        $this->forceFill([
            'status' => $status,
            'decided_at' => $decidedAt,
            'decided_by' => $actor->getKey(),
        ])->syncOriginal();

        return true;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * @return BelongsTo<Email, $this>
     */
    public function emailIncludingHidden(): BelongsTo
    {
        return $this->email()->withTrashed()->withoutGlobalScope(ActiveAccountScope::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function emailable(): MorphTo
    {
        return $this->morphTo();
    }
}
