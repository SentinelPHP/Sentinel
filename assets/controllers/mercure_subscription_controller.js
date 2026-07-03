import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        hubUrl: String,
        topics: Array,
        pollingInterval: { type: Number, default: 30000 },
        maxRetries: { type: Number, default: 2 },
        pollingEndpoint: { type: String, default: '/api/dashboard/events/recent' },
        notificationEvents: { type: Array, default: ['drift_detected', 'health_change', 'threshold_exceeded'] }
    };

    static outlets = ['connection-status'];

    connect() {
        this.retryCount = 0;
        this.retryDelay = 1000;
        this.isPolling = false;
        this.lastEventTimestamp = Date.now();
        this.eventSource = null;
        this.pollingTimer = null;

        if (this.hasHubUrlValue && this.hubUrlValue) {
            this.connectToMercure();
        } else {
            console.warn('Mercure hub URL not configured, falling back to polling');
            this.startPolling();
        }
    }

    disconnect() {
        this.closeEventSource();
        this.stopPolling();
    }

    connectToMercure() {
        try {
            const url = new URL(this.hubUrlValue);

            this.topicsValue.forEach(topic => {
                url.searchParams.append('topic', topic);
            });

            this.eventSource = new EventSource(url, { withCredentials: false });

            this.eventSource.onopen = () => {
                this.retryCount = 0;
                this.retryDelay = 1000;
                this.isPolling = false;
                this.stopPolling();
                this.updateConnectionStatus('connected');
                console.log('Mercure connection established');
            };

            this.eventSource.onmessage = (event) => {
                this.handleMessage(event);
            };

            this.eventSource.onerror = (error) => {
                this.handleError(error);
            };
        } catch (error) {
            console.error('Failed to connect to Mercure:', error);
            this.startPolling();
        }
    }

    handleMessage(event) {
        try {
            const data = JSON.parse(event.data);
            this.lastEventTimestamp = Date.now();
            this.dispatchEvent(data);
        } catch (error) {
            console.error('Failed to parse Mercure message:', error);
        }
    }

    handleError(error) {
        console.warn('Mercure connection error:', error);
        this.updateConnectionStatus('reconnecting');

        if (this.eventSource?.readyState === EventSource.CLOSED) {
            this.retryCount++;

            if (this.retryCount <= this.maxRetriesValue) {
                const delay = Math.min(this.retryDelay * Math.pow(2, this.retryCount - 1), 30000);
                console.log(`Reconnecting to Mercure in ${delay}ms (attempt ${this.retryCount}/${this.maxRetriesValue})`);

                setTimeout(() => {
                    this.closeEventSource();
                    this.connectToMercure();
                }, delay);
            } else {
                console.warn('Max Mercure retries exceeded, falling back to polling');
                this.closeEventSource();
                this.startPolling();
            }
        }
    }

    startPolling() {
        if (this.isPolling) return;

        this.isPolling = true;
        this.updateConnectionStatus('polling');
        console.log('Starting polling fallback');

        this.poll();
        this.pollingTimer = setInterval(() => this.poll(), this.pollingIntervalValue);
    }

    stopPolling() {
        if (this.pollingTimer) {
            clearInterval(this.pollingTimer);
            this.pollingTimer = null;
        }
        this.isPolling = false;
    }

    async poll() {
        try {
            const url = new URL(this.pollingEndpointValue, window.location.origin);
            url.searchParams.set('since', new Date(this.lastEventTimestamp).toISOString());

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`Polling failed: ${response.status}`);
            }

            const events = await response.json();

            if (Array.isArray(events) && events.length > 0) {
                events.forEach(event => this.dispatchEvent(event));
                this.lastEventTimestamp = Date.now();
            }

            this.updateConnectionStatus('polling');
        } catch (error) {
            console.error('Polling error:', error);
            this.updateConnectionStatus('disconnected');
        }
    }

    dispatchEvent(data) {
        const eventType = data.type || 'unknown';
        const toastConfig = this.getToastConfig(data);

        if (toastConfig) {
            document.dispatchEvent(new CustomEvent('sentinel:toast', {
                detail: toastConfig
            }));
        }

        document.dispatchEvent(new CustomEvent('sentinel:realtime', {
            detail: { type: eventType, data }
        }));

        document.dispatchEvent(new CustomEvent(`sentinel:${eventType}`, {
            detail: data
        }));
    }

    getToastConfig(data) {
        const eventType = data.type;
        
        if (!this.shouldShowNotification(eventType)) {
            return null;
        }

        switch (eventType) {
            case 'drift_detected':
                return {
                    type: this.getSeverityType(data.severity),
                    title: 'Drift Detected',
                    message: `${data.severity} drift on ${data.host || 'unknown'} - ${data.path || ''}`,
                    autohide: true,
                    delay: 8000
                };

            case 'health_status_change':
                return {
                    type: this.getHealthStatusType(data.newStatus),
                    title: 'Service Health Changed',
                    message: `${data.host}: ${data.oldStatus} → ${data.newStatus}`,
                    autohide: true,
                    delay: 6000
                };

            case 'threshold_exceeded':
                return {
                    type: 'warning',
                    title: 'Threshold Exceeded',
                    message: `${data.host}: ${data.metric} is ${data.value} (threshold: ${data.threshold})`,
                    autohide: true,
                    delay: 6000
                };

            default:
                return null;
        }
    }

    shouldShowNotification(eventType) {
        const eventMap = {
            'drift_detected': 'drift_detected',
            'health_status_change': 'health_change',
            'threshold_exceeded': 'threshold_exceeded'
        };
        
        const mappedEvent = eventMap[eventType];
        return mappedEvent && this.notificationEventsValue.includes(mappedEvent);
    }

    getSeverityType(severity) {
        switch (severity) {
            case 'critical': return 'error';
            case 'warning': return 'warning';
            case 'info': return 'info';
            default: return 'info';
        }
    }

    getHealthStatusType(status) {
        switch (status) {
            case 'red': return 'error';
            case 'yellow': return 'warning';
            case 'green': return 'success';
            default: return 'info';
        }
    }

    updateConnectionStatus(status) {
        if (this.hasConnectionStatusOutlet) {
            this.connectionStatusOutlets.forEach(outlet => {
                outlet.setStatus(status);
            });
        }

        document.dispatchEvent(new CustomEvent('sentinel:connection-status', {
            detail: { status, isPolling: this.isPolling }
        }));
    }

    closeEventSource() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }

    retryMercure() {
        if (this.isPolling) {
            this.stopPolling();
            this.retryCount = 0;
            this.retryDelay = 1000;
            this.connectToMercure();
        }
    }
}
