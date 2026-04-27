import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'input'];
    static values = {
        autoSubmit: { type: Boolean, default: false },
        debounce: { type: Number, default: 300 }
    };

    connect() {
        // Restore form state from URL on page load
        this.restoreFromUrl();

        // Listen for browser back/forward navigation
        window.addEventListener('popstate', () => this.restoreFromUrl());
    }

    disconnect() {
        if (this.debounceTimeout) {
            clearTimeout(this.debounceTimeout);
        }
    }

    restoreFromUrl() {
        const params = new URLSearchParams(window.location.search);

        this.inputTargets.forEach(input => {
            const name = input.name;
            if (!name) return;

            const value = params.get(name);

            if (input.type === 'checkbox') {
                input.checked = value === 'true' || value === '1' || value === 'on';
            } else if (input.type === 'radio') {
                input.checked = input.value === value;
            } else if (input.tagName === 'SELECT' && input.multiple) {
                const values = params.getAll(name);
                Array.from(input.options).forEach(option => {
                    option.selected = values.includes(option.value);
                });
            } else if (value !== null) {
                input.value = value;
            }
        });

        // Dispatch event for other controllers to react
        this.dispatch('restored', { detail: { params: Object.fromEntries(params) } });
    }

    updateUrl() {
        const params = new URLSearchParams();

        this.inputTargets.forEach(input => {
            const name = input.name;
            if (!name) return;

            let value;
            if (input.type === 'checkbox') {
                if (input.checked) {
                    value = 'true';
                }
            } else if (input.type === 'radio') {
                if (input.checked) {
                    value = input.value;
                }
            } else if (input.tagName === 'SELECT' && input.multiple) {
                Array.from(input.selectedOptions).forEach(option => {
                    params.append(name, option.value);
                });
                return;
            } else {
                value = input.value;
            }

            if (value !== undefined && value !== '') {
                params.set(name, value);
            }
        });

        // Update URL without page reload
        const newUrl = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
        window.history.pushState({}, '', newUrl);

        // Dispatch event for other controllers to react
        this.dispatch('updated', { detail: { params: Object.fromEntries(params) } });
    }

    inputChanged(event) {
        if (this.autoSubmitValue) {
            this.debouncedSubmit();
        } else {
            this.updateUrl();
        }
    }

    debouncedSubmit() {
        if (this.debounceTimeout) {
            clearTimeout(this.debounceTimeout);
        }

        this.debounceTimeout = setTimeout(() => {
            this.updateUrl();
            if (this.hasFormTarget) {
                this.formTarget.requestSubmit();
            }
        }, this.debounceValue);
    }

    submit(event) {
        event.preventDefault();
        this.updateUrl();

        if (this.hasFormTarget) {
            // Use Turbo to submit the form
            this.formTarget.requestSubmit();
        }
    }

    reset() {
        this.inputTargets.forEach(input => {
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = input.defaultChecked;
            } else if (input.tagName === 'SELECT') {
                Array.from(input.options).forEach(option => {
                    option.selected = option.defaultSelected;
                });
            } else {
                input.value = input.defaultValue;
            }
        });

        // Clear URL params
        window.history.pushState({}, '', window.location.pathname);

        this.dispatch('reset');
    }

    getParams() {
        return new URLSearchParams(window.location.search);
    }

    getParam(name) {
        return this.getParams().get(name);
    }

    setParam(name, value) {
        const params = this.getParams();
        if (value === null || value === undefined || value === '') {
            params.delete(name);
        } else {
            params.set(name, value);
        }
        const newUrl = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
        window.history.pushState({}, '', newUrl);
    }
}
