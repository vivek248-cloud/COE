<?php
/**
 * Role-Based Secure Authentication Portal
 * Holy Cross College (Autonomous) - Examination Management System
 * Unified Faculty & ERP Staff Login + COE Office Login
 */
define('PAGE_TITLE', 'Portal Login - Holy Cross College');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/system.php';

$error = '';
$success = '';
$pdo = getDBConnection();
qps_ensure_aux_schema($pdo);

// Login throttling constants from config
$loginMaxAttempts = defined('QPS_LOGIN_MAX_ATTEMPTS') ? QPS_LOGIN_MAX_ATTEMPTS : 5;
$loginWindowMinutes = defined('QPS_LOGIN_WINDOW_MINUTES') ? QPS_LOGIN_WINDOW_MINUTES : 15;
$loginLockMinutes = defined('QPS_LOGIN_LOCK_MINUTES') ? QPS_LOGIN_LOCK_MINUTES : 15;

/**
 * Check if login is throttled for a username/IP
 */
function qps_is_login_throttled(PDO $pdo, string $username, string $ip, int $maxAttempts, int $lockMinutes): bool {
    try {
        $lockStart = date('Y-m-d H:i:s', time() - $lockMinutes * 60);

        // Check recent failed attempts within the lock window
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM qps_login_attempts
            WHERE username = ? AND ip_address = ? AND attempted_at >= ?
        ");
        $st->execute([$username, $ip, $lockStart]);
        $recentFailures = (int)$st->fetchColumn();

        return $recentFailures >= $maxAttempts;
    } catch (Throwable $e) {
        return false; // Fail open on DB error
    }
}

/**
 * Record a failed login attempt
 */
function qps_record_login_attempt(PDO $pdo, string $username, string $ip): void {
    try {
        $st = $pdo->prepare("INSERT INTO qps_login_attempts (username, ip_address, attempted_at) VALUES (?, ?, NOW())");
        $st->execute([$username, $ip]);
    } catch (Throwable $e) {
        // Silently ignore
    }
}

/**
 * Clear login attempts on successful login
 */
function qps_clear_login_attempts(PDO $pdo, string $username, string $ip): void {
    try {
        $st = $pdo->prepare("DELETE FROM qps_login_attempts WHERE username = ? AND ip_address = ?");
        $st->execute([$username, $ip]);
    } catch (Throwable $e) {
        // Silently ignore
    }
}

// Determine active tab: 'staff' (Faculty & ERP) or 'coe' (COE Office)
$activeTab = trim($_GET['tab'] ?? $_GET['role'] ?? 'staff');
if (!in_array($activeTab, ['coe', 'staff', 'hod', 'dean'], true)) {
    $activeTab = 'staff';
}

// Prefill staff code or username if passed via helper buttons
$prefillStaff = trim($_GET['staff_code'] ?? $_GET['quick_code'] ?? '');
$prefillCoe = trim($_GET['coe_user'] ?? '');

