<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Database\Factories\ProtectedRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $created_by
 * @property User|null $creator
 */
#[Fillable([
    'workspace_id',
    'type',
    'value',
    'created_by',
])]
final class ProtectedRecipient extends Model
{
    /**
     * @use HasFactory<ProtectedRecipientFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): ProtectedRecipientFactory
    {
        return ProtectedRecipientFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
