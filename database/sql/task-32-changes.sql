-- =============================================================================
-- Task 32 — shared uploads for learning materials and question-bank questions
--
-- Equivalent to these migrations, for applying to a database where you cannot
-- run `php artisan migrate`:
--   2026_08_06_000001_create_learning_material_subject_table
--   2026_08_06_000002_create_quiz_subject_table
--
-- Target: MySQL 8 / MariaDB 10.6+, database `qyzen_v2`.
--
-- BACK UP FIRST:
--   mysqldump -u root qyzen_v2 > qyzen_v2-backup.sql
--
-- This file only CREATES tables and INSERTs into them. It drops no column and
-- deletes no row, so a material or question that exists today keeps behaving
-- exactly as it does today — it simply gains one pivot link to its own subject.
-- Only NEW uploads produce one row shared across several subjects.
--
-- Both INSERTs are guarded by NOT EXISTS on the pivot, so the whole file is
-- safe to run more than once.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Learning materials ↔ subjects.
--    One uploaded file used to be duplicated into one row per selected subject.
--    From Task 32 the file is ONE tbl_learning_materials row and this pivot
--    carries the fan-out. tbl_learning_materials.subject_id / section_id remain
--    as the creation-time primary and are mirrored in here on every save
--    (LearningMaterial::saved()), exactly like tbl_sections.academic_term_id and
--    tbl_sections_term. The PIVOT is the truth for visibility and filtering.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbl_learning_material_subject` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `material_id` BIGINT UNSIGNED NOT NULL,
    `subject_id`  BIGINT UNSIGNED NOT NULL,
    `section_id`  BIGINT UNSIGNED NULL,
    `created_at`  TIMESTAMP NULL DEFAULT NULL,
    `updated_at`  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tbl_learning_material_subject_material_id_subject_id_unique` (`material_id`, `subject_id`),
    KEY `tbl_learning_material_subject_subject_id_index` (`subject_id`),
    CONSTRAINT `tbl_learning_material_subject_material_id_foreign`
        FOREIGN KEY (`material_id`) REFERENCES `tbl_learning_materials` (`id`) ON DELETE CASCADE,
    CONSTRAINT `tbl_learning_material_subject_subject_id_foreign`
        FOREIGN KEY (`subject_id`) REFERENCES `tbl_subjects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `tbl_learning_material_subject_section_id_foreign`
        FOREIGN KEY (`section_id`) REFERENCES `tbl_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tbl_learning_material_subject` (`material_id`, `subject_id`, `section_id`, `created_at`, `updated_at`)
SELECT m.`id`, m.`subject_id`, m.`section_id`, m.`created_at`, m.`updated_at`
FROM `tbl_learning_materials` m
WHERE m.`subject_id` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `tbl_learning_material_subject` p
      WHERE p.`material_id` = m.`id` AND p.`subject_id` = m.`subject_id`
  );

-- -----------------------------------------------------------------------------
-- 2. Question-bank questions ↔ subjects.
--    Same shape. Soft-deleted (archived) questions are linked too, so restoring
--    a batch still resolves its subjects.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbl_quiz_subject` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quiz_id`    BIGINT UNSIGNED NOT NULL,
    `subject_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tbl_quiz_subject_quiz_id_subject_id_unique` (`quiz_id`, `subject_id`),
    KEY `tbl_quiz_subject_subject_id_index` (`subject_id`),
    CONSTRAINT `tbl_quiz_subject_quiz_id_foreign`
        FOREIGN KEY (`quiz_id`) REFERENCES `tbl_quizzes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `tbl_quiz_subject_subject_id_foreign`
        FOREIGN KEY (`subject_id`) REFERENCES `tbl_subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tbl_quiz_subject` (`quiz_id`, `subject_id`, `created_at`, `updated_at`)
SELECT q.`id`, q.`subject_id`, q.`created_at`, q.`updated_at`
FROM `tbl_quizzes` q
WHERE q.`subject_id` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `tbl_quiz_subject` p
      WHERE p.`quiz_id` = q.`id` AND p.`subject_id` = q.`subject_id`
  );

-- -----------------------------------------------------------------------------
-- 3. Verify. Both counts should equal the number of source rows with a subject.
-- -----------------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM `tbl_learning_materials` WHERE `subject_id` IS NOT NULL) AS materials,
    (SELECT COUNT(*) FROM `tbl_learning_material_subject`)                         AS material_links,
    (SELECT COUNT(*) FROM `tbl_quizzes` WHERE `subject_id` IS NOT NULL)            AS quizzes,
    (SELECT COUNT(*) FROM `tbl_quiz_subject`)                                      AS quiz_links;
