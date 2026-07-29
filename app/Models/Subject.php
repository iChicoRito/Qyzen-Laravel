<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasAcademicTerm;

    protected $table = 'tbl_subjects';

    protected string $academicTermPath = 'section.terms';

    // D2: admin all / educator ownership (perm 'subjects:view' gated at Policy) /
    // student enrollment in this subject.
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        // Task 31: deactivating a term hides its records from educators and students alike. The
        // rows are not deleted — RedirectInactiveTermRecords turns a stale link to one of them into
        // a notice rather than a 403/404 for the owning educator.
        $query->inActiveTerm();

        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        return $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('tbl_enrolled')
            ->whereColumn('tbl_enrolled.educator_id', 'tbl_subjects.educator_id')
            ->whereColumn('tbl_enrolled.subject_id', 'tbl_subjects.id')
            ->where('tbl_enrolled.student_id', $user->id)
            ->where('tbl_enrolled.is_active', true));
    }

    protected $fillable = ['educator_id', 'sections_id', 'subject_code', 'subject_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function educator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'educator_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'sections_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrolled::class, 'subject_id');
    }
}
