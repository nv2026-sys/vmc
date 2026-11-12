(() => {
    let root = document.querySelector('.operations-reports');
    if (!root || root.dataset.reportsReady === 'true') return;
    root.dataset.reportsReady = 'true';

    const escapeHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[character]);
    const duration = (seconds) => {
        if (!Number.isFinite(Number(seconds))) return '—';
        const totalMinutes = Math.max(0, Math.floor(Number(seconds) / 60));
        const days = Math.floor(totalMinutes / 1440);
        const hours = Math.floor((totalMinutes % 1440) / 60);
        const minutes = totalMinutes % 60;
        if (days) return `${days}d ${hours}h`;
        if (hours) return `${hours}h ${minutes}m`;
        return `${minutes}m`;
    };
    const minutes = (value) => value === null || value === undefined
        ? '—'
        : `${Number(value).toFixed(1)} min`;
    const dateTime = (value) => {
        if (!value) return '—';
        const parsed = new Date(value.replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? escapeHtml(value) : parsed.toLocaleString();
    };
    const badge = (value, kind) => {
        const className = String(value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '-');
        return `<span class="report-${kind}-badge is-${className}">${escapeHtml(String(value || '').replaceAll('_', ' '))}</span>`;
    };

    let draw = 0;
    let start = 0;
    const length = 25;
    let orderColumn = 2;
    let orderDir = 'desc';
    let searchTimer;
    let requestController;

    const loadTable = async () => {
        const form = root.querySelector('[data-report-filters]');
        const body = root.querySelector('[data-report-rows]');
        const status = root.querySelector('[data-report-status]');
        if (!form || !body) return;
        requestController?.abort();
        requestController = new AbortController();
        const params = new URLSearchParams(new FormData(form));
        params.set('draw', String(++draw));
        params.set('start', String(start));
        params.set('length', String(length));
        params.set('search', root.querySelector('[data-report-search]')?.value.trim() || '');
        params.set('order_column', String(orderColumn));
        params.set('order_dir', orderDir);

        if (status) status.textContent = 'Loading report records…';
        try {
            const response = await fetch(`${root.dataset.dataUrl}?${params}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: requestController.signal,
            });
            if (!response.ok) throw new Error(`Report request failed (${response.status}).`);
            const result = await response.json();
            if (result.draw !== draw) return;
            if (!result.data.length) {
                body.innerHTML = '<tr><td colspan="17" class="empty-cell">No incidents match the selected filters.</td></tr>';
            } else {
                body.innerHTML = result.data.map((item) => `<tr>
                    <td>${escapeHtml(item.number)}</td>
                    <td><a href="${escapeHtml(item.view_url)}">${escapeHtml(item.job_id)}</a></td>
                    <td>${escapeHtml(dateTime(item.occurred_at))}</td>
                    <td>${escapeHtml(item.type)}</td>
                    <td>${escapeHtml(item.details)}</td>
                    <td>${escapeHtml(item.location)}</td>
                    <td>${escapeHtml(item.ward)}</td>
                    <td>${escapeHtml(item.caller_name)}</td>
                    <td>${escapeHtml(item.caller_phone)}</td>
                    <td>${badge(item.priority, 'priority')}</td>
                    <td>${escapeHtml(item.vehicle_number)}</td>
                    <td>${badge(item.vehicle_status, 'status')}</td>
                    <td>${badge(item.status, 'status')}</td>
                    <td>${escapeHtml(minutes(item.response_minutes))}</td>
                    <td>${escapeHtml(duration(item.elapsed_seconds))}</td>
                    <td>${escapeHtml(minutes(item.resolution_minutes))}</td>
                    <td><a class="button button-light button-small" href="${escapeHtml(item.view_url)}">View</a></td>
                </tr>`).join('');
            }

            const end = Math.min(start + result.data.length, result.recordsFiltered);
            root.querySelector('[data-report-count]').textContent = result.recordsFiltered
                ? `Showing ${start + 1}–${end} of ${result.recordsFiltered} incidents`
                : '0 incidents';
            root.querySelector('[data-report-page]').textContent = `Page ${Math.floor(start / length) + 1}`;
            root.querySelector('[data-report-prev]').disabled = start === 0;
            root.querySelector('[data-report-next]').disabled = start + length >= result.recordsFiltered;
            if (status) status.textContent = '';
        } catch (error) {
            if (error.name === 'AbortError') return;
            console.error('Incident report could not be loaded.', error);
            if (status) status.textContent = 'Unable to load report records. Refresh the page or try again.';
            body.innerHTML = '<tr><td colspan="17" class="empty-cell">Report records could not be loaded.</td></tr>';
        }
    };

    const bind = () => {
        const currentRoot = document.querySelector('.operations-reports');
        if (!currentRoot) return;
        currentRoot.dataset.dataUrl = currentRoot.dataset.dataUrl || '';
        const form = currentRoot.querySelector('[data-report-filters]');
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const params = new URLSearchParams(new FormData(form));
            const url = new URL(form.action, window.location.href);
            url.search = params.toString();
            const submit = form.querySelector('[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Generating…';
            }
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error(`Report generation failed (${response.status}).`);
                const html = await response.text();
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                const replacement = parsed.querySelector('.operations-reports');
                if (!replacement) throw new Error('The report response did not contain report content.');
                currentRoot.replaceWith(replacement);
                window.history.pushState({}, '', url);
                start = 0;
                initialize();
            } catch (error) {
                console.error('Incident report filters could not be applied.', error);
                const alert = document.createElement('p');
                alert.className = 'alert';
                alert.setAttribute('role', 'alert');
                alert.textContent = 'Unable to generate this report. Please try again.';
                form.before(alert);
            } finally {
                if (submit?.isConnected) {
                    submit.disabled = false;
                    submit.textContent = 'Generate Report';
                }
            }
        });

        currentRoot.querySelector('[data-report-reset]')?.addEventListener('click', async (event) => {
            event.preventDefault();
            const url = event.currentTarget.href;
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error(`Reset failed (${response.status}).`);
                const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
                const replacement = parsed.querySelector('.operations-reports');
                if (!replacement) throw new Error('The reset response did not contain report content.');
                currentRoot.replaceWith(replacement);
                window.history.pushState({}, '', url);
                start = 0;
                initialize();
            } catch (error) {
                console.error('Report filters could not be reset.', error);
                window.location.assign(url);
            }
        });

        currentRoot.querySelector('[data-report-search]')?.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => {
                start = 0;
                loadTable();
            }, 250);
        });
        currentRoot.querySelector('[data-report-prev]')?.addEventListener('click', () => {
            start = Math.max(0, start - length);
            loadTable();
        });
        currentRoot.querySelector('[data-report-next]')?.addEventListener('click', () => {
            start += length;
            loadTable();
        });
        currentRoot.querySelectorAll('.report-record-table thead th[data-order-column]').forEach((header) => {
            header.addEventListener('click', () => {
                const column = Number(header.dataset.orderColumn);
                orderDir = orderColumn === column && orderDir === 'asc' ? 'desc' : 'asc';
                orderColumn = column;
                start = 0;
                loadTable();
            });
        });
        currentRoot.querySelector('[data-report-print]')?.addEventListener('click', () => window.print());
        loadTable();
    };

    const initialize = () => {
        root = document.querySelector('.operations-reports');
        if (!root) return;
        root.dataset.reportsReady = 'true';
        root.dataset.dataUrl = root.dataset.dataUrl || '';
        bind();
    };

    window.addEventListener('popstate', () => window.location.reload());
    initialize();
})();
