<?php
/**
 * Phase 1: Shared Workflow Helpers
 * Holy Cross College (Autonomous) - COE Question Paper System
 *
 * Centralized functions for:
 * - Status transition validation (per-item)
 * - Correction counting (open/resolved per item)
 * - Department scope resolution
 * - Role guards (requireRole, requireDepartmentScope)
 * - Audit logging for status transitions
 *
 * All functions use PDO prepared statements, transactions for multi-step writes.
 * Never expose SQL errors to users; log them instead.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/system.php';

/* =========================================================
   SETTINGS HELPERS (read from qps_settings table)
========================================================= */

/**
 * Get a setting value from qps_settings table
 */
function qps_get_setting(PDO $pdo, string $key, $default = null) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM qps_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) {
        error_log('[QPS setting] ' . $e->getMessage());
        return $default;
    }
}

/**
 * Get static account password hash from settings
 */
function qps_get_static_password_hash(PDO $pdo, string $role): string {
    $key = 'static_' . strtolower($role) . '_password_hash';
    return (string)qps_get_setting($pdo, $key, '');
}

/**
 * Get department scope for static HOD/Dean accounts
 * Returns array of department codes or ['all'] for all departments
 */
function qps_get_department_scope(PDO $pdo, string $role): array {
    $key = 'static_' . strtolower($role) . '_department_scope';
    $val = qps_get_setting($pdo, $key, 'all');
    if ($val === 'all' || $val === '') {
        return ['all'];
    }
    return array_map('trim', explode(',', $val));
}

/**
 * Get COE repeat-year threshold (N years)
 */
function qps_get_repeat_year_threshold(PDO $pdo): int {
    return (int)qps_get_setting($pdo, 'coe_repeat_year_threshold', 1);
}

/**
 * Get default academic year
 */
function qps_get_default_academic_year(PDO $pdo): string {
    return (string)qps_get_setting($pdo, 'default_academic_year', '2026-2027');
}

/**
 * Get DOCX header settings
 */
function qps_get_docx_settings(PDO $pdo): array {
    return [
        'header_text' => qps_get_setting($pdo, 'docx_header_text', 'HOLY CROSS COLLEGE (AUTONOMOUS)'),
        'college_name' => qps_get_setting($pdo, 'docx_college_name', 'Holy Cross College (Autonomous)'),
        'affiliation' => qps_get_setting($pdo, 'docx_affiliation', 'Affiliated to Bharathidasan University | NAAC A++'),
        'location' => qps_get_setting($pdo, 'docx_location', 'Tiruchirappalli - 620 002, Tamil Nadu'),
    ];
}

/* =========================================================
   ROLE & AUTHORIZATION HELPERS
========================================================= */

/**
 * Get current user's role (normalized)
 */
function qps_get_current_role(): string {
    if (empty($_SESSION['user'])) {
        return 'GUEST';
    }

    // Static role takes precedence
    if (!empty($_SESSION['qps_static_role'])) {
        return strtoupper($_SESSION['qps_static_role']);
    }

    $role = strtoupper((string)($_SESSION['user']['role'] ?? ''));

    // Map ERP roles
    if (in_array($role, ['ERP_ADMIN', 'ERP_STAFF', 'SUPER_ADMIN', 'ADMIN', 'SYSTEM_ADMIN'], true)) {
        return 'ERP_ADMIN';
    }

    // Map teaching roles
    if (in_array($role, ['TEACHING_STAFF', 'FACULTY', 'TEACHING_FACULTY', 'STAFF'], true)) {
        return 'TEACHING';
    }

    return $role; // HOD, DEAN, COE, COE_ADMIN, COE_STAFF
}

/**
 * Get current user's department code
 */
function qps_get_current_dept_code(): string {
    if (empty($_SESSION['user'])) {
        return '';
    }
    return strtoupper(trim((string)($_SESSION['user']['dept_code'] ?? $_SESSION['user']['department'] ?? '')));
}

