import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['expected', 'actual'];

    connect() {
        this.highlightDifferences();
    }

    highlightDifferences() {
        const codeBlocks = this.element.querySelectorAll('pre code');
        codeBlocks.forEach(block => {
            block.classList.add('diff-highlighted');
        });
    }

    copyPath(event) {
        const path = event.currentTarget.dataset.path;
        if (path && navigator.clipboard) {
            navigator.clipboard.writeText(path).then(() => {
                const originalText = event.currentTarget.textContent;
                event.currentTarget.textContent = 'Copied!';
                setTimeout(() => {
                    event.currentTarget.textContent = originalText;
                }, 1000);
            });
        }
    }

    toggleExpand(event) {
        const target = event.currentTarget.closest('.diff-node');
        if (target) {
            target.classList.toggle('collapsed');
        }
    }
}
