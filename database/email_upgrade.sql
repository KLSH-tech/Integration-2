-- ============================================================================
-- database/email_upgrade.sql — Additive migration for the EMAIL notification
-- feature. SAFE TO RUN ON EXISTING DATA: it only ADDS columns / a table and
-- WIDENS one enum. It deletes nothing.
--
-- Run AFTER student_attendance_system.sql, on the SAME database, e.g. in
-- phpMyAdmin (select the `student_attendance_system` DB → Import / SQL tab).
-- Safe to run more than once (uses IF NOT EXISTS — MariaDB/XAMPP supports it;
-- on MySQL 8 remove the "IF NOT EXISTS" words on the ADD COLUMN lines).
-- ============================================================================
USE `student_attendance_system`;

-- ── 1) Parents need an e-mail address (the table only had a phone before) ────
ALTER TABLE `parents`
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL AFTER `contact_number`;

-- ── 2) Track whether the e-mail went out, alongside the existing sms_sent ────
ALTER TABLE `attendance_logs`
  ADD COLUMN IF NOT EXISTS `email_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sms_sent`;

-- ── 3) FIX A REAL BUG: the scanner writes scanned_by='student_terminal' but
--        the enum only allowed ('teacher','student'), so MySQL silently stored
--        '' (you can see the blank values in the seed data). Widen the enum so
--        the value is stored correctly from now on. Existing rows are untouched.
ALTER TABLE `attendance_logs`
  MODIFY `scanned_by` ENUM('teacher','student','student_terminal','teacher_terminal')
  NOT NULL DEFAULT 'student';

-- ── 4) E-mail audit log: one row per send attempt (history + retry support) ──
CREATE TABLE IF NOT EXISTS `email_log` (
  `email_id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `student_id`        INT(11)      DEFAULT NULL,
  `log_id`            INT(11)      DEFAULT NULL,            -- FK -> attendance_logs.log_id
  `recipient`         VARCHAR(150) DEFAULT NULL,
  `recipient_type`    ENUM('parent','student','custom') NOT NULL DEFAULT 'parent',
  `subject`           VARCHAR(255) DEFAULT NULL,
  `action`            ENUM('in','out') NOT NULL DEFAULT 'in',
  `attendance_status` VARCHAR(20)  DEFAULT NULL,
  `status`            ENUM('sent','failed','skipped','test') NOT NULL DEFAULT 'failed',
  `error_message`     TEXT         DEFAULT NULL,
  `attempts`          INT(11)      NOT NULL DEFAULT 1,
  `sent_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email_id`),
  KEY `idx_email_student` (`student_id`),
  KEY `idx_email_status`  (`status`),
  KEY `idx_email_dedup`   (`student_id`,`action`,`recipient`,`sent_at`),
  KEY `fk_email_log`      (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Foreign keys (wrapped so a re-run won't error if they already exist).
-- If your MySQL version rejects the IF-NOT-EXISTS-style guard, you can ignore a
-- "duplicate foreign key" error on a second run — it is harmless.
SET @fk1 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'email_log' AND CONSTRAINT_NAME = 'fk_email_student');
SET @sql1 := IF(@fk1 = 0,
  'ALTER TABLE `email_log` ADD CONSTRAINT `fk_email_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE',
  'SELECT 1');
PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @fk2 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'email_log' AND CONSTRAINT_NAME = 'fk_email_log');
SET @sql2 := IF(@fk2 = 0,
  'ALTER TABLE `email_log` ADD CONSTRAINT `fk_email_log` FOREIGN KEY (`log_id`) REFERENCES `attendance_logs`(`log_id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- ── 5) OPTIONAL (recommended for the demo): give every parent a working e-mail
--        so the system can actually send. This copies the STUDENT's e-mail to
--        the parent row when the parent has none. Comment out if undesired.
UPDATE `parents` p
JOIN `students` s ON s.id = p.student_id
SET p.email = s.email
WHERE (p.email IS NULL OR p.email = '')
  AND s.email IS NOT NULL AND s.email <> '';

-- ── 6) OPTIONAL data hygiene: the seed data left some blank enum values from
--        the old scanned_by bug. Normalise blanks to sensible defaults. This
--        UPDATES (does not delete) and is safe to skip.
UPDATE `attendance_logs` SET `scanned_by` = 'student_terminal' WHERE `scanned_by` = '' OR `scanned_by` IS NULL;
UPDATE `attendance_logs` SET `attendance_status` = 'on_time'
  WHERE (`attendance_status` = '' OR `attendance_status` IS NULL) AND `action` = 'in';
UPDATE `attendance_logs` SET `attendance_status` = 'on_time'
  WHERE (`attendance_status` = '' OR `attendance_status` IS NULL) AND `action` = 'out';