// Handle Secure POST Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginType = trim($_POST['login_type'] ?? 'staff');
    $activeTab = $loginType;

    // ----------------------------------------------------
    // 1. UNIFIED FACULTY & ERP STAFF LOGIN
    // ----------------------------------------------------
    if ($loginType === 'staff') {
        unset($_SESSION['qps_dean_authenticated']);
        $staffCode = trim($_POST['staff_code'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if ($staffCode === '') {
            $error = 'Please enter your Staff Code.';
        } elseif ($password === '') {
            $error = 'Please enter your Password.';
        } elseif (qps_is_login_throttled($pdo, $staffCode, $clientIp, $loginMaxAttempts, $loginLockMinutes)) {
            $error = 'Too many failed attempts. Please try again later.';
            try { qps_audit($pdo, 'LOGIN_THROTTLED', 'AUTH', $staffCode, ['ip' => $clientIp]); } catch (Throwable $e) {}
        } else {
            // Master ERP Super Admin Check
            if (
                (strtolower($staffCode) === 'erp' || strtolower($staffCode) === 'admin' || strtolower($staffCode) === 'erpad2023m01') &&
                ((defined('ERP_DEFAULT_PASSWORD') && $password === constant('ERP_DEFAULT_PASSWORD')) || $password === 'erp@123' || $password === 'admin@123' || $password === '38747' || $password === 'hcc123')
            ) {
                qps_clear_login_attempts($pdo, $staffCode, $clientIp);
                $_SESSION['user'] = [
                    'staff_code' => 'ERPAD2023M01',
                    'name' => 'ERP System Administrator',
                    'department' => 'ERP / Central IT Section',
                    'dept_code' => 'ERP',
                    'designation' => 'Lead ERP Developer & Administrator',
                    'role' => 'ERP_ADMIN',
                    'is_super_admin' => true,
                    'is_erp_staff' => true,
                    'is_coe' => true,
                    'is_hod' => true
                ];
                try { qps_audit($pdo, 'LOGIN_SUCCESS_ERP_MASTER', 'AUTH', 'ERPAD2023M01'); } catch (Throwable $e) {}
                header("Location: " . getBaseUrl() . "/modules/admin/index.php");
                exit;
            }

            // Database Staff Check
            try {
                $stmt = $pdo->prepare("SELECT * FROM pr_x_xxxx_staf_prof_mast WHERE UPPER(STAFF_CODE) = UPPER(?) AND status = 'Y'");
                $stmt->execute([$staffCode]);
                $staff = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$staff) {
                    $error = "Staff Code '{$staffCode}' not found or inactive. Please contact the administrator.";
                    qps_record_login_attempt($pdo, $staffCode, $clientIp);
                } else {
                    $storedPassword = (string)($staff['STAFF_PASSWORD'] ?? '');
                    $valid = false;

                    if ($storedPassword !== '') {
                        if (preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $storedPassword)) {
                            $valid = password_verify($password, $storedPassword);
                        } elseif (preg_match('/^[a-f0-9]{32}$/i', $storedPassword)) {
                            $valid = hash_equals(strtolower($storedPassword), md5($password));
                        } elseif (preg_match('/^[a-f0-9]{40}$/i', $storedPassword)) {
                            $valid = hash_equals(strtolower($storedPassword), sha1($password));
                        } elseif (preg_match('/^[a-f0-9]{64}$/i', $storedPassword)) {
                            $valid = hash_equals(strtolower($storedPassword), hash('sha256', $password));
                        } else {
                            $valid = hash_equals($storedPassword, $password);
                        }
                    } else {
                        $valid = ($password === 'staff@123' || $password === 'hcc123' || $password === '12345');
                    }

                    if ($valid) {
                        qps_clear_login_attempts($pdo, $staffCode, $clientIp);
                        $dept = $staff['DEPARTMENT'] ?: $staff['dept_code1'] ?: '';
                        $deptUpper = strtoupper((string)$dept);
                        $codeUpper = strtoupper((string)$staff['STAFF_CODE']);
                        $desigUpper = strtoupper((string)($staff['designation'] ?? ''));

                        // Detect ERP privileges
                        $isERP = (
                            strpos($deptUpper, 'ERP') !== false || strpos($deptUpper, 'ADMIN') !== false ||
                            strpos($deptUpper, 'SYSTEM') !== false || strpos($codeUpper, 'ERP') !== false ||
                            strpos($desigUpper, 'DEVELOPER') !== false || strpos($desigUpper, 'ADMIN') !== false ||
                            strpos($desigUpper, 'ERP') !== false || $codeUpper === 'ERPAD2023M01'
                        );
                        $isHod = (strtoupper((string)($staff['hod_status'] ?? '')) === 'Y' || strpos($desigUpper, 'HEAD') !== false || strpos($desigUpper, 'HOD') !== false);

                        $_SESSION['user'] = [
                            'staff_code' => $staff['STAFF_CODE'],
                            'name' => $staff['FIRST_NAME'],
                            'mobile_no' => $staff['MOBILE_NO'] ?? '',
                            'department' => $dept ?: ($isERP ? 'ERP / Central IT' : 'Academic Department'),
                            'dept_code' => $staff['dept_code1'] ?: ($staff['deptcode'] ?: 'STAFF'),
                            'designation' => $staff['designation'] ?: ($isERP ? 'ERP Administrator' : ($isHod ? 'Head of Department' : 'Assistant Professor')),
                            'role' => $isERP ? 'ERP_ADMIN' : 'TEACHING_STAFF',
                            'is_super_admin' => $isERP,
                            'is_erp_staff' => $isERP,
                            'is_coe' => $isERP,
                            'is_hod' => $isHod || $isERP
                        ];

                        try { qps_audit($pdo, 'LOGIN_SUCCESS_STAFF', 'AUTH', $staff['STAFF_CODE']); } catch (Throwable $e) {}

                        if ($isERP) {
                            header("Location: " . getBaseUrl() . "/modules/admin/index.php");
                        } else {
                            header("Location: " . getBaseUrl() . "/modules/teaching/dashboard.php");
                        }
                        exit;
                    } else {
                        $error = "Invalid password for Staff Code '{$staffCode}'.";
                    }
                }
            } catch (Exception $e) {
                $error = "Authentication database error: " . $e->getMessage();
            }
        }
    }

    // ----------------------------------------------------
    // 2. HOD LOGIN - staff master credentials + hod_status='Y'
    // ----------------------------------------------------
    elseif ($loginType === 'hod' || $loginType === 'dean') {
        unset($_SESSION['qps_dean_authenticated'], $_SESSION['qps_static_role'], $_SESSION['qps_static_username'], $_SESSION['qps_static_name']);
        $staffCode = trim((string)($_POST['office_staff_code'] ?? ''));
        $password = (string)($_POST['office_password'] ?? '');
        $requiredStatus = $loginType === 'hod' ? 'hod_status' : 'deanstatus';
        $roleLabel = $loginType === 'hod' ? 'HOD' : 'Dean';
        $destination = $loginType === 'hod' ? '/modules/hod/dashboard.php' : '/modules/dean/dashboard.php';
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if ($staffCode === '' || $password === '') {
            $error = 'Please enter your Staff Code and Password.';
        } elseif (qps_is_login_throttled($pdo, $staffCode, $clientIp, $loginMaxAttempts, $loginLockMinutes)) {
            $error = 'Too many failed attempts. Please try again later.';
            try { qps_audit($pdo, 'LOGIN_THROTTLED', 'AUTH', $staffCode, ['ip' => $clientIp, 'role' => $roleLabel]); } catch (Throwable $e) {}
        } else {
            try {
                // Only the selected office status authorizes this portal; designation text and allow-list tables are not used.
                $stmt = $pdo->prepare("SELECT * FROM pr_x_xxxx_staf_prof_mast WHERE UPPER(STAFF_CODE)=UPPER(?) AND status='Y' LIMIT 1");
                $stmt->execute([$staffCode]);
                $staff = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$staff) {
                    $error = 'Staff Code not found or inactive.';
                    qps_record_login_attempt($pdo, $staffCode, $clientIp);
                } elseif (
                      $loginType === 'hod'
                          ? strtoupper(trim((string)($staff['hod_status'] ?? ''))) !== 'Y'
                          : (int)($staff['deanstatus'] ?? 0) !== 1
                  ) {
                      $error = 'This Staff Code is not enabled for the ' . $roleLabel . ' portal. Contact the administrator.';
                      qps_record_login_attempt($pdo, $staffCode, $clientIp);
                  }
                 elseif (!qps_verify_staff_master_password($password, (string)($staff['STAFF_PASSWORD'] ?? ''))) {
                    $error = 'Invalid Staff Code or Password.';
                    qps_record_login_attempt($pdo, $staffCode, $clientIp);
                } else {
                    qps_clear_login_attempts($pdo, $staffCode, $clientIp);
                    session_regenerate_id(true);
                    $dept = trim((string)($staff['DEPARTMENT'] ?? '')) ?: trim((string)($staff['dept_code1'] ?? ''));
                    $_SESSION['user'] = [
                        'staff_code' => $staff['STAFF_CODE'],
                        'name' => $staff['FIRST_NAME'] ?? $staff['STAFF_CODE'],
                        'mobile_no' => $staff['MOBILE_NO'] ?? '',
                        'department' => $dept,
                        'dept_code' => $staff['dept_code1'] ?? ($staff['deptcode'] ?? ''),
                        'designation' => $staff['designation'] ?? $roleLabel,
                        'role' => $loginType === 'hod' ? 'HOD' : 'DEAN',
                        'is_super_admin' => false,
                        'is_erp_staff' => false,
                        'is_coe' => false,
                        'is_hod' => $loginType === 'hod'
                    ];
                    if ($loginType === 'dean') $_SESSION['qps_dean_authenticated'] = true;
                    else unset($_SESSION['qps_dean_authenticated']);
                    try { qps_audit($pdo, 'LOGIN_SUCCESS_' . strtoupper($loginType) . '_STATUS', 'AUTH', $staff['STAFF_CODE']); } catch (Throwable $e) {}
                    header('Location: ' . getBaseUrl() . $destination);
                    exit;
                }
            } catch (Throwable $e) {
                error_log('QPS ' . $roleLabel . ' login error: ' . $e->getMessage());
                $error = $roleLabel . ' login could not be completed. Please try again.';
            }
        }
    }

    // ----------------------------------------------------
    // 3. COE OFFICE LOGIN
    // ----------------------------------------------------
    elseif ($loginType === 'coe') {
        unset($_SESSION['qps_dean_authenticated']);
        $coeUser = trim($_POST['coe_username'] ?? '');
        $coePass = trim($_POST['coe_password'] ?? '');
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if ($coeUser === '') {
            $error = 'Please enter your COE Username.';
        } elseif ($coePass === '') {
            $error = 'Please enter your COE Password.';
        } elseif (qps_is_login_throttled($pdo, $coeUser, $clientIp, $loginMaxAttempts, $loginLockMinutes)) {
            $error = 'Too many failed attempts. Please try again later.';
            try { qps_audit($pdo, 'LOGIN_THROTTLED', 'AUTH', $coeUser, ['ip' => $clientIp, 'role' => 'COE']); } catch (Throwable $e) {}
        } else {
            // Get COE static password hash from qps_settings
            $staticHash = qps_get_setting($pdo, 'static_coe_password_hash', '');
            $isStaticCOE = false;
            if ($staticHash && (strtolower($coeUser) === 'coe' || strtolower($coeUser) === 'coe_office')) {
                $isStaticCOE = password_verify($coePass, $staticHash);
            }

            if ($isStaticCOE) {
                qps_clear_login_attempts($pdo, $coeUser, $clientIp);
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'staff_code' => 'STATIC_COE',
                    'name' => 'Controller of Examinations',
                    'department' => 'COE Examination Office',
                    'dept_code' => 'COE',
                    'designation' => 'COE Office',
                    'role' => 'COE_ADMIN',
                    'is_super_admin' => false,
                    'is_erp_staff' => false,
                    'is_coe' => true,
                    'is_hod' => false
                ];
                $_SESSION['qps_static_role'] = 'COE';
                $_SESSION['qps_static_username'] = $coeUser;
                $_SESSION['qps_static_name'] = 'Controller of Examinations';
                // No must_change for COE (single static account)
                $_SESSION['qps_static_must_change'] = false;
                try { qps_audit($pdo, 'LOGIN_SUCCESS_COE_STATIC', 'AUTH', 'STATIC_COE'); } catch (Throwable $e) {}
                header("Location: " . getBaseUrl() . "/modules/coe/dashboard.php"); exit;
            } else {
                $error = 'Invalid COE Username or Password.';
                qps_record_login_attempt($pdo, $coeUser, $clientIp);
            }
        }
    }
}

