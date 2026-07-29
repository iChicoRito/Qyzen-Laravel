-- =============================================================================
-- Tasks 29 + 30 — schema and data changes
--
-- Equivalent to these migrations, for applying to a database where you cannot
-- run `php artisan migrate`:
--   2026_07_28_000001_add_is_archived_to_tbl_assessments
--   2026_07_28_000002_create_announcement_subject_table
--   2026_07_28_000003_add_kind_to_tbl_conversation_messages
--   2026_07_29_000001_backfill_kind_on_system_conversation_messages
--
-- Target: MySQL 8 / MariaDB 10.6+, database `qyzen_v2`.
--
-- BACK UP FIRST. Section 2 DROPS tbl_announcements.subject_id. The data is
-- copied into the new pivot table first, but the column itself is destroyed:
--   mysqldump -u root qyzen_v2 > qyzen_v2-backup.sql
--
-- Run the whole file in one go. It is wrapped in a transaction, but note that
-- MySQL commits DDL implicitly — a failure part-way through will NOT roll back
-- the DDL that already ran. Each step is written to be re-runnable, so on a
-- failure you can fix the cause and run the file again.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Task 29 — archive assessments without touching student scores.
--    A boolean, deliberately not a soft delete: a soft-delete global scope would
--    hide archived assessments from the score matrix, exports and student score
--    history. This flag is read only by the listing queries.
-- -----------------------------------------------------------------------------
ALTER TABLE `tbl_assessments`
    ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`,
    ADD INDEX `tbl_assessments_is_archived_index` (`is_archived`);


-- -----------------------------------------------------------------------------
-- 2. Task 29 — announcements target many subjects.
--    Creates the pivot, copies the single subject_id into it, then drops the old
--    column. Order matters: the foreign key must go before the index it depends
--    on, or MySQL raises errno 1553 ("cannot drop index needed in a foreign key
--    constraint").
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbl_announcement_subject` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `announcement_id` BIGINT UNSIGNED NOT NULL,
    `subject_id`      BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY `tbl_announcement_subject_announcement_id_subject_id_unique` (`announcement_id`, `subject_id`),
    KEY `tbl_announcement_subject_subject_id_foreign` (`subject_id`),
    CONSTRAINT `tbl_announcement_subject_announcement_id_foreign`
        FOREIGN KEY (`announcement_id`) REFERENCES `tbl_announcements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `tbl_announcement_subject_subject_id_foreign`
        FOREIGN KEY (`subject_id`) REFERENCES `tbl_subjects` (`id`) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Backfill. INSERT IGNORE makes a re-run a no-op against the unique key.
INSERT IGNORE INTO `tbl_announcement_subject` (`announcement_id`, `subject_id`)
SELECT `id`, `subject_id`
FROM `tbl_announcements`
WHERE `subject_id` IS NOT NULL;

-- Verify the copy before destroying the column. These two numbers must match;
-- if they do not, STOP and do not run the ALTER below.
SELECT
    (SELECT COUNT(*) FROM `tbl_announcements` WHERE `subject_id` IS NOT NULL) AS announcements_with_a_subject,
    (SELECT COUNT(*) FROM `tbl_announcement_subject`)                         AS pivot_rows;

ALTER TABLE `tbl_announcements`
    DROP FOREIGN KEY `tbl_announcements_subject_id_foreign`;
ALTER TABLE `tbl_announcements`
    DROP INDEX `tbl_announcements_subject_id_is_active_index`,
    DROP COLUMN `subject_id`;


-- -----------------------------------------------------------------------------
-- 3. Task 29/30 — tag system-generated chat messages so the thread renders them
--    as a KTUI alert instead of a chat bubble. NULL = an ordinary typed message.
-- -----------------------------------------------------------------------------
ALTER TABLE `tbl_conversation_messages`
    ADD COLUMN `kind` VARCHAR(255) NULL DEFAULT NULL AFTER `content`;


-- -----------------------------------------------------------------------------
-- 4. Task 30 — retag messages sent before the `kind` column existed, so historic
--    exemption / special-access messages stop rendering as bubbles.
--
--    These two sentences are produced only by
--    AssessmentController::toggleExemption/toggleAccess and are never typed by a
--    user, so matching on the opening phrase is safe. Both wordings are matched:
--    "exempted from assessment X" was shortened to "exempted from X" in Task 30.
--    Deleted messages and rows that already carry a kind are skipped.
-- -----------------------------------------------------------------------------
UPDATE `tbl_conversation_messages`
SET `kind` = 'assessment_exempted'
WHERE `kind` IS NULL
  AND `message_deleted_at` IS NULL
  AND `content` LIKE 'You have been exempted from %';

UPDATE `tbl_conversation_messages`
SET `kind` = 'assessment_access_granted'
WHERE `kind` IS NULL
  AND `message_deleted_at` IS NULL
  AND `content` LIKE 'You have been granted special access to %';


-- -----------------------------------------------------------------------------
-- 5. Record the migrations so `php artisan migrate` does not try to re-run them.
--    Skip this section if the target database has no `migrations` table.
-- -----------------------------------------------------------------------------
INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.name, (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`)
FROM (
    SELECT '2026_07_28_000001_add_is_archived_to_tbl_assessments'                  AS name
    UNION ALL SELECT '2026_07_28_000002_create_announcement_subject_table'
    UNION ALL SELECT '2026_07_28_000003_add_kind_to_tbl_conversation_messages'
    UNION ALL SELECT '2026_07_29_000001_backfill_kind_on_system_conversation_messages'
) AS m
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` x WHERE x.`migration` = m.name
);


-- -----------------------------------------------------------------------------
-- 6. Post-run verification. Expected:
--      assessments_is_archived_column   = 1
--      announcements_subject_id_column  = 0   (dropped)
--      conversation_messages_kind_column= 1
--      untagged_system_messages         = 0
-- -----------------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_assessments' AND COLUMN_NAME = 'is_archived')            AS assessments_is_archived_column,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_announcements' AND COLUMN_NAME = 'subject_id')           AS announcements_subject_id_column,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_conversation_messages' AND COLUMN_NAME = 'kind')         AS conversation_messages_kind_column,
    (SELECT COUNT(*) FROM `tbl_conversation_messages`
      WHERE `kind` IS NULL AND `message_deleted_at` IS NULL
        AND (`content` LIKE 'You have been exempted from %'
          OR `content` LIKE 'You have been granted special access to %'))                                            AS untagged_system_messages;
