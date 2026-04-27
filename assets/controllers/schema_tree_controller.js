import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle(event) {
        const toggleElement = event.currentTarget;
        const contentElement = toggleElement.nextElementSibling;
        const iconElement = toggleElement.querySelector('.tree-icon');

        if (contentElement && contentElement.classList.contains('tree-content')) {
            contentElement.classList.toggle('d-none');
            
            if (iconElement) {
                if (contentElement.classList.contains('d-none')) {
                    iconElement.classList.remove('bi-chevron-down');
                    iconElement.classList.add('bi-chevron-right');
                } else {
                    iconElement.classList.remove('bi-chevron-right');
                    iconElement.classList.add('bi-chevron-down');
                }
            }
        }
    }

    expandAll() {
        this.element.querySelectorAll('.tree-content').forEach(content => {
            content.classList.remove('d-none');
        });
        this.element.querySelectorAll('.tree-icon').forEach(icon => {
            icon.classList.remove('bi-chevron-right');
            icon.classList.add('bi-chevron-down');
        });
    }

    collapseAll() {
        this.element.querySelectorAll('.tree-content').forEach(content => {
            content.classList.add('d-none');
        });
        this.element.querySelectorAll('.tree-icon').forEach(icon => {
            icon.classList.remove('bi-chevron-down');
            icon.classList.add('bi-chevron-right');
        });
    }
}
