<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\User;
use Database\Factories\EmailBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $connected_account_id
 * @property string $subject
 * @property int $total_recipients
 * @property int $sent_count
 * @property int $failed_count
 * @property EmailBatchStatus $status
 */
#[Fillable([
    'workspace_id',
    'user_id',
    'connected_account_id',
    'subject',
    'total_recipients',
    'sent_count',
    'failed_count',
    'status',
])]
final class EmailBatch extends Model
{
    /**
     * @use HasFactory<EmailBatchFactory>
     */
    use HasFactory;

    use HasUlids, HasWorkspace;

    protected static function newFactory(): EmailBatchFactory
    {
        return EmailBatchFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => EmailBatchStatus::class,
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /**
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'batch_id');
    }
}
