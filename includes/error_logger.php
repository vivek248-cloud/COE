<?php
/** Central PHP error log for COE diagnostics. */
if (!function_exists('qps_write_error_log')) {
    function qps_write_error_log(string $kind, string $message, string $file = '', int $line = 0, string $trace = ''): void {
        try {
            $dir = dirname(__DIR__) . '/storage/private';
            if (!is_dir($dir)) @mkdir($dir, 0770, true);
            $path = $dir . '/qps-error-log.jsonl';
            if (is_file($path) && filesize($path) > 5 * 1024 * 1024) @rename($path, $path . '.' . date('Ymd-His') . '.bak');
            $user = $_SESSION['user'] ?? [];
            $entry = [
                'time' => date('Y-m-d H:i:s'), 'level' => strtoupper($kind),
                'message' => substr($message, 0, 5000), 'file' => $file, 'line' => $line,
                'staff_code' => (string)($user['staff_code'] ?? 'SYSTEM'),
                'staff_name' => (string)($user['name'] ?? 'System'),
                'role' => (string)($user['role'] ?? ($_SESSION['qps_static_role'] ?? 'SYSTEM')),
                'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'trace' => substr($trace, 0, 12000)
            ];
            @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $ignored) {}
    }
}
if (empty($GLOBALS['qps_error_logger_registered'])) {
    $GLOBALS['qps_error_logger_registered'] = true;
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        qps_write_error_log('PHP ' . $severity, (string)$message, (string)$file, (int)$line);
        return false; // preserve PHP's normal handling
    });
    register_shutdown_function(function () {
        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            qps_write_error_log('FATAL', (string)$last['message'], (string)$last['file'], (int)$last['line']);
        }
    });
}
