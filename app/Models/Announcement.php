<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Announcement extends Model
{
    public const PRIVATE_DISK = 'announcement-images';

    protected $table = 'tbl_announcements';

    protected $fillable = [
        'educator_id', 'is_global', 'title', 'description', 'body', 'images', 'is_active',
    ];

    protected $casts = [
        'is_global' => 'boolean', 'images' => 'array', 'is_active' => 'boolean',
    ];

    public static function readableImageDisk(string $path): ?string
    {
        foreach ([self::PRIVATE_DISK, 'local'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Task 31: educator ownership does NOT depend on the term still being active — deactivating
        // a term must not turn historical records into 404s. The active-term gate is a
        // *current-workflow* filter and belongs only on the student's operational lists below.
        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        if (! $user->hasRole('student')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn('is_active'), true)->where(function (Builder $q) use ($user): void {
            $q->where(function (Builder $global) use ($user): void {
                $global->where('is_global', true)
                    ->whereExists(fn ($enrolled) => $enrolled->selectRaw('1')
                        ->from('tbl_enrolled')
                        ->join('tbl_subjects', 'tbl_subjects.id', '=', 'tbl_enrolled.subject_id')
                        ->join('tbl_sections', 'tbl_sections.id', '=', 'tbl_subjects.sections_id')
                        ->join('tbl_academic_term', 'tbl_academic_term.id', '=', 'tbl_sections.academic_term_id')
                        ->whereColumn('tbl_enrolled.educator_id', 'tbl_announcements.educator_id')
                        ->where('tbl_enrolled.student_id', $user->id)
                        ->where('tbl_enrolled.is_active', true)
                        ->where('tbl_academic_term.is_active', true));
            })->orWhere(function (Builder $subject) use ($user): void {
                // Task 29: enrolled in ANY of the announcement's target subjects. EXISTS (not a
                // join) so a student enrolled in two targeted subjects still sees it once.
                $subject->where('is_global', false)
                    ->whereHas('subjects.section.academicTerm', fn ($term) => $term->where('is_active', true))
                    ->whereExists(fn ($enrolled) => $enrolled->selectRaw('1')
                        ->from('tbl_enrolled')
                        ->join('tbl_announcement_subject', 'tbl_announcement_subject.subject_id', '=', 'tbl_enrolled.subject_id')
                        ->whereColumn('tbl_announcement_subject.announcement_id', 'tbl_announcements.id')
                        ->whereColumn('tbl_enrolled.educator_id', 'tbl_announcements.educator_id')
                        ->where('tbl_enrolled.student_id', $user->id)
                        ->where('tbl_enrolled.is_active', true));
            });
        });
    }

    public function educator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'educator_id');
    }

    // Task 29: an announcement targets zero (global) or many subjects.
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'tbl_announcement_subject', 'announcement_id', 'subject_id');
    }
}
