<?php
declare(strict_types=1);
?>

<header class="qps-header">

    <div class="qps-header-left">

        <button
            type="button"
            class="qps-sidebar-toggle"
            id="qpsSidebarToggle"
            aria-label="Toggle navigation"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

        <a
            href="<?= BASE_URL ?>/"
            class="qps-brand"
        >

            <div class="qps-brand-icon">
                QP
            </div>

            <div class="qps-brand-text">

                <span class="qps-brand-title">
                    Question Paper System
                </span>

                <span class="qps-brand-subtitle">
                    Examination Management
                </span>

            </div>

        </a>

    </div>


    <div class="qps-header-right">

        <div class="qps-user">

            <div class="qps-user-avatar">
                <?= e(strtoupper(substr($userName, 0, 1))) ?>
            </div>

            <div class="qps-user-details">

                <span class="qps-user-name">
                    <?= e($userName) ?>
                </span>

                <span class="qps-user-role">
                    <?= e(str_replace('_', ' ', $userRole)) ?>
                </span>

            </div>

        </div>


        <a
            href="<?= BASE_URL ?>/logout.php"
            class="qps-header-logout"
        >
            Logout
        </a>

    </div>

</header>