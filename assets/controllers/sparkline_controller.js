import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

export default class extends Controller {
    static values = {
        data: Object,
        label: { type: String, default: 'Value' },
        type: { type: String, default: 'line' },
        color: { type: String, default: '#0d6efd' }
    };

    chart = null;

    connect() {
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
        const values = Object.values(data);

        const chartConfig = {
            type: this.typeValue,
            data: {
                labels: labels,
                datasets: [{
                    label: this.labelValue,
                    data: values,
                    backgroundColor: this.typeValue === 'bar' 
                        ? this.hexToRgba(this.colorValue, 0.6)
                        : this.hexToRgba(this.colorValue, 0.1),
                    borderColor: this.colorValue,
                    borderWidth: this.typeValue === 'line' ? 2 : 0,
                    fill: this.typeValue === 'line',
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            title: (items) => {
                                if (items.length > 0) {
                                    const keys = Object.keys(this.dataValue);
                                    return keys[items[0].dataIndex] || items[0].label;
                                }
                                return '';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxTicksLimit: 8,
                            color: '#6c757d',
                            font: {
                                size: 11
                            }
                        }
                    },
                    y: {
                        display: true,
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            color: '#6c757d',
                            font: {
                                size: 11
                            },
                            callback: (value) => {
                                if (value >= 1000) {
                                    return (value / 1000).toFixed(1) + 'k';
                                }
                                return value;
                            }
                        }
                    }
                }
            }
        };

        this.chart = new Chart(ctx, chartConfig);
    }

    updateChart() {
        const data = this.dataValue || {};
        
        const labels = Object.keys(data).map(key => {
            const date = new Date(key);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        });
        const values = Object.values(data);

        this.chart.data.labels = labels;
        this.chart.data.datasets[0].data = values;
        this.chart.update('none');
    }

    hexToRgba(hex, alpha) {
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }
}
