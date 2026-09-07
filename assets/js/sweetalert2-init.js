document.addEventListener('DOMContentLoaded', function () {
    if (!window.Swal) return;

    document.querySelectorAll('[data-confirm]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();

            Swal.fire({
                icon: 'warning',
                title: 'Are you sure?',
                text: link.getAttribute('data-confirm') || 'Please confirm this action.',
                showCancelButton: true,
                confirmButtonText: 'Yes, continue',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.isConfirmed) {
                    window.location.href = link.href;
                }
            });
        });
    });
});