/**
 * Get current user's staff code
 */
function qps_get_current_staff_code(): string {
    if (empty($_SESSION['user'])) {
        return '';
    }
    return (string)($_SESSION['user']['staff_code'] ?? '');
}

/**
 * Get current user's name
 */
function qps_get_current_name(): string {
    if (empty($_SESSION['user'])) {
        return 'SYSTEM';
    }
    return (string)($_SESSION['user']['name'] ?? $_SESSION['user']['staff_code'] ?? 'SYSTEM');
}

/**
 * Check if user has a specific role
 */
function qps_has_role(string $requiredRole): bool {
    $current = qps_get_current_role();
    $required = strtoupper($requiredRole);

    // ERP Admin and Super Admin have all access
    if (in_array($current, ['ERP_ADMIN', 'SUPER_ADMIN'], true)) {
        return true;
    }

    return $current === $required;
}

/**
 * Require a specific role - exits with 403 if not authorized
 */
function qps_require_role(string $requiredRole): void {
    if (!qps_has_role($requiredRole)) {
        http_response_code(403);
        $role = qps_get_current_role();
        exit(json_encode([
            'success' => false,
            'message' => "Access denied. Required role: {$requiredRole}, current: {$role}"
        ], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Require user to be logged in
 */
function qps_require_auth(): void {
    if (empty($_SESSION['user']) && empty($_SESSION['qps_static_role'])) {
        http_response_code(401);
        exit(json_encode([
            'success' => false,
            'message' => 'Authentication required. Please log in.'
        ], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Check if current user can access a department
 * HOD/Dean static accounts have configurable department scopes
 */
function qps_can_access_department(PDO $pdo, string $deptCode): bool {
    $role = qps_get_current_role();
    $userDept = qps_get_current_dept_code();

    // ERP Admin and Super Admin have all access
    if (in_array($role, ['ERP_ADMIN', 'SUPER_ADMIN'], true)) {
        return true;
    }

    // Teaching staff can only access their own department
    if ($role === 'TEACHING') {
        return strtoupper($deptCode) === strtoupper($userDept);
    }

    // Static HOD/Dean accounts check configured scope
    if (in_array($role, ['HOD', 'DEAN'], true) && !empty($_SESSION['qps_static_role'])) {
        $scope = qps_get_department_scope($pdo, $role);
        if (in_array('all', $scope, true)) {
            return true;
        }
        return in_array(strtoupper($deptCode), array_map('strtoupper', $scope), true);
    }

    // Regular HOD/Dean (from staff master) - use their department
    if (in_array($role, ['HOD', 'DEAN'], true)) {
        return strtoupper($deptCode) === strtoupper($userDept);
    }

    // COE has access to all departments
    if (in_array($role, ['COE', 'COE_ADMIN', 'COE_STAFF'], true)) {
        return true;
    }

    return false;
}

/**
 * Require department access - exits with 403 if not authorized
 */
function qps_require_department(PDO $pdo, string $deptCode): void {
    if (!qps_can_access_department($pdo, $deptCode)) {
        http_response_code(403);
        exit(json_encode([
            'success' => false,
            'message' => "Access denied. You do not have permission for department: {$deptCode}"
        ], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Check if user owns a submission (for teaching staff)
 */
function qps_user_owns_submission(PDO $pdo, int $submissionId): bool {
    $staffCode = qps_get_current_staff_code();
    if (!$staffCode) {
        return false;
    }

    try {
        $st = $pdo->prepare("SELECT 1 FROM course_submissions WHERE id = ? AND staff_code = ?");
        $st->execute([$submissionId, $staffCode]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[QPS ownership check] ' . $e->getMessage());
        return false;
    }
}

/* =========================================================
   STATUS TRANSITION VALIDATION
========================================================= */

/**
 * Valid status transitions per item type
 * Returns array: [from_status => [allowed_to_statuses]]
 */
function qps_get_valid_transitions(): array {
    return [
        'question_bank' => [
            'draft' => ['submitted_to_hod'],
            'submitted_to_hod' => ['hod_corrections'],
            'hod_corrections' => ['submitted_to_hod'],
            'sent_to_dean' => ['dean_corrections'],
            'dean_corrections' => ['sent_to_dean'],
            'sent_to_coe' => ['approved'],
            'approved' => [], // terminal
        ],
        'master_blueprint' => [
            'draft' => ['submitted_to_hod'],
            'submitted_to_hod' => ['hod_corrections'],
            'hod_corrections' => ['submitted_to_hod'],
            'sent_to_dean' => ['dean_corrections'],
            'dean_corrections' => ['sent_to_dean'],
            'sent_to_coe' => ['approved'],
            'approved' => [],
        ],
        'final_blueprint' => [
            'draft' => ['submitted_to_hod'],
            'submitted_to_hod' => ['hod_corrections'],
            'hod_corrections' => ['submitted_to_hod'],
            'sent_to_dean' => ['dean_corrections'],
            'dean_corrections' => ['sent_to_dean'],
            'sent_to_coe' => ['approved'],
            'approved' => [],
        ],
    ];
}

/**
 * Validate if a status transition is allowed
 */
function qps_is_valid_transition(string $itemType, string $fromStatus, string $toStatus): bool {
    $transitions = qps_get_valid_transitions();
    $itemTransitions = $transitions[$itemType] ?? [];
    $allowed = $itemTransitions[$fromStatus] ?? [];
    return in_array($toStatus, $allowed, true);
}

/**
 * Get the current status of an item for a submission
 */
function qps_get_item_status(PDO $pdo, int $submissionId, string $itemType): ?string {
    $column = match($itemType) {
        'question_bank' => 'question_bank_status',
        'master_blueprint' => 'master_blueprint_status',
        'final_blueprint' => 'final_blueprint_status',
        default => null
    };

    if (!$column) {
        return null;
    }

    try {
        $st = $pdo->prepare("SELECT {$column} FROM course_submissions WHERE id = ?");
        $st->execute([$submissionId]);
        return $st->fetchColumn() ?: null;
    } catch (Throwable $e) {
        error_log('[QPS get item status] ' . $e->getMessage());
        return null;
    }
}

/**
 * Update item status with validation
 * Returns [success => bool, message => string, new_status => string|null]
 */
function qps_transition_item_status(PDO $pdo, int $submissionId, string $itemType, string $toStatus, string $actorRole, string $note = ''): array {
    $fromStatus = qps_get_item_status($pdo, $submissionId, $itemType);

    if ($fromStatus === null) {
        return ['success' => false, 'message' => 'Submission not found', 'new_status' => null];
    }

    if (!$fromStatus) {
        return ['success' => false, 'message' => 'Invalid current status', 'new_status' => null];
    }

    if (!qps_is_valid_transition($itemType, $fromStatus, $toStatus)) {
        return [
            'success' => false,
            'message' => "Invalid transition: {$itemType} from {$fromStatus} to {$toStatus}",
            'new_status' => null
        ];
    }

    // Check for open corrections before allowing forward transitions
    $forwardStatuses = ['sent_to_dean', 'sent_to_coe', 'approved'];
    if (in_array($toStatus, $forwardStatuses, true)) {
        $openCount = qps_count_open_corrections($pdo, $submissionId, $itemType);
        if ($openCount > 0) {
            return [
                'success' => false,
                'message' => "Cannot forward: {$openCount} open correction(s) exist for {$itemType}",
                'new_status' => null
            ];
        }
    }

    $column = match($itemType) {
        'question_bank' => 'question_bank_status',
        'master_blueprint' => 'master_blueprint_status',
        'final_blueprint' => 'final_blueprint_status',
        default => null
    };

    if (!$column) {
        return ['success' => false, 'message' => 'Invalid item type', 'new_status' => null];
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE course_submissions SET {$column} = ?, updated_at = NOW() WHERE id = ?");
        $st->execute([$toStatus, $submissionId]);

        // Also update qps_submission_packages if linked
        $st2 = $pdo->prepare("UPDATE qps_submission_packages SET status = ?, updated_at = NOW() WHERE id = (SELECT final_blueprint_id FROM course_submissions WHERE id = ?)");
        $st2->execute([$toStatus, $submissionId]);

        // Audit log
        qps_audit_transition($pdo, $submissionId, $itemType, $fromStatus, $toStatus, $actorRole, $note);

        $pdo->commit();
        return ['success' => true, 'message' => 'Status updated', 'new_status' => $toStatus];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[QPS transition status] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'new_status' => null];
    }
}

/* =========================================================
   CORRECTION COUNTING & MANAGEMENT
========================================================= */

/**
 * Count open corrections for a submission item
 */
function qps_count_open_corrections(PDO $pdo, int $submissionId, ?string $itemType = null): int {
    try {
        $sql = "SELECT COUNT(*) FROM corrections WHERE submission_id = ? AND status = 'open'";
        $params = [$submissionId];

        if ($itemType !== null) {
            $sql .= " AND item_type = ?";
            $params[] = $itemType;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[QPS count corrections] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Get all corrections for a submission (grouped by item type)
 */
function qps_get_corrections(PDO $pdo, int $submissionId): array {
    try {
        $st = $pdo->prepare("
            SELECT * FROM corrections
            WHERE submission_id = ?
            ORDER BY item_type, target_type, target_id, created_at DESC
        ");
        $st->execute([$submissionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Group by item_type
        $grouped = [
            'question_bank' => [],
            'master_blueprint' => [],
            'final_blueprint' => [],
        ];

        foreach ($rows as $row) {
            $itemType = $row['item_type'];
            if (isset($grouped[$itemType])) {
                $grouped[$itemType][] = $row;
            }
        }

        return $grouped;
    } catch (Throwable $e) {
        error_log('[QPS get corrections] ' . $e->getMessage());
        return [];
    }
}

/**
 * Add a correction (used by HOD, Dean, COE)
 */
function qps_add_correction(PDO $pdo, array $data): array {
    $required = ['submission_id', 'item_type', 'target_type', 'target_id', 'text', 'raised_by_role', 'raised_by'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'message' => "Missing required field: {$field}"];
        }
    }

    // Validate item_type
    if (!in_array($data['item_type'], ['question_bank', 'master_blueprint', 'final_blueprint'], true)) {
        return ['success' => false, 'message' => 'Invalid item_type'];
    }

    // Validate target_type
    if (!in_array($data['target_type'], ['question', 'blueprint_cell'], true)) {
        return ['success' => false, 'message' => 'Invalid target_type'];
    }

    // Validate raised_by_role
    if (!in_array($data['raised_by_role'], ['HOD', 'DEAN', 'COE'], true)) {
        return ['success' => false, 'message' => 'Invalid raised_by_role'];
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("
            INSERT INTO corrections
            (submission_id, item_type, target_type, target_id, cell_ref, text, raised_by_role, raised_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')
        ");
        $st->execute([
            $data['submission_id'],
            $data['item_type'],
            $data['target_type'],
            $data['target_id'],
            $data['cell_ref'] ?? null,
            $data['text'],
            $data['raised_by_role'],
            $data['raised_by'],
        ]);

        $correctionId = (int)$pdo->lastInsertId();

        // If this is a question correction, also update draft question status
        if ($data['item_type'] === 'question_bank' && $data['target_type'] === 'question') {
            $st2 = $pdo->prepare("
                UPDATE qps_question_bank_draft_questions
                SET correction_status = 'NEEDS_CORRECTION', remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $st2->execute([$data['text'], $data['target_id']]);
        }

        // Audit log
        qps_audit($pdo, 'CORRECTION_ADDED', 'CORRECTION', (string)$correctionId, [
            'submission_id' => $data['submission_id'],
            'item_type' => $data['item_type'],
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'raised_by_role' => $data['raised_by_role'],
            'raised_by' => $data['raised_by'],
        ]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Correction added', 'correction_id' => $correctionId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[QPS add correction] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Resolve a correction (used by teaching staff)
 */
function qps_resolve_correction(PDO $pdo, int $correctionId, string $resolverNote, string $resolverStaffCode): array {
    $pdo->beginTransaction();
    try {
        // Get correction details
        $st = $pdo->prepare("
            SELECT c.*, cs.staff_code as submission_staff_code
            FROM corrections c
            JOIN course_submissions cs ON cs.id = c.submission_id
            WHERE c.id = ? AND c.status = 'open' AND cs.staff_code = ?
        ");
        $st->execute([$correctionId, $resolverStaffCode]);
        $correction = $st->fetch(PDO::FETCH_ASSOC);

        if (!$correction) {
            return ['success' => false, 'message' => 'Correction not found or not authorized to resolve'];
        }

        // Update correction
        $st2 = $pdo->prepare("
            UPDATE corrections
            SET status = 'resolved', resolver_note = ?, resolved_by = ?, resolved_at = NOW()
            WHERE id = ? AND status = 'open'
        ");
        $st2->execute([$resolverNote, $resolverStaffCode, $correctionId]);

        if ($st2->rowCount() === 0) {
            return ['success' => false, 'message' => 'Correction was already resolved'];
        }

        // If question correction, update draft question
        if ($correction['item_type'] === 'question_bank' && $correction['target_type'] === 'question') {
            $st3 = $pdo->prepare("
                UPDATE qps_question_bank_draft_questions
                SET correction_status = 'CORRECTED', remarks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $st3->execute([$resolverNote !== '' ? $resolverNote : 'Correction resolved', $correction['target_id']]);
        }

        // Audit log
        qps_audit($pdo, 'CORRECTION_RESOLVED', 'CORRECTION', (string)$correctionId, [
            'submission_id' => $correction['submission_id'],
            'item_type' => $correction['item_type'],
            'target_type' => $correction['target_type'],
            'target_id' => $correction['target_id'],
            'resolver_note' => $resolverNote,
        ]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Correction resolved'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[QPS resolve correction] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Withdraw a correction (used by HOD/Dean/COE who created it)
 */
function qps_withdraw_correction(PDO $pdo, int $correctionId, string $actorRole, string $actorCode): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("
            UPDATE corrections
            SET status = 'withdrawn'
            WHERE id = ? AND status = 'open' AND raised_by_role = ? AND raised_by = ?
        ");
        $st->execute([$correctionId, $actorRole, $actorCode]);

        if ($st->rowCount() === 0) {
            return ['success' => false, 'message' => 'Correction not found or not authorized to withdraw'];
        }

        // Audit log
        qps_audit($pdo, 'CORRECTION_WITHDRAWN', 'CORRECTION', (string)$correctionId, []);

        $pdo->commit();
        return ['success' => true, 'message' => 'Correction withdrawn'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[QPS withdraw correction] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/* =========================================================
   AUDIT LOGGING
========================================================= */

/**
 * Get client IP address (respects trusted proxies)
 */
function qps_get_client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $trustedProxies = defined('QPS_TRUSTED_PROXIES') && is_array(QPS_TRUSTED_PROXIES) ? QPS_TRUSTED_PROXIES : [];

    if (in_array($ip, $trustedProxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $ip = $forwarded;
        }
    }

    return $ip;
}

/**
 * Generic audit log function
 */
function qps_audit(PDO $pdo, string $action, string $entityType = '', string $entityId = '', array $details = []): void {
    $staffCode = qps_get_current_staff_code();
    $staffName = qps_get_current_name();
    $role = qps_get_current_role();

    // Try to get mobile from staff master if not in session
    $staffMobile = '';
    if (!empty($staffCode) && $staffCode !== 'SYSTEM' && $staffCode !== 'STATIC_HOD' && $staffCode !== 'STATIC_DEAN' && $staffCode !== 'STATIC_COE') {
        try {
            $stMob = $pdo->prepare("SELECT MOBILE_NO, FIRST_NAME FROM pr_x_xxxx_staf_prof_mast WHERE UPPER(STAFF_CODE) = UPPER(?) LIMIT 1");
            $stMob->execute([$staffCode]);
            $rowMob = $stMob->fetch(PDO::FETCH_ASSOC);
            if ($rowMob) {
                $staffMobile = $rowMob['MOBILE_NO'] ?? '';
                if (empty($staffName)) $staffName = $rowMob['FIRST_NAME'] ?? '';
            }
        } catch (Throwable $e) {}
    }

    $ipAddress = qps_get_client_ip();

    try {
        // Ensure audit schema has all columns
        qps_ensure_audit_schema($pdo);

        $st = $pdo->prepare("
            INSERT INTO qps_audit_log
            (staff_code, staff_name, staff_mobile, role, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $staffCode,
            $staffName,
            $staffMobile,
            $role,
            $action,
            $entityType,
            $entityId,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ipAddress,
            date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $e) {
        // Fallback for older schema
        try {
            $stOld = $pdo->prepare("
                INSERT INTO qps_audit_log (actor, role, action, entity_type, entity_id, details, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stOld->execute([$staffCode, $role, $action, $entityType, $entityId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s')]);
        } catch (Throwable $e2) {
            error_log('[QPS audit fallback] ' . $e2->getMessage());
        }
    }
}

/**
 * Structured status transition audit
 */
function qps_audit_transition(PDO $pdo, int $submissionId, string $itemType, string $fromStatus, string $toStatus, string $actorRole, string $note = ''): void {
    qps_audit($pdo, 'STATUS_TRANSITION', 'COURSE_SUBMISSION_ITEM', (string)$submissionId, [
        'item_type' => $itemType,
        'from_status' => $fromStatus,
        'to_status' => $toStatus,
        'actor_role' => $actorRole,
        'note' => $note,
    ]);
}

/**
 * Ensure audit log schema has required columns
 */
function qps_ensure_audit_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    qps_add_column_if_missing($pdo, 'qps_audit_log', 'staff_code', 'VARCHAR(100) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'staff_name', 'VARCHAR(255) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'staff_mobile', 'VARCHAR(50) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'role', 'VARCHAR(50) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'entity_type', 'VARCHAR(100) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'entity_id', 'VARCHAR(100) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'details', 'LONGTEXT NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'ip_address', 'VARCHAR(100) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'from_status', 'VARCHAR(50) NULL');
    qps_add_column_if_missing($pdo, 'qps_audit_log', 'to_status', 'VARCHAR(50) NULL');
}

/* =========================================================
   UTILITY: Safe column addition helper (reused from system.php)
========================================================= */
function qps_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_map(function($c) { return strtolower($c['name']); }, $cols);
            if (!in_array(strtolower($column), $existing, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE UPPER(TABLE_SCHEMA) = UPPER(DATABASE()) AND UPPER(TABLE_NAME) = UPPER(?) AND UPPER(COLUMN_NAME) = UPPER(?)");
            $q->execute([$table, $column]);
            if (!(int)$q->fetchColumn()) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    } catch (Throwable $e) {
        error_log('[QPS add column] ' . $e->getMessage());
    }
}

/* =========================================================
   CSRF HELPERS
========================================================= */

/**
 * Get or generate CSRF token for a session key
 */
function qps_get_csrf_token(string $sessionKey = 'qps_csrf'): string {
    if (empty($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$sessionKey];
}

/**
 * Validate CSRF token
 */
function qps_validate_csrf(string $token, string $sessionKey = 'qps_csrf'): bool {
    $expected = $_SESSION[$sessionKey] ?? '';
    return $expected !== '' && hash_equals($expected, $token);
}

/* =========================================================
   JSON RESPONSE HELPER
========================================================= */

/**
 * Send JSON response and exit
 */
function qps_json_response(array $data, int $statusCode = 200): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send success JSON response
 */
function qps_json_success(array $data = [], string $message = ''): never {
    qps_json_response(['success' => true, 'message' => $message, 'data' => $data]);
}

/**
 * Send error JSON response
 */
function qps_json_error(string $message, array $data = [], int $statusCode = 400): never {
    qps_json_response(['success' => false, 'message' => $message, 'data' => $data], $statusCode);
}

/* =========================================================
   DEPARTMENT HELPERS
========================================================= */

/**
 * Get all departments
 */
function qps_get_departments(PDO $pdo): array {
    try {
        $st = $pdo->query("SELECT code, name FROM departments ORDER BY name ASC");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[QPS get departments] ' . $e->getMessage());
        return [];
    }
}

/**
 * Get department name by code
 */
function qps_get_dept_name(PDO $pdo, string $deptCode): string {
    try {
        $st = $pdo->prepare("SELECT name FROM departments WHERE UPPER(code) = UPPER(?) LIMIT 1");
        $st->execute([$deptCode]);
        return (string)($st->fetchColumn() ?: $deptCode);
    } catch (Throwable $e) {
        return $deptCode;
    }
}

/* =========================================================
   COURSE SUBMISSION HELPERS
========================================================= */

/**
 * Get or create a course submission record
 */
function qps_get_or_create_submission(PDO $pdo, array $data): int {
    $required = ['course_code', 'semester', 'academic_year', 'exam_type', 'staff_code'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new RuntimeException("Missing required field: {$field}");
        }
    }

    $programmeYear = $data['programme_year'] ?? '';
    $deptCode = $data['dept_code'] ?? '';

    $pdo->beginTransaction();
    try {
        // Try to find existing
        $st = $pdo->prepare("
            SELECT id FROM course_submissions
            WHERE course_code = ? AND semester = ? AND academic_year = ?
              AND exam_type = ? AND programme_year = ? AND staff_code = ?
        ");
        $st->execute([
            $data['course_code'],
            $data['semester'],
            $data['academic_year'],
            $data['exam_type'],
            $programmeYear,
            $data['staff_code']
        ]);
        $existing = $st->fetchColumn();

        if ($existing) {
            $pdo->commit();
            return (int)$existing;
        }

        // Create new
        $st2 = $pdo->prepare("
            INSERT INTO course_submissions
            (course_code, course_title, dept_code, dept_name, semester, academic_year,
             exam_type, programme_year, language, staff_code, total_marks,
             question_bank_status, master_blueprint_status, final_blueprint_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'draft', 'draft')
        ");
        $st2->execute([
            $data['course_code'],
            $data['course_title'] ?? null,
            $deptCode,
            $data['dept_name'] ?? null,
            $data['semester'],
            $data['academic_year'],
            $data['exam_type'],
            $programmeYear,
            $data['language'] ?? 'EN',
            $data['staff_code'],
            $data['total_marks'] ?? 75,
        ]);

        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Check if user can edit an item (draft, hod_corrections, dean_corrections only)
 */
function qps_can_edit_item(string $status): bool {
    $editable = ['draft', 'hod_corrections', 'dean_corrections'];
    return in_array($status, $editable, true);
}

/**
 * Check if item is locked (submitted or approved)
 */
function qps_is_item_locked(string $status): bool {
    $locked = ['submitted_to_hod', 'sent_to_dean', 'sent_to_coe', 'approved'];
    return in_array($status, $locked, true);
}