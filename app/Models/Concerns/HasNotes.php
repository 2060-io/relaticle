<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Note;
use App\Models\Pivots\Noteable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasNotes
{
    /**
     * @return MorphToMany<Note, $this, Noteable>
     */
    public function notes(): MorphToMany
    {
        return $this->morphToMany(Note::class, 'noteable')->using(Noteable::class);
    }
}
