import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

export default class extends Controller {
    static values = {
        data: Object,
        thresholdYellow: { type: Number, default: 500 },
        thresholdRed: { type: Number, default: 1000 },
        showPercentiles: { type: Boolean, default: true },
        height: { type: Number, default: 120 }
    };

    chart = null;

    connect() {
        this.element.style.height = `${this.heightValue}px`;
        this.renderChart();
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }

    dataValueChanged() {
        if (this.chart) {
            this.updateChart();
        } else {
            this.renderChart();
        }
    }

    renderChart() {
        const ctx = this.element.getContext('2d');
        const data = this.dataValue || {};
        
        const labels = Object.keys(data).map(key => {
            const date = new Date(key);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        });

        const datasets = this.buildDatasets(data);

        const chartConfig = {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: this.getChartOptions()
        };

        this.chart = new Chart(ctx, chartConfig);
    }

    buildDatasets(data) {
        const values = Object.values(data);
        const avgData = values.map(v => v.avg || 0);
        
        const datasets = [
            {
                label: 'Avg Latency',
                data: avgData,
                borderColor: '#0d6efd',
                backgroundColor: this.createGradient('#0d6efd'),
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 4
            }
        ];

        if (this.showPercentilesValue) {
            const p95Data = values.map(v => v.p95 || 0);
            datasets.push({
                label: 'P95 Latency',
                data: p95Data,
                borderColor: '#6c757d',
                backgroundColor: 'transparent',
                borderWidth: 1,
                borderDash: [5, 5],
                fill: false,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 3
            });
        }

        return datasets;
    }

    createGradient(color) {
        const ctx = this.element.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, this.heightValue);
        gradient.addColorStop(0, this.hexToRgba(color, 0.3));
        gradient.addColorStop(1, this.hexToRgba(color, 0.0));
        return gradient;
    }

    getChartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: this.showPercentilesValue,
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        padding: 8,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 12,
                    displayColors: true,
                    callbacks: {
                        title: (items) => {
                            if (items.length > 0) {
                                const keys = Object.keys(this.dataValue);
                                return keys[items[0].dataIndex] || items[0].label;
                            }
                            return '';
                        },
                        label: (context) => {
                            return `${context.dataset.label}: ${context.parsed.y}ms`;
                        },
                        afterBody: (items) => {
                            if (items.length > 0) {
                                const keys = Object.keys(this.dataValue);
                                const key = keys[items[0].dataIndex];
                                const data = this.dataValue[key];
                                if (data && data.count) {
                                    return [`Requests: ${data.count}`];
                                }
                            }
                            return [];
                        }
                    }
                },
                annotation: {
                    annotations: this.getThresholdAnnotations()
                }
            },
            scales: {
                x: {
                    display: true,
                    grid: { display: false },
                    ticks: {
                        maxTicksLimit: 8,
                        color: '#6c757d',
                        font: { size: 10 }
                    }
                },
                y: {
                    display: true,
                    beginAtZero: true,
                    grid: { color: 'rgba(0, 0, 0, 0.05)' },
                    ticks: {
                        color: '#6c757d',
                        font: { size: 10 },
                        callback: (value) => `${value}ms`
                    }
                }
            }
        };
    }

    getThresholdAnnotations() {
        return {
            yellowLine: {
                type: 'line',
                yMin: this.thresholdYellowValue,
                yMax: this.thresholdYellowValue,
                borderColor: '#ffc107',
                borderWidth: 1,
                borderDash: [3, 3],
                label: {
                    display: false
                }
            },
            redLine: {
                type: 'line',
                yMin: this.thresholdRedValue,
                yMax: this.thresholdRedValue,
                borderColor: '#dc3545',
                borderWidth: 1,
                borderDash: [3, 3],
                label: {
                    display: false
                }
            }
        };
    }

    updateChart() {
        const data = this.dataValue || {};
        
        const labels = Object.keys(data).map(key => {
            const date = new Date(key);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        });

        const values = Object.values(data);
        const avgData = values.map(v => v.avg || 0);

        this.chart.data.labels = labels;
        this.chart.data.datasets[0].data = avgData;

        if (this.showPercentilesValue && this.chart.data.datasets[1]) {
            const p95Data = values.map(v => v.p95 || 0);
            this.chart.data.datasets[1].data = p95Data;
        }

        this.chart.update('none');
    }

    hexToRgba(hex, alpha) {
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }
}
