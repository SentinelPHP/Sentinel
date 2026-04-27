import { Controller } from '@hotwired/stimulus';

/**
 * Loading Controller
 * 
 * Handles loading states for buttons and forms, and manages skeleton screens.
 * 
 * Usage:
 * - Button loading: <button data-controller="loading" data-action="click->loading#start">Submit</button>
 * - Form loading: <form data-controller="loading" data-action="submit->loading#start">
 * - Auto-stop on Turbo navigation: Automatically stops loading when page changes
 */
export default class extends Controller {
    static targets = ['button', 'skeleton', 'content'];
    static values = {
        text: { type: String, default: 'Loading...' },
        timeout: { type: Number, default: 30000 } // Auto-stop after 30 seconds
    };

    connect() {
        this.originalContent = new Map();
        this.timeoutId = null;

        // Listen for Turbo events to auto-stop loading
        document.addEventListener('turbo:before-render', this.stop.bind(this));
        document.addEventListener('turbo:frame-render', this.stop.bind(this));
    }

    disconnect() {
        this.stop();
        document.removeEventListener('turbo:before-render', this.stop.bind(this));
        document.removeEventListener('turbo:frame-render', this.stop.bind(this));
    }

    /**
     * Start loading state
     */
    start(event) {
        const target = event?.currentTarget || this.element;

        if (target.tagName === 'BUTTON' || target.tagName === 'A') {
            this.startButtonLoading(target);
        } else if (target.tagName === 'FORM') {
            const submitBtn = target.querySelector('[type="submit"]');
            if (submitBtn) {
                this.startButtonLoading(submitBtn);
            }
        }

        // Show skeleton if available
        if (this.hasSkeletonTarget) {
            this.showSkeleton();
        }

        // Auto-stop after timeout
        this.timeoutId = setTimeout(() => {
            this.stop();
        }, this.timeoutValue);
    }

    /**
     * Stop loading state
     */
    stop() {
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }

        // Restore all buttons
        this.originalContent.forEach((content, button) => {
            button.classList.remove('btn-loading');
            button.disabled = false;
            button.innerHTML = content;
        });
        this.originalContent.clear();

        // Hide skeleton, show content
        if (this.hasSkeletonTarget) {
            this.hideSkeleton();
        }
    }

    /**
     * Start loading state on a button
     */
    startButtonLoading(button) {
        if (button.classList.contains('btn-loading')) return;

        // Store original content
        this.originalContent.set(button, button.innerHTML);

        // Add loading class and disable
        button.classList.add('btn-loading');
        button.disabled = true;

        // Wrap content in span for visibility toggle
        button.innerHTML = `<span class="btn-text">${button.innerHTML}</span>`;
    }

    /**
     * Show skeleton screen
     */
    showSkeleton() {
        this.skeletonTargets.forEach(skeleton => {
            skeleton.classList.remove('d-none');
            skeleton.setAttribute('aria-busy', 'true');
        });

        if (this.hasContentTarget) {
            this.contentTargets.forEach(content => {
                content.classList.add('d-none');
            });
        }
    }

    /**
     * Hide skeleton screen
     */
    hideSkeleton() {
        this.skeletonTargets.forEach(skeleton => {
            skeleton.classList.add('d-none');
            skeleton.setAttribute('aria-busy', 'false');
        });

        if (this.hasContentTarget) {
            this.contentTargets.forEach(content => {
                content.classList.remove('d-none');
            });
        }
    }
}
