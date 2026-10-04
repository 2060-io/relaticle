<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Database\Factories\WorkspaceEmailBlocklistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;

/**
 * @property string $workspace_id
 * @property EmailBlocklistType $type
 * @property EmailVisibilityEnforcement $enforcement_level
 * @property string $value
 * @property bool $include_subdomains
 * @property string|null $created_by
 * @property User|null $creator
 */
#[Fillable([
    'workspace_id',
    'type',
    'value',
    'enforcement_level',
    'include_subdomains',
    'created_by',
])]
#[Table(name: 'workspace_email_blocklists')]
final class WorkspaceEmailBlocklist extends Model
{
    /**
     * @use HasFactory<WorkspaceEmailBlocklistFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): WorkspaceEmailBlocklistFactory
    {
        return WorkspaceEmailBlocklistFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => EmailBlocklistType::class,
            'enforcement_level' => EmailVisibilityEnforcement::class,
            'include_subdomains' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
