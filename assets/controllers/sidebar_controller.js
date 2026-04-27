import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [];

    // Touch tracking
    touchStartX = 0;
    touchStartY = 0;
    touchEndX = 0;
    isSwiping = false;

    // Focus trap
    focusableElements = null;
    firstFocusable = null;
    lastFocusable = null;
    previousActiveElement = null;

    connect() {
        this.sidebar = document.getElementById('sidebar');
        this.mainContent = document.getElementById('main-content');
        this.overlay = document.querySelector('.sidebar-overlay');
        this.menuButton = document.querySelector('[data-action="sidebar#show"]');

        // Restore collapsed state from localStorage
        if (localStorage.getItem('sidebar-collapsed') === 'true') {
            this.sidebar.classList.add('collapsed');
            this.mainContent.classList.add('sidebar-collapsed');
        }

        // Set up touch event listeners for swipe gestures
        this.setupTouchListeners();

        // Set up keyboard event listeners
        this.setupKeyboardListeners();
    }

    disconnect() {
        this.removeTouchListeners();
        this.removeKeyboardListeners();
    }

    setupTouchListeners() {
        // Bind methods to preserve context
        this.handleTouchStart = this.handleTouchStart.bind(this);
        this.handleTouchMove = this.handleTouchMove.bind(this);
        this.handleTouchEnd = this.handleTouchEnd.bind(this);

        document.addEventListener('touchstart', this.handleTouchStart, { passive: true });
        document.addEventListener('touchmove', this.handleTouchMove, { passive: false });
        document.addEventListener('touchend', this.handleTouchEnd, { passive: true });
    }

    removeTouchListeners() {
        document.removeEventListener('touchstart', this.handleTouchStart);
        document.removeEventListener('touchmove', this.handleTouchMove);
        document.removeEventListener('touchend', this.handleTouchEnd);
    }

    setupKeyboardListeners() {
        this.handleKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.handleKeydown);
    }

    removeKeyboardListeners() {
        document.removeEventListener('keydown', this.handleKeydown);
    }

    handleTouchStart(event) {
        this.touchStartX = event.touches[0].clientX;
        this.touchStartY = event.touches[0].clientY;
        this.isSwiping = false;
    }

    handleTouchMove(event) {
        if (!this.touchStartX) return;

        const currentX = event.touches[0].clientX;
        const currentY = event.touches[0].clientY;
        const diffX = currentX - this.touchStartX;
        const diffY = currentY - this.touchStartY;

        // Only consider horizontal swipes (more horizontal than vertical movement)
        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 10) {
            this.isSwiping = true;
            this.touchEndX = currentX;

            // Prevent vertical scrolling during horizontal swipe
            if (Math.abs(diffX) > 30) {
                event.preventDefault();
            }
        }
    }

    handleTouchEnd() {
        if (!this.isSwiping) {
            this.resetTouch();
            return;
        }

        const diffX = this.touchEndX - this.touchStartX;
        const threshold = 80; // Minimum swipe distance
        const isOpen = this.sidebar.classList.contains('show');

        // Swipe right to open (only from left edge)
        if (diffX > threshold && this.touchStartX < 50 && !isOpen) {
            this.show();
        }
        // Swipe left to close
        else if (diffX < -threshold && isOpen) {
            this.close();
        }

        this.resetTouch();
    }

    resetTouch() {
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.touchEndX = 0;
        this.isSwiping = false;
    }

    handleKeydown(event) {
        const isOpen = this.sidebar.classList.contains('show');

        // Escape key closes sidebar on mobile
        if (event.key === 'Escape' && isOpen) {
            event.preventDefault();
            this.close();
            return;
        }

        // Focus trap when sidebar is open on mobile
        if (isOpen && event.key === 'Tab') {
            this.handleFocusTrap(event);
        }
    }

    handleFocusTrap(event) {
        // Get all focusable elements within sidebar
        this.focusableElements = this.sidebar.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );

        if (this.focusableElements.length === 0) return;

        this.firstFocusable = this.focusableElements[0];
        this.lastFocusable = this.focusableElements[this.focusableElements.length - 1];

        // Shift+Tab on first element -> go to last
        if (event.shiftKey && document.activeElement === this.firstFocusable) {
            event.preventDefault();
            this.lastFocusable.focus();
        }
        // Tab on last element -> go to first
        else if (!event.shiftKey && document.activeElement === this.lastFocusable) {
            event.preventDefault();
            this.firstFocusable.focus();
        }
    }

    toggle() {
        this.sidebar.classList.toggle('collapsed');
        this.mainContent.classList.toggle('sidebar-collapsed');

        // Save state to localStorage
        localStorage.setItem('sidebar-collapsed', this.sidebar.classList.contains('collapsed'));

        // Update aria-expanded on toggle button
        const toggleBtn = this.sidebar.querySelector('[data-action="sidebar#toggle"]');
        if (toggleBtn) {
            const isCollapsed = this.sidebar.classList.contains('collapsed');
            toggleBtn.setAttribute('aria-expanded', !isCollapsed);
        }
    }

    show() {
        // Store the element that had focus before opening
        this.previousActiveElement = document.activeElement;

        this.sidebar.classList.add('show');
        this.overlay.classList.add('show');
        document.body.classList.add('focus-trap-active');

        // Update aria-expanded on menu button
        if (this.menuButton) {
            this.menuButton.setAttribute('aria-expanded', 'true');
        }

        // Focus the first focusable element in sidebar
        requestAnimationFrame(() => {
            const firstLink = this.sidebar.querySelector('.nav-link');
            if (firstLink) {
                firstLink.focus();
            }
        });

        // Announce to screen readers
        this.announceToScreenReader('Navigation menu opened');
    }

    close() {
        this.sidebar.classList.remove('show');
        this.overlay.classList.remove('show');
        document.body.classList.remove('focus-trap-active');

        // Update aria-expanded on menu button
        if (this.menuButton) {
            this.menuButton.setAttribute('aria-expanded', 'false');
        }

        // Restore focus to the element that opened the sidebar
        if (this.previousActiveElement) {
            this.previousActiveElement.focus();
            this.previousActiveElement = null;
        }

        // Announce to screen readers
        this.announceToScreenReader('Navigation menu closed');
    }

    announceToScreenReader(message) {
        const announcement = document.createElement('div');
        announcement.setAttribute('role', 'status');
        announcement.setAttribute('aria-live', 'polite');
        announcement.setAttribute('aria-atomic', 'true');
        announcement.className = 'sr-only';
        announcement.textContent = message;

        document.body.appendChild(announcement);

        // Remove after announcement
        setTimeout(() => {
            announcement.remove();
        }, 1000);
    }
}
