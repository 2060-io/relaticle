<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\User;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\EmailIntegration\Policies\EmailTemplatePolicy;

/**
 * @property string|null $created_by
 * @property bool $is_shared
 * @property User|null $creator
 */
#[UsePolicy(EmailTemplatePolicy::class)]
#[Fillable([
    'workspace_id',
    'created_by',
    'name',
    'subject',
    'body_html',
    'variables',
    'is_shared',
])]
final class EmailTemplate extends Model
{
    /**
     * @use HasFactory<EmailTemplateFactory>
     */
    use HasFactory, HasUlids, HasWorkspace, SoftDeletes;

    protected static function newFactory(): EmailTemplateFactory
    {
        return EmailTemplateFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_shared' => 'boolean',
        ];
    }
}
