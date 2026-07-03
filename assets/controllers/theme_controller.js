import { Controller } from '@hotwired/stimulus';

/**
 * Theme controller for managing light/dark mode.
 * 
 * Reads the user's theme preference and applies it to the document.
 * Supports 'light', 'dark', and 'system' (follows OS preference).
 * Persists the theme in localStorage for instant load on page refresh.
 */
export default class extends Controller {
    static values = {
        preference: { type: String, default: 'light' }
    };

    static targets = ['toggle'];

    connect() {
        this.applyTheme(this.getEffectiveTheme());
        
        if (this.preferenceValue === 'system') {
            this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            this.mediaQueryHandler = this.onSystemThemeChange.bind(this);
            this.mediaQuery.addEventListener('change', this.mediaQueryHandler);
        }
    }

    disconnect() {
        if (this.mediaQuery && this.mediaQueryHandler) {
            this.mediaQuery.removeEventListener('change', this.mediaQueryHandler);
        }
    }

    preferenceValueChanged() {
        this.applyTheme(this.getEffectiveTheme());
        
        if (this.mediaQuery && this.mediaQueryHandler) {
            this.mediaQuery.removeEventListener('change', this.mediaQueryHandler);
            this.mediaQuery = null;
            this.mediaQueryHandler = null;
        }
        
        if (this.preferenceValue === 'system') {
            this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            this.mediaQueryHandler = this.onSystemThemeChange.bind(this);
            this.mediaQuery.addEventListener('change', this.mediaQueryHandler);
        }
    }

    toggle() {
        const currentTheme = document.documentElement.getAttribute('data-bs-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        this.applyTheme(newTheme);
        localStorage.setItem('sentinel-theme', newTheme);
    }

    setTheme(event) {
        const theme = event.currentTarget.dataset.theme;
        if (theme) {
            this.preferenceValue = theme;
            this.applyTheme(this.getEffectiveTheme());
            localStorage.setItem('sentinel-theme-preference', theme);
        }
    }

    getEffectiveTheme() {
        const stored = localStorage.getItem('sentinel-theme-preference');
        const preference = stored || this.preferenceValue;
        
        if (preference === 'system') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        
        return preference;
    }

    applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        
        if (this.hasToggleTarget) {
            const icon = this.toggleTarget.querySelector('i');
            if (icon) {
                icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
            }
        }
    }

    onSystemThemeChange(event) {
        if (this.preferenceValue === 'system') {
            this.applyTheme(event.matches ? 'dark' : 'light');
        }
    }
}
