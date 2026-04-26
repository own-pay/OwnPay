(function() {
    // Ensure container exists
    function getToastContainer() {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.position = 'fixed';
            container.style.top = '10px';
            container.style.right = '10px';
            container.style.zIndex = '9999';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '10px';
            document.body.appendChild(container);
        }
        return container;
    }

    // Main function to create a toast
    window.createToast = function({ title = 'Notification', description = '', svg = '', timeout = 5000, top = 10 }) {
        const container = getToastContainer();
        container.style.top = top + 'px';

        const toast = document.createElement('div');
        toast.className = 'custom-toast toast align-items-center text-bg-white border-0 fade show';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        toast.style.position = 'relative';
        toast.style.boxShadow = '0 10px 15px rgba(31, 41, 55, 0.1), 0 4px 6px rgba(31, 41, 55, 0.05)';
        toast.style.transition = 'all 0.3s ease';

        toast.innerHTML = `
            <div style="padding: calc(.25rem * 4); gap: calc(.25rem * 1); display: grid;">
                <div class="t-head" style="display: flex; align-items: center;">
                    <span class="toast-svg" style="margin-right: 10px; display: flex; align-items: center;">
                        ${svg}
                    </span>
                    <span style="color: #000000;font-weight: 500;">${title}</span>
                    <button type="button" class="btn-close btn-close-black me-2 m-auto" aria-label="Close"></button>
                </div>
                <div class="t-body" style="margin-left: 30px;">${description}</div>
            </div>
        `;

        // Manual close
        toast.querySelector('.btn-close').addEventListener('click', () => {
            toast.remove();
        });

        // Prepend new toast
        container.prepend(toast);

        // Auto-remove after timeout
        setTimeout(() => {
            toast.remove();
        }, timeout);
    };
})();