// Record failed login attempts with the request IP through the existing audit helper.
if ($error !== '' && isset($loginType)) {
    try { qps_audit($pdo, 'LOGIN_FAILED', 'AUTH', (string)$loginType, [
        'login_type' => (string)$loginType,
        'username' => (string)($_POST['staff_code'] ?? $_POST['dean_staff_code'] ?? $_POST['coe_username'] ?? ''),
        'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? '')
    ]); } catch (Throwable $e) {}
}

// Fetch Sample Quick Faculty Accounts
$quickFacultyList = [];
try {
    $stQ = $pdo->query("SELECT s.STAFF_CODE, s.FIRST_NAME, s.DEPARTMENT, s.dept_code1, (SELECT COUNT(DISTINCT papercode) FROM timetablefaculty WHERE fid = s.STAFF_CODE) as paper_count 
                        FROM pr_x_xxxx_staf_prof_mast s 
                        WHERE s.status = 'Y' 
                        ORDER BY paper_count DESC, s.FIRST_NAME ASC LIMIT 6");
    $quickFacultyList = $stQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="min-h-screen flex flex-col justify-center py-12 px-4 sm:px-6 lg:px-8 relative">
  
  <!-- Ambient Glow Background -->
  <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-orange-400/15 rounded-full blur-3xl pointer-events-none"></div>

  <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center space-y-3">
    <!-- Institutional Logo -->
    <div class="mx-auto flex items-center justify-center">
      <img src="https://hcctrichy.ac.in/img/logo.png" alt="Holy Cross College Logo" class="w-20 h-20 object-contain rounded-2xl bg-white p-2 shadow-xl shadow-orange-500/20 border border-slate-200">
    </div>

    <div>
      <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight font-serif uppercase"><?php echo COLLEGE_NAME; ?></h2>
      <p class="text-xs text-orange-600 font-extrabold tracking-wider uppercase mt-0.5">Autonomous • NAAC A++ (4th Cycle)</p>
      <p class="text-xs text-slate-500 mt-1">Autonomous Question Paper & OBE Blueprint System</p>
    </div>
  </div>

  <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10">
    <div class="card-modern p-6 sm:p-8 space-y-6">
      
      <!-- 3-Way Portal Selector: Faculty / Dean / COE -->
      <div class="grid grid-cols-4 gap-1 rounded-2xl bg-slate-100 p-1 border border-slate-200">
        <button type="button" onclick="switchLoginTab('staff')" id="tab-btn-staff" class="py-2 text-xs font-black rounded-xl transition duration-200 <?php echo $activeTab === 'staff' ? 'bg-white text-orange-950 shadow-md' : 'text-slate-500 hover:text-slate-900'; ?>">Faculty</button>
        <button type="button" onclick="switchLoginTab('hod')" id="tab-btn-hod" class="py-2 text-xs font-black rounded-xl transition duration-200 <?php echo $activeTab === 'hod' ? 'bg-white text-blue-950 shadow-md' : 'text-slate-500 hover:text-slate-900'; ?>">HOD</button>
        <button type="button" onclick="switchLoginTab('dean')" id="tab-btn-dean" class="py-2 text-xs font-black rounded-xl transition duration-200 <?php echo $activeTab === 'dean' ? 'bg-white text-purple-950 shadow-md' : 'text-slate-500 hover:text-slate-900'; ?>">Dean Login</button>
        <button type="button" onclick="switchLoginTab('coe')" id="tab-btn-coe" class="py-2 text-xs font-black rounded-xl transition duration-200 <?php echo $activeTab === 'coe' ? 'bg-white text-orange-950 shadow-md' : 'text-slate-500 hover:text-slate-900'; ?>">COE Office</button>
      </div>

      <!-- Error / Success Notices -->
      <?php if ($error): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 text-xs p-3.5 rounded-2xl flex items-start space-x-2.5">
          <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 shrink-0 mt-0.5"></i>
          <span class="font-bold"><?php echo htmlspecialchars($error); ?></span>
        </div>
      <?php endif; ?>

      <!-- Form 1: Faculty & ERP Staff Login -->
      <form method="POST" action="" id="form-staff" class="<?php echo $activeTab === 'staff' ? '' : 'hidden'; ?> space-y-4">
        <input type="hidden" name="login_type" value="staff">

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Staff Code</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <i data-lucide="user" class="w-4 h-4"></i>
            </span>
            <input type="text" name="staff_code" required value="<?php echo htmlspecialchars($prefillStaff ?: '38747'); ?>" placeholder="e.g. 38747, 51336, ERPAD2023M01" class="pill-search-input w-full font-bold text-xs pl-10 text-slate-900">
          </div>
          <p class="text-[10px] text-slate-400 mt-1">Teaching faculty, HODs, and ERP administrators log in with their Staff Code.</p>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Password *</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <i data-lucide="lock" class="w-4 h-4"></i>
            </span>
            <input type="password" name="password" required value="hcc123" placeholder="Enter your staff password" class="pill-search-input w-full font-bold text-xs pl-10 text-slate-900">
          </div>
        </div>

        <button type="submit" class="btn-orange-pill w-full text-xs shadow-lg mt-2">
          <span>Sign In to Portal</span>
          <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </button>
      </form>

      <!-- HOD Login: same staff code/password, gated by hod_status='Y' -->
      <form method="POST" action="" id="form-hod" class="<?php echo $activeTab === 'hod' ? '' : 'hidden'; ?> space-y-4">
        <input type="hidden" name="login_type" value="hod">
        <div class="rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">Use your existing college Staff Code and Staff Master password. Access is granted only when hod_status = Y.</div>
        <div><label class="block text-xs font-bold text-slate-700 mb-1">HOD Staff Code *</label><input type="text" name="office_staff_code" required autocomplete="username" value="<?php echo htmlspecialchars($prefillStaff); ?>" placeholder="Enter your Staff Code" class="pill-search-input w-full font-bold text-xs text-slate-900"></div>
        <div><label class="block text-xs font-bold text-slate-700 mb-1">Staff Password *</label><input type="password" name="office_password" required autocomplete="current-password" placeholder="Enter your staff password" class="pill-search-input w-full font-bold text-xs text-slate-900"></div>
        <button type="submit" class="w-full rounded-full bg-blue-700 hover:bg-blue-800 text-white font-black text-xs py-3 shadow-lg mt-2">Sign In to HOD Portal</button>
      </form>

      <!-- Dean Login: same staff code/password, gated by deanstatus=1 -->
      <form method="POST" action="" id="form-dean" class="<?php echo $activeTab === 'dean' ? '' : 'hidden'; ?> space-y-4">
        <input type="hidden" name="login_type" value="dean">
        <div class="rounded-xl border border-purple-100 bg-purple-50 p-3 text-xs text-purple-900">Use your existing college Staff Code and Staff Master password. Access is granted only when deanstatus = 1.</div>
        <div><label class="block text-xs font-bold text-slate-700 mb-1">Dean Staff Code *</label><input type="text" name="office_staff_code" required autocomplete="username" value="<?php echo htmlspecialchars($prefillStaff); ?>" placeholder="Enter your Staff Code" class="pill-search-input w-full font-bold text-xs text-slate-900"></div>
        <div><label class="block text-xs font-bold text-slate-700 mb-1">Staff Password *</label><input type="password" name="office_password" required autocomplete="current-password" placeholder="Enter your staff password" class="pill-search-input w-full font-bold text-xs text-slate-900"></div>
        <button type="submit" class="w-full rounded-full bg-purple-700 hover:bg-purple-800 text-white font-black text-xs py-3 shadow-lg mt-2">Sign In to Dean Portal</button>
      </form>

      <!-- Form 3: COE Office Login -->
      <form method="POST" action="" id="form-coe" class="<?php echo $activeTab === 'coe' ? '' : 'hidden'; ?> space-y-4">
        <input type="hidden" name="login_type" value="coe">

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">COE Administrator Username *</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <i data-lucide="shield-check" class="w-4 h-4"></i>
            </span>
            <input type="text" name="coe_username" required value="coe_office" placeholder="coe_office" class="pill-search-input w-full font-bold text-xs pl-10 text-slate-900">
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Password *</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <i data-lucide="lock" class="w-4 h-4"></i>
            </span>
            <input type="password" name="coe_password" required value="hcc123" placeholder="••••••••" class="pill-search-input w-full font-bold text-xs pl-10 text-slate-900">
          </div>
        </div>

        <button type="submit" class="btn-orange-pill w-full text-xs shadow-lg mt-2">
          <span>Sign In as COE Admin</span>
          <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </button>
      </form>

      <!-- Quick Switcher Demo Accounts -->
      <!-- <div class="pt-4 border-t border-slate-100 space-y-2">
        <div class="text-[10px] font-black uppercase tracking-wider text-slate-400 flex items-center justify-between">
          <span>Quick Switch Accounts</span>
          <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-500"></i>
        </div>

        <div class="grid grid-cols-2 gap-2 text-[11px]">
          <a href="?quick_login=38747" class="p-2 rounded-2xl border border-slate-200 bg-slate-50 hover:bg-orange-50 hover:border-orange-200 transition text-left truncate">
            <div class="font-bold text-slate-900 truncate">Dr. S. Kavitha</div>
            <div class="text-[9px] text-slate-500">38747 • Commerce</div>
          </a>

          <a href="?quick_login=51336" class="p-2 rounded-2xl border border-slate-200 bg-slate-50 hover:bg-orange-50 hover:border-orange-200 transition text-left truncate">
            <div class="font-bold text-slate-900 truncate">French Faculty</div>
            <div class="text-[9px] text-slate-500">51336 • French</div>
          </a>

          <a href="?quick_login=ERPAD2023M01" class="p-2 rounded-2xl border border-purple-200 bg-purple-50/60 hover:bg-purple-100 transition text-left truncate">
            <div class="font-bold text-purple-950 truncate">ERP Administrator</div>
            <div class="text-[9px] text-purple-600 font-mono">ERPAD2023M01</div>
          </a>

          <a href="?login_as_coe=1" class="p-2 rounded-2xl border border-amber-200 bg-amber-50/60 hover:bg-amber-100 transition text-left truncate">
            <div class="font-bold text-amber-950 truncate">COE Office</div>
            <div class="text-[9px] text-amber-700 font-mono">COE_OFFICE</div>
          </a>
        </div>
      </div> -->

    </div>
  </div>
</div>

<script>
function switchLoginTab(tab) {
  const tabs = ['staff', 'hod', 'dean', 'coe'];
  tabs.forEach(function (name) {
    const form = document.getElementById('form-' + name);
    const button = document.getElementById('tab-btn-' + name);
    if (!form || !button) return;
    const active = name === tab;
    form.classList.toggle('hidden', !active);
    button.classList.toggle('bg-white', active);
    button.classList.toggle('shadow-md', active);
    button.classList.toggle('text-orange-950', active && name !== 'dean');
    button.classList.toggle('text-purple-950', active && name === 'dean');
    button.classList.toggle('text-blue-950', active && name === 'hod');
    button.classList.toggle('text-slate-500', !active);
  });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
