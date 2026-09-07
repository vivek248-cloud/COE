<?php
declare(strict_types=1);

$isTeaching = ($userRole === 'TEACHING_STAFF');
$isCoe = ($userRole === 'COE_STAFF');
?>

<div
    class="qps-sidebar-overlay"
    id="qpsSidebarOverlay"
></div>


<aside
    class="qps-sidebar"
    id="qpsSidebar"
>

    <div class="qps-sidebar-inner">


        <!-- =================================================
             TEACHING STAFF
        ================================================== -->

        <?php if ($isTeaching): ?>

            <div class="qps-sidebar-section">

                <div class="qps-sidebar-label">
                    Teaching
                </div>


                <a
                    href="<?= BASE_URL ?>/teaching/dashboard.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'dashboard' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ▦
                    </span>

                    <span>
                        Dashboard
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/teaching/question-bank.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'question-bank' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ▤
                    </span>

                    <span>
                        Question Bank
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/teaching/question-add.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'add-question' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ＋
                    </span>

                    <span>
                        Add Question
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/teaching/submissions.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'submissions' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ⇧
                    </span>

                    <span>
                        Submissions
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/teaching/history.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'history' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ◷
                    </span>

                    <span>
                        History
                    </span>

                </a>

            </div>

        <?php endif; ?>


        <!-- =================================================
             COE STAFF
        ================================================== -->

        <?php if ($isCoe): ?>

            <div class="qps-sidebar-section">

                <div class="qps-sidebar-label">
                    COE
                </div>


                <a
                    href="<?= BASE_URL ?>/coe/dashboard.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'dashboard' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ▦
                    </span>

                    <span>
                        Dashboard
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/coe/question-bank.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'question-bank' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ▤
                    </span>

                    <span>
                        Question Bank
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/coe/review.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'review' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ✓
                    </span>

                    <span>
                        Review Questions
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/coe/blueprints.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'blueprints' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ▥
                    </span>

                    <span>
                        Blueprints
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/coe/generate.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'generate' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ⚙
                    </span>

                    <span>
                        Generate Paper
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/coe/generated-papers.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'generated-papers' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ▣
                    </span>

                    <span>
                        Generated Papers
                    </span>

                </a>


                <a
                    href="<?= BASE_URL ?>/coe/history.php"
                    class="qps-sidebar-link
                    <?= $activeMenu === 'history' ? 'active' : '' ?>"
                >

                    <span class="qps-sidebar-icon">
                        ◷
                    </span>

                    <span>
                        History / Audit
                    </span>

                </a>

            </div>

        <?php endif; ?>


        <!-- =================================================
             ACCOUNT
        ================================================== -->

        <div class="qps-sidebar-section qps-sidebar-account">

            <div class="qps-sidebar-label">
                Account
            </div>


            <a
                href="<?= BASE_URL ?>/logout.php"
                class="qps-sidebar-link qps-sidebar-logout"
            >

                <span class="qps-sidebar-icon">
                    ↪
                </span>

                <span>
                    Logout
                </span>

            </a>

        </div>


    </div>

</aside>