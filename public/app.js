let stations = [];

const listEl = document.getElementById('list');
const countEl = document.getElementById('count');
const searchEl = document.getElementById('search');
const fuelEl = document.getElementById('fuel');

const FUEL_META = {
    '92': { label: 'АИ-92', reportable: true, type: 'fuel' },
    '95': { label: 'АИ-95', reportable: true, type: 'fuel' },
    '95_pulsar': { label: '95 Pulsar', reportable: true, type: 'fuel' },
    '100': { label: 'АИ-100', reportable: true, type: 'fuel' },

    'diesel_summer': { label: 'ДТ', reportable: true, type: 'fuel' },
    'diesel_winter': { label: 'ДТ (зима)', reportable: true, type: 'fuel' },
    'diesel_pulsar_summer': { label: 'ДТ Pulsar', reportable: true, type: 'fuel' },
    'diesel_pulsar_winter': { label: 'ДТ Pulsar (зима)', reportable: true, type: 'fuel' },

    'chademo_90': { label: 'CHAdeMO 90 кВт', reportable: false, type: 'charge' },
    'combo2_120': { label: 'CCS Combo 2 120 кВт', reportable: false, type: 'charge' },
    'gbt_120': { label: 'GB/T 120 кВт', reportable: false, type: 'charge' }
};

function metaForFuel(key) {
    if (FUEL_META[key]) {
        return FUEL_META[key];
    }

    return {
        label: key.replaceAll('_', ' '),
        reportable: false,
        type: 'other'
    };
}

