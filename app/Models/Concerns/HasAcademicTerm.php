<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Task 31: shared "is this record's academic term still active?" test.
 *
 * Deactivating a term hides everything filed under it, but the rows are still there. Two callers
 * need to ask the question separately from visibleTo():
 *  - list queries, which want the filter inline;
 *  - the educator redirect guard in bootstrap/app.php, which needs to tell "hidden because the
 *    term is inactive" apart from "hidden because you do not own it" so the first can produce a
 *    notice instead of a 403.
 *
 * Implementors set $academicTermPath to the relation path that reaches AcademicTerm, or override
 * scopeInActiveTerm() outright when one path is not enough (see Assessment).
 */
trait HasAcademicTerm
{
    public function scopeInActiveTerm(Builder $query): Builder
    {
        return $query->whereHas($this->academicTermPath, fn ($term) => $term->where('is_active', true));
    }

    /**
     * withoutGlobalScopes: this asks one narrow question about THIS row — is its term active? —
     * so it must not be answered "no" merely because the row is soft-deleted (Score and Quiz both
     * soft-delete, and archived scores are restorable while their term is live).
     */
    public function termIsActive(): bool
    {
        return static::query()->withoutGlobalScopes()->inActiveTerm()->whereKey($this->getKey())->exists();
    }
}
