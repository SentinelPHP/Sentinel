import './stimulus_bootstrap.js';
import './styles/app.scss';

// Bootstrap JS
import * as bootstrap from 'bootstrap';

// Make Bootstrap available globally for Stimulus controllers
window.bootstrap = bootstrap;

// Initialize Bootstrap components on Turbo navigation
// Turbo is loaded via @symfony/ux-turbo in controllers.json
document.addEventListener('turbo:load', () => {
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

    // Initialize Bootstrap popovers
    const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
    popoverTriggerList.forEach(el => new bootstrap.Popover(el));
});

// Preserve scroll position on Turbo navigation
let scrollPositions = {};

document.addEventListener('turbo:before-visit', (event) => {
    scrollPositions[window.location.href] = window.scrollY;
});

document.addEventListener('turbo:load', () => {
    const scrollPosition = scrollPositions[window.location.href];
    if (scrollPosition) {
        window.scrollTo(0, scrollPosition);
    }
});

// Handle Turbo loading states
document.addEventListener('turbo:before-fetch-request', () => {
    document.body.classList.add('turbo-loading');
});

document.addEventListener('turbo:before-fetch-response', () => {
    document.body.classList.remove('turbo-loading');
});
