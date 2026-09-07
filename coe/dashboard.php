<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_role('COE_STAFF');

$title = 'COE Dashboard';
require __DIR__ . '/../includes/layout/header.php';
?>
<div class="card">
    <h1>COE Staff Dashboard</h1>
    <p>Welcome, <?= htmlspecialchars((string)$_SESSION['username'], ENT_QUOTES, 'UTF-8') ?>.</p>
    <p><strong>Phase 1 authentication is active.</strong></p>

    <h3>Coming in later phases</h3>
    <ul>
        <li>Question Bank</li>
        <li>Question review/edit/approval</li>
        <li>K-level assignment</li>
        <li>Blueprint management</li>
        <li>Paper generation</li>
        <li>Generated papers</li>
        <li>COE history/audit</li>
    </ul>
</div>
<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
