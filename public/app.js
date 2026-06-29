let stations = [];

const listEl = document.getElementById('list');
const countEl = document.getElementById('count');
const searchEl = document.getElementById('search');
const fuelEl = document.getElementById('fuel');

function fuelName(key) {
    const names = {
        '92': 'АИ-92',
        '95': 'АИ-95',
        '95_pulsar': '95 Pulsar',
        '100': 'АИ-100',
        'diesel_summer': 'ДТ',
        'diesel_winter': 'ДТ зима'
    };

    return names[key] || key;
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

function reportHtml(report) {
    if (!report) {
        return `
            <div class="report report-empty">
                Наличие топлива и очередь пока не подтверждены пользователями
            </div>
        `;
    }

    return `
        <div class="report">
            <b>По данным пользователей:</b><br>
            АИ-92: ${availabilityName(report.fuel_92)}<br>
            АИ-95: ${availabilityName(report.fuel_95)}<br>
            95 Pulsar: ${availabilityName(report.fuel_95_pulsar)}<br>
            ДТ: ${availabilityName(report.fuel_diesel)}<br>
            Очередь: ${queueName(report.queue_level)}
            ${report.comment ? `<br>Комментарий: ${escapeHtml(report.comment)}` : ''}
            <br>
            <span class="meta">Отчёт: ${report.created_at}</span>
        </div>
    `;
}

function escapeHtml(text) {
    return String(text)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function render() {
    const query = searchEl.value.trim().toLowerCase();
    const fuel = fuelEl.value;

    let filtered = stations.filter(station => {
        const text = [
            station.number,
            station.name,
            station.address
        ].join(' ').toLowerCase();

        if (query && !text.includes(query)) {
            return false;
        }

        if (fuel && !station.prices.some(p => p.key === fuel)) {
            return false;
        }

        return true;
    });

    countEl.textContent = `Показано АЗС: ${filtered.length}`;

    listEl.innerHTML = '';

    for (const station of filtered) {
        const pricesHtml = station.prices.length
            ? station.prices.map(p => `
                <span class="price">${fuelName(p.key)} — ${p.price.toFixed(2)} ₽</span>
            `).join('')
            : '<span class="price">Цен нет</span>';

        const div = document.createElement('div');
        div.className = 'station';

        div.innerHTML = `
            <div class="station-title">
                ${station.brand || 'АЗС'} ${station.number || ''}
            </div>

            <div class="address">
                ${station.address || 'Адрес не указан'}
            </div>

            <div class="prices">
                ${pricesHtml}
            </div>

            ${reportHtml(station.latest_report)}

            <button class="report-button" data-station-id="${station.id}">
                Сообщить ситуацию
            </button>

            <div class="meta">
                Цены обновлены: ${station.updated_at || 'неизвестно'}
                <br>
                ID: ${station.external_id}
            </div>
        `;

        listEl.appendChild(div);
    }

    document.querySelectorAll('.report-button').forEach(button => {
        button.addEventListener('click', () => {
            const stationId = Number(button.dataset.stationId);
            const station = stations.find(s => s.id === stationId);
            openReportForm(station);
        });
    });
}

function openReportForm(station) {
    const modal = document.createElement('div');
    modal.className = 'modal-backdrop';

    modal.innerHTML = `
        <div class="modal">
            <h2>Сообщить ситуацию</h2>
            <div class="modal-address">${station.brand} ${station.number || ''}<br>${station.address}</div>

            ${selectAvailability('fuel_92', 'АИ-92')}
            ${selectAvailability('fuel_95', 'АИ-95')}
            ${selectAvailability('fuel_95_pulsar', '95 Pulsar')}
            ${selectAvailability('fuel_diesel', 'ДТ')}

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
        const payload = {
            station_id: station.id,
            fuel_92: document.getElementById('report_fuel_92').value,
            fuel_95: document.getElementById('report_fuel_95').value,
            fuel_95_pulsar: document.getElementById('report_fuel_95_pulsar').value,
            fuel_diesel: document.getElementById('report_fuel_diesel').value,
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

function selectAvailability(name, label) {
    return `
        <label>
            ${label}
            <select id="report_${name}">
                <option value="unknown">Не знаю</option>
                <option value="yes">Есть</option>
                <option value="no">Нет</option>
            </select>
        </label>
    `;
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