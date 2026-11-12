-- Additive Dean review audit table for the existing QPS draft workflow.
-- Run only after selecting the restored test database (e.g. hccweb_coe_test).
-- Does not alter existing staff, course, question, or authentication tables.
CREATE TABLE IF NOT EXISTS qps_dean_review_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    draft_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NULL,
    staff_code VARCHAR(100) NOT NULL,
    action_type VARCHAR(40) NOT NULL,
    remark_text TEXT NULL,
    question_version INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dean_action_draft (draft_id, created_at),
    KEY idx_dean_action_question (question_id, action_type, id),
    KEY idx_dean_action_staff (staff_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
