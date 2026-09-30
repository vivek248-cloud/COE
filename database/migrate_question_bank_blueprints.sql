-- Question Bank Blueprint (QBB) - course-specific HOD workflow
-- Separate from the COE OBE blueprint table: blueprints
-- MySQL / MariaDB

CREATE TABLE IF NOT EXISTS question_bank_blueprints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_code VARCHAR(30) NOT NULL,
    paper_code VARCHAR(100) NOT NULL,
    course_title VARCHAR(255) NOT NULL,
    semester VARCHAR(50) NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    exam_type VARCHAR(120) NOT NULL,
    total_questions INT NOT NULL DEFAULT 275,
    total_marks INT NOT NULL DEFAULT 0,
    matrix_json LONGTEXT NOT NULL,
    status ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    created_by VARCHAR(100) NOT NULL,
    created_by_name VARCHAR(255) NULL,
    published_by VARCHAR(100) NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_qbb_course (department_code, paper_code),
    KEY idx_qbb_status (status),
    KEY idx_qbb_created_by (created_by),
    KEY idx_qbb_context (paper_code, semester, academic_year, exam_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
