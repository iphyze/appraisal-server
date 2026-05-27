-- =============================================================================
-- SCHEMA UPDATE: lambert2_appraisal_conn
-- Appraisal Sections + General Questions + Section Responses
-- Run this AFTER the initial schema has been created.
-- =============================================================================

USE `lambert2_appraisal_conn`;

SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. APPRAISAL SECTIONS
--    Defines ALL sections per cycle per company — including Section A (KPI).
--    type = 'kpi'     → questions come from kpi_questions table (dept/staff specific)
--    type = 'general' → questions come from general_questions table (org-wide)
--
--    Weights across all sections in a cycle MUST total exactly 100.
--    Enforced at the API layer on create/update.
--
--    Example for cycle 2025:
--      A | KPI                   | kpi     | 30%
--      B | Staff's Teamwork      | general | 30%
--      C | Work Attitude         | general | 20%
--      D | Personal Competence   | general | 20%
--                                            ─────
--                                            100%
-- =============================================================================
CREATE TABLE IF NOT EXISTS `appraisal_sections` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `company_id`  INT UNSIGNED  NOT NULL,
  `cycle_id`    INT UNSIGNED  NOT NULL,
  `code`        VARCHAR(10)   NOT NULL
                COMMENT 'Section identifier: A, B, C, D, E...',
  `label`       VARCHAR(200)  NOT NULL
                COMMENT 'Display heading e.g. "Team Objective / KPI (30%) - A"',
  `description` VARCHAR(500)  DEFAULT NULL
                COMMENT 'Subtitle shown on the appraisal form',
  `type`        ENUM('kpi','general') NOT NULL DEFAULT 'general'
                COMMENT 'kpi = dept/staff-specific questions; general = org-wide questions',
  `weight`      DECIMAL(5,2)  NOT NULL
                COMMENT 'Percentage weight e.g. 30.00. All sections in a cycle must sum to 100.',
  `sort_order`  TINYINT       NOT NULL DEFAULT 0
                COMMENT 'Display order on the appraisal form',
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED  DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_section_code_cycle` (`company_id`, `cycle_id`, `code`),
  KEY `idx_section_cycle`   (`cycle_id`),
  KEY `idx_section_company` (`company_id`),
  CONSTRAINT `fk_section_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  CONSTRAINT `fk_section_cycle`   FOREIGN KEY (`cycle_id`)   REFERENCES `appraisal_cycles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================================
-- 2. GENERAL QUESTIONS
--    Org-wide questions for sections of type = 'general' (B, C, D, E...).
--    Belong to a section_id rather than directly to a cycle —
--    the cycle is resolved through the section.
--    Each question is scored 1–5; average across questions = section score.
-- =============================================================================
DROP TABLE IF EXISTS `general_questions`;
CREATE TABLE `general_questions` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `company_id`    INT UNSIGNED  NOT NULL,
  `section_id`    INT UNSIGNED  NOT NULL,
  `question_text` TEXT          NOT NULL,
  `sort_order`    SMALLINT      NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`    INT UNSIGNED  DEFAULT NULL,
  `updated_by`    INT UNSIGNED  DEFAULT NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gq_section`  (`section_id`),
  KEY `idx_gq_company`  (`company_id`),
  CONSTRAINT `fk_gq_company`  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  CONSTRAINT `fk_gq_section`  FOREIGN KEY (`section_id`) REFERENCES `appraisal_sections`(`id`)
                               ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================================
-- 3. KPI QUESTIONS — updated to link to appraisal_sections
--    Section A is a row in appraisal_sections with type = 'kpi'.
--    kpi_questions.section_id references that row so KPI questions
--    are also cycle-aware through the section.
--
--    Hierarchy (most specific wins):
--      1. staff_user_id set     → question is specific to one staff member
--      2. supervisor_id set     → question applies to all that supervisor's staff
--      3. Both NULL             → departmental default for that section/cycle
-- =============================================================================
DROP TABLE IF EXISTS `kpi_questions`;
CREATE TABLE `kpi_questions` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `company_id`     INT UNSIGNED  NOT NULL,
  `section_id`     INT UNSIGNED  NOT NULL
                   COMMENT 'References the KPI section (type=kpi) in appraisal_sections',
  `department`     VARCHAR(150)  NOT NULL,
  `supervisor_id`  INT UNSIGNED  DEFAULT NULL
                   COMMENT 'NULL = dept default; set = supervisor-specific override',
  `staff_user_id`  INT UNSIGNED  DEFAULT NULL
                   COMMENT 'NULL = applies to group; set = individual staff override',
  `question_text`  TEXT          NOT NULL,
  `sort_order`     SMALLINT      NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED  DEFAULT NULL,
  `updated_by`     INT UNSIGNED  DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kpi_section`     (`section_id`),
  KEY `idx_kpi_dept`        (`department`),
  KEY `idx_kpi_supervisor`  (`supervisor_id`),
  KEY `idx_kpi_staff`       (`staff_user_id`),
  CONSTRAINT `fk_kpi_company`    FOREIGN KEY (`company_id`)    REFERENCES `companies`(`id`),
  CONSTRAINT `fk_kpi_section`    FOREIGN KEY (`section_id`)    REFERENCES `appraisal_sections`(`id`),
  CONSTRAINT `fk_kpi_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users`(`id`)
                                  ON DELETE SET NULL,
  CONSTRAINT `fk_kpi_staff`      FOREIGN KEY (`staff_user_id`) REFERENCES `users`(`id`)
                                  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================================