function escapeHtml(text) {
    return String(text ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function availabilityName(value) {
    const names = {
        yes: 'есть',
        no: 'нет',
        unknown: 'неизвестно'
    };

    return names[value] || 'неизвестно';
}

function queueName(value) {
    const names = {
        none: 'очереди нет',
        small: 'до 5 машин',
        medium: '5–15 машин',
        big: 'больше 15 машин',
        unknown: 'неизвестно'
    };

    return names[value] || 'неизвестно';
}

function getReportableFuels(station) {
    return (station.prices || []).filter(p => metaForFuel(p.key).reportable);
}

function getChargeItems(station) {
    return (station.prices || []).filter(p => metaForFuel(p.key).type === 'charge');
}

function reportHtml(station) {
    const report = station.latest_report;
    const reportableFuels = getReportableFuels(station);

    if (!report) {
        return `
            <div class="report report-empty">
                Наличие топлива и очередь пока не подтверждены пользователями
            </div>
        `;
    }

    const lines = reportableFuels.map(item => {
        const meta = metaForFuel(item.key);
        const value = report.fuel_statuses?.[item.key] || 'unknown';
        return `<div class="report-line"><span>${meta.label}</span><b>${availabilityName(value)}</b></div>`;
    }).join('');

    return `
        <div class="report">
            <div class="report-title">По данным пользователей</div>
            ${lines}
            <div class="report-line"><span>Очередь</span><b>${queueName(report.queue_level)}</b></div>
            ${report.comment ? `<div class="report-comment">Комментарий: ${escapeHtml(report.comment)}</div>` : ''}
            <div class="report-time">Отчёт: ${escapeHtml(report.created_at || '')}</div>
        </div>
    `;
}

function render() {
    const query = searchEl.value.trim().toLowerCase();
    const fuelFilter = fuelEl.value;

    const filtered = stations.filter(station => {
        const text = [
            station.number,
            station.name,
            station.address
        ].join(' ').toLowerCase();

        if (query && !text.includes(query)) {
            return false;
        }

        if (fuelFilter) {
            const reportableFuels = getReportableFuels(station);
            if (!reportableFuels.some(p => p.key === fuelFilter)) {
                return false;
            }
        }

        return true;
    });

    countEl.textContent = `Показано АЗС: ${filtered.length}`;
    listEl.innerHTML = '';

    for (const station of filtered) {
        const reportableFuels = getReportableFuels(station);
        const chargeItems = getChargeItems(station);

        const pricesHtml = reportableFuels.length
            ? reportableFuels.map(p => {
                const meta = metaForFuel(p.key);
                return `<span class="price">${meta.label} — ${Number(p.price).toFixed(2)} ₽</span>`;
            }).join('')
            : '<span class="price">Данные по топливу не найдены</span>';

        const chargingHtml = chargeItems.length
            ? `
                <div class="charging-block">
                    <div class="section-title">Электрозарядка</div>
                    <div class="charging-list">
                        ${chargeItems.map(p => {
                            const meta = metaForFuel(p.key);
                            return `<span class="charge-badge">${meta.label} — ${Number(p.price).toFixed(2)} ₽</span>`;
                        }).join('')}
                    </div>
                </div>
            `
            : '';

        const div = document.createElement('div');
        div.className = 'station';

        div.innerHTML = `
            <div class="station-title">
                ${(station.brand || 'АЗС')} ${(station.number || '')}
            </div>

            <div class="address">
                ${escapeHtml(station.address || 'Адрес не указан')}
            </div>

            <div class="section-title">Топливо</div>
            <div class="prices">
                ${pricesHtml}
            </div>

            ${chargingHtml}

            ${reportHtml(station)}

            <button class="report-button" data-station-id="${station.id}">
                Сообщить ситуацию
            </button>

            <div class="meta">
                Цены обновлены: ${escapeHtml(station.updated_at || 'неизвестно')}
                <br>
                ID: ${escapeHtml(station.external_id || '')}
            </div>
        `;

        listEl.appendChild(div);
    }

    document.querySelectorAll('.report-button').forEach(button => {
        button.addEventListener('click', () => {
            const stationId = Number(button.dataset.stationId);
            const station = stations.find(s => s.id === stationId);
            if (station) {
                openReportForm(station);
            }
        });
    });
}

function selectAvailability(id, label, selected = 'unknown') {
    return `
        <label>
            ${escapeHtml(label)}
            <select id="${id}">
                <option value="unknown" ${selected === 'unknown' ? 'selected' : ''}>Не знаю</option>
                <option value="yes" ${selected === 'yes' ? 'selected' : ''}>Есть</option>
                <option value="no" ${selected === 'no' ? 'selected' : ''}>Нет</option>
            </select>
        </label>
    `;
}

function openReportForm(station) {
    const reportableFuels = getReportableFuels(station);
    const latest = station.latest_report?.fuel_statuses || {};

    const fuelFieldsHtml = reportableFuels.length
        ? reportableFuels.map(item => {
            const meta = metaForFuel(item.key);
            return selectAvailability(
                `report_fuel_${item.key}`,
                meta.label,
                latest[item.key] || 'unknown'
            );
        }).join('')
        : '<div class="modal-note">Для этой АЗС не найдено подходящих видов топлива для отчёта.</div>';

    const modal = document.createElement('div');
    modal.className = 'modal-backdrop';

    modal.innerHTML = `
        <div class="modal">
            <h2>Сообщить ситуацию</h2>
            <div class="modal-address">
                ${(station.brand || 'АЗС')} ${(station.number || '')}<br>
                ${escapeHtml(station.address || '')}
            </div>

            <div class="section-title">Наличие топлива</div>
            ${fuelFieldsHtml}

            <label>
                Очередь
                <select id="report_queue">
                    <option value="unknown">Не знаю</option>
                    <option value="none">Очереди нет</option>
                    <option value="small">До 5 машин</option>
                    <option value="medium">5–15 машин</option>
                    <option value="big">Больше 15 машин</option>
                </select>
            </label>

            <label>
                Комментарий
                <textarea id="report_comment" maxlength="300" placeholder="Например: 95 только по талонам"></textarea>
            </label>

            <div class="modal-actions">
                <button id="send_report">Отправить</button>
                <button id="close_report" class="secondary">Закрыть</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    document.getElementById('close_report').addEventListener('click', () => {
        modal.remove();
    });

    document.getElementById('send_report').addEventListener('click', async () => {
        const fuelStatuses = {};

        for (const item of reportableFuels) {
            const el = document.getElementById(`report_fuel_${item.key}`);
            if (el) {
                fuelStatuses[item.key] = el.value;
            }
        }

        const payload = {
            station_id: station.id,
            fuel_statuses: fuelStatuses,
            queue_level: document.getElementById('report_queue').value,
            comment: document.getElementById('report_comment').value
        };

        const response = await fetch('/report.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!response.ok) {
            alert(data.error || 'Ошибка отправки');
            return;
        }

        modal.remove();
        await load();
        alert('Спасибо, отчёт сохранён');
    });
}

async function load() {
    try {
        const response = await fetch('/api.php?t=' + Date.now());
        const data = await response.json();

        stations = data.items || [];
        render();
    } catch (e) {
        countEl.textContent = 'Ошибка загрузки данных';
        console.error(e);
    }
}

searchEl.addEventListener('input', render);
fuelEl.addEventListener('change', render);

load();
setInterval(load, 30000);