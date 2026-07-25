{{-- Educator shell: Metronic light-sidebar layout + educator nav (icons per item). --}}
@php
    $navItems = [
        ['heading' => 'Educator'],
        ['label' => 'Dashboard',   'url' => route('educator.dashboard'),         'active' => request()->routeIs('educator.dashboard'),                                    'icon' => 'element-11'],
        ['label' => 'Calendar',    'url' => route('educator.calendar'),          'active' => request()->routeIs('educator.calendar'),                                     'icon' => 'calendar'],
        ['heading' => 'Classroom'],
        ['label' => 'Sections',    'url' => route('educator.sections.index'),    'active' => request()->routeIs('educator.sections.*'),                                   'icon' => 'abstract-26'],
        ['label' => 'Subjects',    'url' => route('educator.subjects.index'),    'active' => request()->routeIs('educator.subjects.*'),                                   'icon' => 'book'],
        ['label' => 'Enrollment',  'url' => route('educator.enrollment.index'),  'active' => request()->routeIs('educator.enrollment.*'),                                 'icon' => 'people'],
        ['heading' => 'Assessment'],
        ['label' => 'Assessments', 'url' => route('educator.assessments.index'), 'active' => request()->routeIs('educator.assessments.*'),                                'icon' => 'questionnaire-tablet'],
        [
            'label' => 'Question Bank',
            'active' => request()->routeIs('educator.quizzes.*'),
            'icon' => 'note-2',
            'children' => [
                ['label' => 'Question Bank', 'url' => route('educator.quizzes.index'), 'active' => request()->routeIs('educator.quizzes.index', 'educator.quizzes.create', 'educator.quizzes.store', 'educator.quizzes.edit', 'educator.quizzes.update', 'educator.quizzes.destroy', 'educator.quizzes.bulk', 'educator.quizzes.upload', 'educator.quizzes.upload.template', 'educator.quizzes.archive')],
                ['label' => 'Archived Questions', 'url' => route('educator.quizzes.archived'), 'active' => request()->routeIs('educator.quizzes.archived', 'educator.quizzes.archived.restore')],
            ],
        ],
        [
            'label' => 'Scores',
            'active' => request()->routeIs('educator.scores.*'),
            'icon' => 'chart-simple',
            'children' => [
                ['label' => 'Scores', 'url' => route('educator.scores.index'), 'active' => request()->routeIs('educator.scores.index', 'educator.scores.show', 'educator.scores.matrix', 'educator.scores.student')],
                ['label' => 'Archived Scores', 'url' => route('educator.scores.deleted'), 'active' => request()->routeIs('educator.scores.deleted')],
            ],
        ],
        ['heading' => 'Resources'],
        ['label' => 'Materials',   'url' => route('educator.materials.index'),   'active' => request()->routeIs('educator.materials.*'),                                  'icon' => 'folder'],
        ['label' => 'Announcements', 'url' => route('educator.announcements.index'), 'active' => request()->routeIs('educator.announcements.*'), 'icon' => 'notification-status'],

    ];
@endphp

@extends('layouts.app', ['role' => 'educator', 'navItems' => $navItems])