-- 4. APPRAISALS — drop hardcoded section columns
--    work_accomplishment, work_attitude, work_competence, general_performance
--    are no longer stored as fixed columns — they live in appraisal_section_scores.
--    kpi_rating and appraisal_summary stay as computed averages for quick access.
--    kpi_questions_snapshot stays to preserve what questions were used (for PDF).
-- =============================================================================
ALTER TABLE `appraisals`
  DROP COLUMN IF EXISTS `work_accomplishment`,
  DROP COLUMN IF EXISTS `work_attitude`,
  DROP COLUMN IF EXISTS `work_competence`,
  DROP COLUMN IF EXISTS `general_performance`;


-- =============================================================================
-- 5. APPRAISAL SECTION SCORES
--    Stores the computed average score per section per appraisal.
--    One row per section per appraisal.
--    section_avg = average of all question ratings in that section.
--    weighted_score = section_avg × (section.weight / 100)
--    appraisal_summary = SUM(weighted_score) across all sections.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `appraisal_section_scores` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `appraisal_id`  INT UNSIGNED  NOT NULL,
  `section_id`    INT UNSIGNED  NOT NULL,
  `section_code`  VARCHAR(10)   NOT NULL COMMENT 'Snapshot: A, B, C...',
  `section_label` VARCHAR(200)  NOT NULL COMMENT 'Snapshot: heading at time of appraisal',
  `section_weight`DECIMAL(5,2)  NOT NULL COMMENT 'Snapshot: weight at time of appraisal',
  `section_avg`   DECIMAL(4,2)  DEFAULT NULL COMMENT 'Average of all question ratings',
  `weighted_score`DECIMAL(5,4)  DEFAULT NULL COMMENT 'section_avg × (weight/100)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appraisal_section` (`appraisal_id`, `section_id`),
  KEY `idx_ass_appraisal` (`appraisal_id`),
  CONSTRAINT `fk_ass_appraisal` FOREIGN KEY (`appraisal_id`) REFERENCES `appraisals`(`id`)
                                  ON DELETE CASCADE,
  CONSTRAINT `fk_ass_section`   FOREIGN KEY (`section_id`)   REFERENCES `appraisal_sections`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================================
