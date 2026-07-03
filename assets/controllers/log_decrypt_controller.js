import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'decryptButton',
        'encryptedMessage',
        'decryptedContent',
        'errorMessage',
        'errorText',
        'requestSection',
        'requestHeadersSection',
        'requestHeaders',
        'requestBodySection',
        'requestBody',
        'responseSection',
        'responseHeadersSection',
        'responseHeaders',
        'responseBodySection',
        'responseBody'
    ];

    static values = {
        url: String
    };

    async decrypt(event) {
        event.preventDefault();

        const button = this.decryptButtonTarget;
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Decrypting...';

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Decryption failed');
            }

            this.encryptedMessageTarget.classList.add('d-none');
            this.decryptedContentTarget.classList.remove('d-none');
            button.classList.add('d-none');

            let hasRequest = false;
            let hasResponse = false;

            if (data.requestHeaders) {
                this.requestHeadersTarget.textContent = this.formatJson(data.requestHeaders);
                this.requestHeadersSectionTarget.classList.remove('d-none');
                hasRequest = true;
            }

            if (data.requestBody) {
                this.requestBodyTarget.textContent = this.formatJson(data.requestBody);
                this.requestBodySectionTarget.classList.remove('d-none');
                hasRequest = true;
            }

            if (hasRequest) {
                this.requestSectionTarget.classList.remove('d-none');
            }

            if (data.responseHeaders) {
                this.responseHeadersTarget.textContent = this.formatJson(data.responseHeaders);
                this.responseHeadersSectionTarget.classList.remove('d-none');
                hasResponse = true;
            }

            if (data.responseBody) {
                this.responseBodyTarget.textContent = this.formatJson(data.responseBody);
                this.responseBodySectionTarget.classList.remove('d-none');
                hasResponse = true;
            }

            if (hasResponse) {
                this.responseSectionTarget.classList.remove('d-none');
            }

            if (!hasRequest && !hasResponse) {
                this.decryptedContentTarget.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No content available after decryption.</div>';
            }

        } catch (error) {
            this.encryptedMessageTarget.classList.add('d-none');
            this.errorMessageTarget.classList.remove('d-none');
            this.errorTextTarget.textContent = error.message;
            button.disabled = false;
            button.innerHTML = originalText;
        }
    }

    formatJson(str) {
        try {
            const parsed = JSON.parse(str);
            return JSON.stringify(parsed, null, 2);
        } catch {
            return str;
        }
    }
}
