<header class="app-navbar">
    <div class="navbar-left">
        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
            aria-expanded="true"
        >
            <i class="bi bi-list"></i>
        </button>

        <div>
            <div class="navbar-eyebrow">Administration</div>
            <div class="navbar-title"><?= e($page_title ?? 'Dashboard') ?></div>
        </div>
    </div>

    <div class="navbar-right">
        <span class="portal-badge">
            <i class="bi bi-shield-check"></i>
            COE Portal
        </span>

        <div class="user-placeholder" title="Authentication will be added later">
            <span class="user-avatar">C</span>
            <span class="d-none d-md-inline">COE Staff</span>
        </div>
    </div>
</header>