-- 6. APPRAISAL KPI RESPONSES — updated to link to section
--    Per-question ratings for Section A (KPI).
-- =============================================================================
DROP TABLE IF EXISTS `appraisal_kpi_responses`;
CREATE TABLE `appraisal_kpi_responses` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `appraisal_id`     INT UNSIGNED  NOT NULL,
  `section_id`       INT UNSIGNED  NOT NULL,
  `kpi_question_id`  INT UNSIGNED  NOT NULL,
  `question_text`    TEXT          NOT NULL COMMENT 'Snapshot of question at appraisal time',
  `rating`           DECIMAL(3,1)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_response` (`appraisal_id`, `kpi_question_id`),
  CONSTRAINT `fk_kpir_appraisal` FOREIGN KEY (`appraisal_id`)   REFERENCES `appraisals`(`id`)
                                   ON DELETE CASCADE,
  CONSTRAINT `fk_kpir_section`   FOREIGN KEY (`section_id`)     REFERENCES `appraisal_sections`(`id`),
  CONSTRAINT `fk_kpir_question`  FOREIGN KEY (`kpi_question_id`)REFERENCES `kpi_questions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================================
-- 7. APPRAISAL SECTION RESPONSES
--    Per-question ratings for sections B, C, D, E... (type = 'general').
-- =============================================================================
CREATE TABLE IF NOT EXISTS `appraisal_section_responses` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `appraisal_id`         INT UNSIGNED  NOT NULL,
  `section_id`           INT UNSIGNED  NOT NULL,
  `general_question_id`  INT UNSIGNED  NOT NULL,
  `question_text`        TEXT          NOT NULL COMMENT 'Snapshot of question at appraisal time',
  `rating`               DECIMAL(3,1)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_section_response` (`appraisal_id`, `general_question_id`),
  CONSTRAINT `fk_sr_appraisal` FOREIGN KEY (`appraisal_id`)        REFERENCES `appraisals`(`id`)
                                 ON DELETE CASCADE,
  CONSTRAINT `fk_sr_section`   FOREIGN KEY (`section_id`)          REFERENCES `appraisal_sections`(`id`),
  CONSTRAINT `fk_sr_question`  FOREIGN KEY (`general_question_id`) REFERENCES `general_questions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =============================================================================
-- 8. STAFF KPI ASSIGNMENTS — updated to reference section_id instead of cycle_id
-- =============================================================================
DROP TABLE IF EXISTS `staff_kpi_assignments`;
CREATE TABLE `staff_kpi_assignments` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `section_id`       INT UNSIGNED  NOT NULL
                     COMMENT 'The KPI section this assignment belongs to',
  `staff_user_id`    INT UNSIGNED  NOT NULL,
  `kpi_question_id`  INT UNSIGNED  NOT NULL,
  `use_dept_default` TINYINT(1)    NOT NULL DEFAULT 1
                     COMMENT '1 = use dept/supervisor default; 0 = use this custom set',
  `assigned_by`      INT UNSIGNED  DEFAULT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_kpi` (`section_id`, `staff_user_id`, `kpi_question_id`),
  CONSTRAINT `fk_ska_section`   FOREIGN KEY (`section_id`)      REFERENCES `appraisal_sections`(`id`),
  CONSTRAINT `fk_ska_staff`     FOREIGN KEY (`staff_user_id`)   REFERENCES `users`(`id`),
  CONSTRAINT `fk_ska_question`  FOREIGN KEY (`kpi_question_id`) REFERENCES `kpi_questions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 9. SEED — Default sections for cycle 4 (2025) matching old system structure
--    Run after inserting cycle data.
--    Weights: A=30, B=30, C=20, D=20 → total = 100
-- =============================================================================
INSERT IGNORE INTO `appraisal_sections`
  (company_id, cycle_id, code, label, description, type, weight, sort_order, is_active)
VALUES
  (1, 4, 'A',
   'Team Objective / KPI (30%) - A',
   'Please provide a rating from 1-5 for each KPI listed for this staff member',
   'kpi', 30.00, 1, 1),

  (1, 4, 'B',
   'Staff''s Teamwork (30%) - B',
   'Please rate the staff on the below work accomplishment criteria from 1-5',
   'general', 30.00, 2, 1),

  (1, 4, 'C',
   'Staff''s Work Attitude (20%) - C',
   'Please provide a rating from 1-5 as per the below Work Attitude criteria',
   'general', 20.00, 3, 1),

  (1, 4, 'D',
   'Staff''s Personal Competence (20%) - D',
   'Please provide a rating from 1-5 as per the below Work Competence criteria',
   'general', 20.00, 4, 1);


