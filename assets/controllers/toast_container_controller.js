import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        // Listen for custom toast events
        document.addEventListener('sentinel:toast', this.handleToastEvent.bind(this));
    }

    disconnect() {
        document.removeEventListener('sentinel:toast', this.handleToastEvent.bind(this));
    }

    handleToastEvent(event) {
        const { type, title, message, autohide = true, delay = 5000 } = event.detail;
        this.show(type, title, message, autohide, delay);
    }

    show(type, title, message, autohide = true, delay = 5000) {
        const toastId = `toast-${Date.now()}`;
        const iconClass = this.getIconClass(type);
        const bgClass = this.getBgClass(type);

        const toastHtml = `
            <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="${autohide}" data-bs-delay="${delay}">
                <div class="toast-header ${bgClass} text-white">
                    <i class="bi ${iconClass} me-2"></i>
                    <strong class="me-auto">${this.escapeHtml(title)}</strong>
                    <small>Just now</small>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ${this.escapeHtml(message)}
                </div>
            </div>
        `;

        this.element.insertAdjacentHTML('beforeend', toastHtml);

        const toastElement = document.getElementById(toastId);
        const toast = new window.bootstrap.Toast(toastElement);

        // Remove from DOM after hidden
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });

        toast.show();
    }

    getIconClass(type) {
        const icons = {
            success: 'bi-check-circle-fill',
            error: 'bi-x-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            info: 'bi-info-circle-fill'
        };
        return icons[type] || icons.info;
    }

    getBgClass(type) {
        const classes = {
            success: 'bg-success',
            error: 'bg-danger',
            warning: 'bg-warning',
            info: 'bg-info'
        };
        return classes[type] || classes.info;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
