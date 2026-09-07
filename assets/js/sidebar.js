document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('app');
    const sidebar = document.getElementById('appSidebar');
    const toggle = document.getElementById('sidebarToggle');

    if (!app || !sidebar || !toggle) {
        return;
    }

    toggle.addEventListener('click', () => {
        const collapsed = app.classList.toggle('sidebar-collapsed');
        toggle.setAttribute('aria-expanded', String(!collapsed));
    });
});
