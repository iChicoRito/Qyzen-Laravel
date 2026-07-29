<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use HasAcademicTerm;

    protected $table = 'tbl_sections';

    protected string $academicTermPath = 'academicTerm';

    // D2: admin all / educator ownership (perm 'sections:view' gated at Policy) /
    // student enrollment via a subject in this section.
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        // Task 31: deactivating a term hides its records from educators and students alike.
        $query->inActiveTerm();

        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        return $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('tbl_enrolled')
            ->join('tbl_subjects', 'tbl_subjects.id', '=', 'tbl_enrolled.subject_id')
            ->whereColumn('tbl_enrolled.educator_id', 'tbl_sections.educator_id')
            ->whereColumn('tbl_subjects.sections_id', 'tbl_sections.id')
            ->where('tbl_enrolled.student_id', $user->id)
            ->where('tbl_enrolled.is_active', true));
    }

    protected $fillable = ['educator_id', 'academic_term_id', 'section_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function educator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'educator_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function terms(): BelongsToMany
    {
        return $this->belongsToMany(AcademicTerm::class, 'tbl_sections_term', 'section_id', 'academic_term_id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'sections_id');
    }
}
