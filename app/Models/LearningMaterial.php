<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LearningMaterial extends Model
{
    use HasAcademicTerm;

    public const PRIVATE_DISK = 'learning-materials';

    protected $table = 'tbl_learning_materials';

    protected string $academicTermPath = 'subject.section.terms';

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
            return $query->where($this->qualifyColumn('is_active'), true)
                ->whereExists(fn ($q) => $q->selectRaw('1')
                    ->from('tbl_enrolled')
                    ->whereColumn('tbl_enrolled.educator_id', 'tbl_learning_materials.educator_id')
                    ->whereColumn('tbl_enrolled.subject_id', 'tbl_learning_materials.subject_id')
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