-- =============================================================================
-- 10. SEED — Default general questions for sections B, C, D (2025)
--     Matches the hardcoded questions from the old new_appraisal.php
-- =============================================================================

-- Section B questions (Teamwork/Work Accomplishment)
INSERT IGNORE INTO `general_questions` (company_id, section_id, question_text, sort_order)
SELECT 1, s.id, q.question_text, q.sort_order
FROM appraisal_sections s
JOIN (
  SELECT 'Demonstrate Good Judgement and Decision-Making Skill' AS question_text, 1 AS sort_order
  UNION ALL SELECT 'Plans and organizes tasks to set up priorities', 2
  UNION ALL SELECT 'Develops schedules and meets deadlines', 3
  UNION ALL SELECT 'Initiate and provides suggestions for job enrichment and expanded duties', 4
  UNION ALL SELECT 'Produces work that meets standards', 5
) q ON 1=1
WHERE s.company_id = 1 AND s.cycle_id = 4 AND s.code = 'B';

-- Section C questions (Work Attitude)
INSERT IGNORE INTO `general_questions` (company_id, section_id, question_text, sort_order)
SELECT 1, s.id, q.question_text, q.sort_order
FROM appraisal_sections s
JOIN (
  SELECT 'Maintains harmonious relations with all stakeholders (including superiors, subordinates, colleagues, customers, suppliers, etc)' AS question_text, 1 AS sort_order
  UNION ALL SELECT 'Good verbal & written communication skills', 2
  UNION ALL SELECT 'Communicate timely, effectively and directly with co-workers', 3
  UNION ALL SELECT 'Attendance/Discipline and General Conduct', 4
) q ON 1=1
WHERE s.company_id = 1 AND s.cycle_id = 4 AND s.code = 'C';

-- Section D questions (Personal Competence)
INSERT IGNORE INTO `general_questions` (company_id, section_id, question_text, sort_order)
SELECT 1, s.id, q.question_text, q.sort_order
FROM appraisal_sections s
JOIN (
  SELECT 'Demonstrates good knowledge and skills' AS question_text, 1 AS sort_order
  UNION ALL SELECT 'Functions effectively under pressure and understands job requirements', 2
  UNION ALL SELECT 'Energy Level', 3
) q ON 1=1
WHERE s.company_id = 1 AND s.cycle_id = 4 AND s.code = 'D';


-- =============================================================================
-- SUMMARY OF CHANGES
-- =============================================================================
--
-- NEW TABLES:
--   appraisal_sections        — defines A/B/C/D/E sections per company per cycle
--   general_questions         — org-wide questions for general sections (B,C,D,E)
--   appraisal_section_scores  — computed avg + weighted score per section per appraisal
--   appraisal_section_responses — per-question ratings for general sections
--   staff_kpi_assignments     — custom KPI question sets per staff member
--
-- UPDATED TABLES:
--   kpi_questions             — now links to appraisal_sections instead of cycle directly
--   appraisal_kpi_responses   — now includes section_id
--
-- REMOVED COLUMNS FROM appraisals:
--   work_accomplishment, work_attitude, work_competence, general_performance
--   (replaced by appraisal_section_scores + appraisal_section_responses)
--
-- KEY RULES (enforced at API layer):
--   1. Total weight of all sections in a cycle must equal exactly 100%
--   2. Only one section per cycle can have type = 'kpi' (Section A)
--   3. KPI section weight is configurable — NOT hardcoded at 30%
--   4. Adding Section E next year = one INSERT into appraisal_sections
--      + questions into general_questions
--   5. appraisal_summary = SUM(section_avg × section.weight/100) — fully dynamic
--
-- EVALUATION STATEMENTS (computed from appraisal_summary):
--   0.1 – 1.0  → Requires Development
--   1.1 – 2.0  → Marginally Meets Requirements
--   2.1 – 3.0  → Completely Meet Requirements
--   3.1 – 4.5  → Consistently Exceeds Requirements
--   4.6 – 5.0  → Significant / Outstanding
--
-- =============================================================================
