<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class LearningMaterial extends Model
{
    use HasAcademicTerm;

    public const PRIVATE_DISK = 'learning-materials';

    protected $table = 'tbl_learning_materials';

    // Task 32: the tbl_learning_material_subject pivot is the truth — one uploaded file may be
    // assigned to several subjects, and that is what the upload form syncs. The subject_id /
    // section_id columns are only the creation-time primary and must NOT drive visibility or
    // filtering; reading the scalar instead of the relation is exactly what broke the term filter
    // (it read tbl_sections.academic_term_id while inActiveTerm read tbl_sections_term).
    protected string $academicTermPath = 'subjects.section.terms';

    // ...which only holds if the pivot is never empty. Materials are also created by seeders and
    // tests that set subject_id and nothing else, so mirror the primary into the pivot on every
    // save. Callers that then sync() an explicit subject list still win — store() passes the first
    // selected subject as the primary, so it survives the sync.
    protected static function booted(): void
    {
        static::saved(function (self $material): void {
            if ($material->subject_id) {
                $material->subjects()->syncWithoutDetaching([
                    $material->subject_id => ['section_id' => $material->section_id],
                ]);
            }
        });
    }

    // D2: educator ownership / student enrollment (active material only). No admin
    // policy in source. Admins excluded.
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Task 31: deactivating a term hides its records from educators and students alike.
        $query->inActiveTerm();

        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        if ($user->hasRole('student')) {
            // Task 32: enrollment is matched against the pivot, so a shared file reaches every
            // subject it was assigned to — not just the one that happens to be the primary.
            return $query->where($this->qualifyColumn('is_active'), true)
                ->whereExists(fn ($q) => $q->selectRaw('1')
                    ->from('tbl_enrolled')
                    ->join('tbl_learning_material_subject', 'tbl_learning_material_subject.subject_id', '=', 'tbl_enrolled.subject_id')
                    ->whereColumn('tbl_enrolled.educator_id', 'tbl_learning_materials.educator_id')
                    ->whereColumn('tbl_learning_material_subject.material_id', 'tbl_learning_materials.id')
                    ->where('tbl_enrolled.student_id', $user->id)
                    ->where('tbl_enrolled.is_active', true));
        }

        return $query->whereRaw('1 = 0');
    }

    protected $fillable = [
        'educator_id', 'subject_id', 'section_id', 'storage_bucket', 'storage_path',
        'file_name', 'file_extension', 'mime_type', 'file_size', 'is_active',
    ];

    protected $casts = ['file_size' => 'integer', 'is_active' => 'boolean'];

    public function storageDisk(): string
    {
        return $this->storage_bucket ?: self::PRIVATE_DISK;
    }

    public function readableStorageDisk(): ?string
    {
        foreach (array_unique([$this->storageDisk(), self::PRIVATE_DISK, 'local']) as $disk) {
            if (Storage::disk($disk)->exists($this->storage_path)) {
                return $disk;
            }
        }

        return null;
    }

    public function educator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'educator_id');
    }

    // The creation-time primary. Kept for sort joins and as the mirror source; read subjects()
    // when you need "which subjects is this file assigned to".
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    // Task 32: every subject this one file is shared with.
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'tbl_learning_material_subject', 'material_id', 'subject_id')
            ->withPivot('section_id')
            ->withTimestamps();
    }
}
