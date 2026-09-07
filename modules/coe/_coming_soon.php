<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

$page_title = $page_title ?? 'COE Portal';
$current_page = $current_page ?? '';

require_once dirname(__DIR__, 2) . '/includes/layout/header.php';
require_once dirname(__DIR__, 2) . '/includes/layout/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__, 2) . '/includes/layout/navbar.php'; ?>

    <main class="app-content">
        <div class="page-heading">
            <div>
                <h1><?= e($page_title) ?></h1>
                <p>This module URL is active and ready for development.</p>
            </div>
        </div>

        <div class="card-panel">
            <div class="card-panel-header">
                <h5 class="mb-1">Module ready</h5>
                <p class="text-muted small mb-0">
                    This page is a working route placeholder. Its full functionality will be implemented in its planned phase.
                </p>
            </div>
            <div class="card-panel-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                    <div>
                        <strong>URL working correctly</strong>
                        <div class="text-muted small">No authentication is required during the development phases.</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php require_once dirname(__DIR__, 2) . '/includes/layout/footer.php'; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout/scripts.php'; ?>
