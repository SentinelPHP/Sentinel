import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['indicator', 'text'];

    connect() {
        this.currentStatus = 'unknown';
        this.checkConnection();

        this.interval = setInterval(() => this.checkConnection(), 30000);

        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.setStatus('disconnected'));

        document.addEventListener('sentinel:connection-status', (event) => {
            this.setStatus(event.detail.status);
        });
    }

    disconnect() {
        if (this.interval) {
            clearInterval(this.interval);
        }
    }

    async checkConnection() {
        if (!navigator.onLine) {
            this.setStatus('disconnected');
            return;
        }

        if (this.currentStatus === 'connected' || this.currentStatus === 'polling') {
            return;
        }

        try {
            const response = await fetch('/health', {
                method: 'HEAD',
                cache: 'no-cache'
            });

            if (response.ok) {
                if (this.currentStatus === 'unknown') {
                    this.setStatus('connected');
                }
            } else {
                this.setStatus('degraded');
            }
        } catch (error) {
            this.setStatus('disconnected');
        }
    }

    handleOnline() {
        if (this.currentStatus === 'disconnected') {
            this.setStatus('reconnecting');
        }
    }

    setStatus(status) {
        this.currentStatus = status;

        const configs = {
            connected: {
                className: 'status-indicator status-green me-2',
                text: 'Connected',
                title: 'Real-time updates active via Mercure'
            },
            polling: {
                className: 'status-indicator status-blue me-2',
                text: 'Polling',
                title: 'Polling for updates (Mercure unavailable)'
            },
            reconnecting: {
                className: 'status-indicator status-yellow me-2',
                text: 'Reconnecting...',
                title: 'Attempting to reconnect to real-time updates'
            },
            degraded: {
                className: 'status-indicator status-yellow me-2',
                text: 'Degraded',
                title: 'Connection degraded'
            },
            disconnected: {
                className: 'status-indicator status-red me-2',
                text: 'Disconnected',
                title: 'No connection to server'
            }
        };

        const config = configs[status] || configs.disconnected;

        if (this.hasIndicatorTarget) {
            this.indicatorTarget.className = config.className;
            this.indicatorTarget.title = config.title;
        }

        if (this.hasTextTarget) {
            this.textTarget.textContent = config.text;
            this.textTarget.title = config.title;
        }
    }

    setOnline() {
        this.setStatus('connected');
    }

    setOffline() {
        this.setStatus('disconnected');
    }

    setDegraded() {
        this.setStatus('degraded');
    }

    setReconnecting() {
        this.setStatus('reconnecting');
    }
}
