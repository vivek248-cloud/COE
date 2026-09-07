<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? APP_NAME;
$activeMenu = $activeMenu ?? '';
$pageCss = $pageCss ?? [];
$pageJs = $pageJs ?? [];

$userName = $_SESSION['username'] ?? 'User';
$userRole = $_SESSION['role'] ?? '';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= e($pageTitle) ?> - <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>/assets/css/layout.css"
    >

    <?php foreach ((array) $pageCss as $css): ?>

        <link
            rel="stylesheet"
            href="<?= BASE_URL ?>/assets/css/<?= e($css) ?>"
        >

    <?php endforeach; ?>

</head>


<body>

<div class="qps-app">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <?php require __DIR__ . '/header.php'; ?>


    <!-- =====================================================
         MAIN LAYOUT
    ====================================================== -->

    <div class="qps-layout">


        <!-- =================================================
             SIDEBAR
        ================================================== -->

        <?php require __DIR__ . '/sidebar.php'; ?>


        <!-- =================================================
             PAGE CONTENT
        ================================================== -->

        <main class="qps-main">

            <div class="qps-content">

                <?= $content ?? '' ?>

            </div>

        </main>


    </div>


    <!-- =====================================================
         FOOTER
    ====================================================== -->

    <?php require __DIR__ . '/footer.php'; ?>


</div>


<!-- =========================================================
     SIDEBAR MOBILE SCRIPT
========================================================= -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const toggle =
        document.getElementById('qpsSidebarToggle');

    const sidebar =
        document.getElementById('qpsSidebar');

    const overlay =
        document.getElementById('qpsSidebarOverlay');


    if (!toggle || !sidebar) {
        return;
    }


    toggle.addEventListener('click', function () {

        sidebar.classList.toggle('is-open');

        if (overlay) {

            overlay.classList.toggle(
                'is-visible'
            );

        }

    });


    if (overlay) {

        overlay.addEventListener('click', function () {

            sidebar.classList.remove(
                'is-open'
            );

            overlay.classList.remove(
                'is-visible'
            );

        });

    }

});

</script>


<!-- =========================================================
     PAGE JAVASCRIPT
========================================================= -->

<?php foreach ((array) $pageJs as $js): ?>

    <script
        src="<?= BASE_URL ?>/assets/js/<?= e($js) ?>"
    ></script>

<?php endforeach; ?>


</body>

</html>