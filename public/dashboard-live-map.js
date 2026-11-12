(() => {
    const root = document.getElementById('ops-live-map');
    if (!root) return;

    const message = document.querySelector('[data-map-message]');
    const showMessage = (text) => {
        message.textContent = text;
        message.hidden = false;
    };
    const hideMessage = () => {
        message.textContent = '';
        message.hidden = true;
    };

    if (!window.L) {
        showMessage('The map library did not load. Check the connection and refresh the page.');
        return;
    }

    const parsePayload = (raw) => {
        try {
            const parsed = JSON.parse(raw || '{}');
            return {
                incidents: Array.isArray(parsed.incidents) ? parsed.incidents : [],
                vehicles: Array.isArray(parsed.vehicles) ? parsed.vehicles : [],
                center: Array.isArray(parsed.center) && parsed.center.length === 2 ? parsed.center : null,
            };
        } catch (error) {
            console.error('Live map data could not be read.', error);
            showMessage('Map data is unavailable. Refresh the page or contact support.');
            return { incidents: [], vehicles: [], center: null };
        }
    };

    let data = parsePayload(root.dataset.mapPayload);
    const validCoordinates = (item) => item.latitude !== null
        && item.latitude !== ''
        && item.longitude !== null
        && item.longitude !== ''
        && Number.isFinite(Number(item.latitude))
        && Number.isFinite(Number(item.longitude));
    const initialPoints = [
        ...data.incidents.filter(validCoordinates),
        ...data.vehicles.filter(validCoordinates),
    ];
    const center = initialPoints.length
        ? [
            initialPoints.reduce((sum, item) => sum + Number(item.latitude), 0) / initialPoints.length,
            initialPoints.reduce((sum, item) => sum + Number(item.longitude), 0) / initialPoints.length,
        ]
        : data.center || [0, 0];
    const map = window.L.map(root, { preferCanvas: true, zoomControl: true })
        .setView(center, initialPoints.length || data.center ? 12 : 2);
    const tileLayer = window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
    }).addTo(map);
    tileLayer.on('tileerror', () => showMessage('Map tiles could not be loaded. GPS and incident records remain available.'));
    tileLayer.on('tileload', hideMessage);

    const makeCluster = () => window.L.markerClusterGroup
        ? window.L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 42 })
        : window.L.layerGroup();
    const vehicleLayer = makeCluster();
    const incidentLayer = makeCluster();
    const densityLayer = window.L.layerGroup();
    const vehicleMarkers = new Map();
    const incidentMarkers = new Map();
    let vehiclesVisible = true;
    let incidentsVisible = true;
    let densityVisible = true;

    vehicleLayer.addTo(map);
    incidentLayer.addTo(map);
    densityLayer.addTo(map);

    const text = (value) => value === null || value === undefined || value === '' ? '--' : String(value);
    const safeHtml = (value) => text(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[character]);
    const dateTime = (value) => {
        if (!value) return '--';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '--' : date.toLocaleString();
    };
    const duration = (seconds) => {
        if (!Number.isFinite(Number(seconds)) || Number(seconds) < 0) return '--';
        const minutes = Math.floor(Number(seconds) / 60);
        const days = Math.floor(minutes / 1440);
        const hours = Math.floor((minutes % 1440) / 60);
        const remainingMinutes = minutes % 60;
        if (days) return `${days}d ${hours}h`;
        if (hours) return `${hours}h ${remainingMinutes}m`;
        return `${remainingMinutes}m`;
    };
    const haversineKm = (fromLat, fromLng, toLat, toLng) => {
        const radians = (degrees) => degrees * Math.PI / 180;
        const dLat = radians(toLat - fromLat);
        const dLng = radians(toLng - fromLng);
        const a = Math.sin(dLat / 2) ** 2
            + Math.cos(radians(fromLat)) * Math.cos(radians(toLat)) * Math.sin(dLng / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    };
    const estimatedArrival = (vehicle) => {
        if (
            vehicle.status_code !== 'ON_ROAD'
            || !Number(vehicle.speed_kph)
            || !vehicle.last_gps_at
            || Date.now() - new Date(vehicle.last_gps_at).getTime() > 5 * 60 * 1000
            || !Number.isFinite(Number(vehicle.incident_latitude))
            || !Number.isFinite(Number(vehicle.incident_longitude))
            || !validCoordinates(vehicle)
        ) return '--';

        const distanceKm = haversineKm(
            Number(vehicle.latitude),
            Number(vehicle.longitude),
            Number(vehicle.incident_latitude),
            Number(vehicle.incident_longitude),
        );
        const seconds = Math.round(distanceKm / Number(vehicle.speed_kph) * 3600);
        const arrival = new Date(Date.now() + seconds * 1000);
        const arrivalTime = arrival.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        return `~${arrivalTime} (${duration(seconds)}, straight-line estimate)`;
    };
    const priorityColor = (priority) => ({
        CRITICAL: '#e53945',
        HIGH: '#f28b24',
        MEDIUM: '#e8c33a',
        NORMAL: '#e8c33a',
        LOW: '#38ad68',
    })[String(priority || '').toUpperCase()] || '#e8c33a';
    const statusClass = (status) => ({
        ON_ROAD: 'on_road',
        OFF_ROAD: 'off_road',
        ASSIGNED: 'assigned',
        IDLE: 'idle',
        AT_INCIDENT: 'at_incident',
    })[status] || 'unknown';

    const popupContent = (title, fields, href, actionLabel) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'ops-map-popup';
        const heading = document.createElement('h3');
        heading.textContent = title;
        wrapper.appendChild(heading);
        const list = document.createElement('dl');
        fields.forEach(([label, value]) => {
            const term = document.createElement('dt');
            const detail = document.createElement('dd');
            term.textContent = label;
            detail.textContent = text(value);
            list.append(term, detail);
        });
        wrapper.appendChild(list);
        if (href) {
            const action = document.createElement('a');
            action.className = 'ops-map-popup-action';
            action.href = href;
            action.textContent = actionLabel;
            wrapper.appendChild(action);
        }
        return wrapper;
    };

    const highlightRow = (selector, key) => {
        document.querySelectorAll('.ops-map-highlight').forEach((row) => row.classList.remove('ops-map-highlight'));
        const row = Array.from(document.querySelectorAll(selector)).find((candidate) => (
            candidate.getAttribute(selector.includes('incident') ? 'data-map-incident' : 'data-map-vehicle') === key
        ));
        if (!row) return;
        row.classList.add('ops-map-highlight');
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        window.setTimeout(() => row.classList.remove('ops-map-highlight'), 3500);
    };

    const focusMarker = (marker, rowSelector, rowAttribute, key) => {
        if (!marker) {
            showMessage('No saved GPS coordinates are available for this record.');
            return;
        }
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 15), { animate: true });
        marker.openPopup();
        highlightRow(rowSelector, String(key));
    };

    const getFilters = () => ({
        ward: document.querySelector('[data-map-ward-filter]')?.value || '',
        vehicleSearch: document.querySelector('[data-map-search-vehicle]')?.value.trim().toLowerCase() || '',
        incidentSearch: document.querySelector('[data-map-search-incident]')?.value.trim().toLowerCase() || '',
    });

    const filtersMatch = (record, query, fields) => !query || fields.some((field) => (
        String(record[field] || '').toLowerCase().includes(query)
    ));

    const applyRowFilters = () => {
        const filters = getFilters();
        document.querySelectorAll('[data-map-incident]').forEach((row) => {
            const incident = data.incidents.find((item) => item.job_id === row.dataset.mapIncident);
            const matchesWard = !filters.ward || row.dataset.mapWardId === filters.ward;
            const matchesSearch = !filters.incidentSearch || filtersMatch(
                incident || { job_id: row.dataset.mapIncident },
                filters.incidentSearch,
                ['job_id', 'type', 'location', 'caller_name', 'caller_phone'],
            );
            row.hidden = !matchesWard || !matchesSearch;
        });
        document.querySelectorAll('[data-map-vehicle]').forEach((row) => {
            const vehicle = data.vehicles.find((item) => item.number === row.dataset.mapVehicle);
            const matchesWard = !filters.ward || row.dataset.mapWardId === filters.ward;
            const matchesSearch = !filters.vehicleSearch || filtersMatch(
                vehicle || { number: row.dataset.mapVehicle },
                filters.vehicleSearch,
                ['number', 'status', 'ward', 'incident_id'],
            );
            row.hidden = !matchesWard || !matchesSearch;
        });
    };

    const clearLayers = () => {
        vehicleLayer.clearLayers();
        incidentLayer.clearLayers();
        densityLayer.clearLayers();
        vehicleMarkers.clear();
        incidentMarkers.clear();
    };

    const fitMapToRecords = () => {
        const points = [
            ...data.incidents.filter(validCoordinates),
            ...data.vehicles.filter(validCoordinates),
        ];
        if (points.length > 1) {
            map.fitBounds(points.map((point) => [Number(point.latitude), Number(point.longitude)]), {
                padding: [28, 28],
                maxZoom: 14,
            });
        } else if (points.length === 1) {
            map.setView([Number(points[0].latitude), Number(points[0].longitude)], 13);
        } else if (data.center) {
            map.setView(data.center, 12);
        }
    };

    const renderIncident = (incident) => {
        if (!validCoordinates(incident)) return;
        const marker = window.L.circleMarker(
            [Number(incident.latitude), Number(incident.longitude)],
            {
                radius: 8,
                color: '#ffffff',
                weight: 2,
                fillColor: priorityColor(incident.priority),
                fillOpacity: 0.96,
            },
        );
        const vehicle = data.vehicles.find((item) => item.number === incident.vehicle_number);
        marker.bindPopup(popupContent(incident.job_id, [
            ['Incident ID', incident.job_id],
            ['Incident Type', incident.type],
            ['Description', incident.description],
            ['Priority', incident.priority],
            ['Location', incident.location],
            ['Ward', incident.ward],
            ['Caller Name', incident.caller_name],
            ['Caller Phone', incident.caller_phone],
            ['Assigned Vehicle', incident.vehicle_number],
            ['Incident Status', incident.status],
            ['ETA', vehicle ? estimatedArrival(vehicle) : '--'],
            ['Elapsed Time', duration(incident.elapsed_seconds)],
        ], incident.view_url, 'View Incident'));
        marker.on('click', () => highlightRow('[data-map-incident]', incident.job_id));
        incidentMarkers.set(incident.job_id, marker);
        incidentLayer.addLayer(marker);
    };

    const renderVehicle = (vehicle) => {
        if (!validCoordinates(vehicle)) return;
        const iconClass = statusClass(vehicle.status_code);
        const statusSymbol = {
            ON_ROAD: '<path d="M4 12h15m-6-6 6 6-6 6"/>',
            OFF_ROAD: '<path d="m6 6 12 12M18 6 6 18"/>',
            ASSIGNED: '<circle cx="12" cy="12" r="7"/><path d="M12 8v8m-4-4h8"/>',
            IDLE: '<circle cx="12" cy="12" r="5"/>',
            AT_INCIDENT: '<path d="M12 4v10m0 4v1"/>',
        }[vehicle.status_code] || '<circle cx="12" cy="12" r="5"/>';
        const icon = window.L.divIcon({
            className: 'ops-map-vehicle-marker',
            iconSize: [42, 49],
            iconAnchor: [21, 17],
            popupAnchor: [0, -16],
            html: `<span class="ops-map-vehicle-icon is-${iconClass}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14l2 5v10h-2v2h-3v-2H8v2H5v-2H3V8l2-5Zm1.4 2L5.2 8h13.6l-1.2-3H6.4ZM5 10v6h14v-6H5Zm2 1h3v2H7v-2Zm7 0h3v2h-3v-2Z"/></svg><b class="ops-map-vehicle-state"><svg viewBox="0 0 24 24" aria-hidden="true">${statusSymbol}</svg></b></span><span class="ops-map-vehicle-label">${safeHtml(vehicle.number)}</span>`,
        });
        const marker = window.L.marker([Number(vehicle.latitude), Number(vehicle.longitude)], { icon });
        marker.bindPopup(popupContent(vehicle.number, [
            ['Vehicle Number', vehicle.number],
            ['Current Status', vehicle.status],
            ['Current Location', vehicle.current_location],
            ['Ward', vehicle.ward],
            ['Assigned Incident ID', vehicle.incident_id],
            ['Destination', vehicle.destination],
            ['Current Speed', vehicle.speed_kph === null ? '--' : `${vehicle.speed_kph} km/h`],
            ['ETA', estimatedArrival(vehicle)],
            ['Elapsed Time', duration(vehicle.elapsed_seconds)],
            ['Arrival Time', dateTime(vehicle.arrived_at)],
            ['Last GPS Update', dateTime(vehicle.last_gps_at)],
            ['GPS Freshness', vehicle.gps_stale ? 'Stale (over 5 minutes old)' : 'Fresh (within 5 minutes)'],
        ], vehicle.view_url, 'View Details'));
        marker.on('click', () => highlightRow('[data-map-vehicle]', vehicle.number));
        vehicleMarkers.set(vehicle.number, marker);
        vehicleLayer.addLayer(marker);
    };

    const renderDensity = (incidents) => {
        const wards = new Map();
        incidents.filter((incident) => validCoordinates(incident) && incident.ward_id !== null)
            .forEach((incident) => {
                const key = String(incident.ward_id);
                const ward = wards.get(key) || { name: incident.ward, incidents: [] };
                ward.incidents.push(incident);
                wards.set(key, ward);
            });

        wards.forEach((ward) => {
            const count = ward.incidents.length;
            const priorityCounts = ward.incidents.reduce((counts, incident) => {
                const priority = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'].includes(incident.priority)
                    ? incident.priority
                    : 'MEDIUM';
                counts[priority] = (counts[priority] || 0) + 1;
                return counts;
            }, {});
            const center = [
                ward.incidents.reduce((sum, incident) => sum + Number(incident.latitude), 0) / count,
                ward.incidents.reduce((sum, incident) => sum + Number(incident.longitude), 0) / count,
            ];
            const color = count >= 8 ? '#df4545' : count >= 5 ? '#f28b24' : count >= 3 ? '#e5c43a' : '#38ad68';
            const circle = window.L.circle(center, {
                radius: Math.max(100, 100 * Math.sqrt(count)),
                color,
                weight: 2,
                fillColor: color,
                fillOpacity: 0.2,
            });
            circle.bindTooltip(String(count), {
                permanent: true,
                direction: 'center',
                className: 'ops-map-density-count',
            });
            const priorityFields = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW']
                .map((priority) => `${priority}: ${priorityCounts[priority] || 0}`)
                .join(' · ');
            circle.bindPopup(popupContent(ward.name || 'Ward', [
                ['Ward Name', ward.name],
                ['Geocoded incidents', count],
                ['Priority counts', priorityFields],
            ]));
            circle.on('click', () => {
                map.fitBounds(circle.getBounds(), { maxZoom: 16, padding: [24, 24] });
                window.setTimeout(() => circle.openPopup(), 180);
            });
            densityLayer.addLayer(circle);
        });
    };

    const render = () => {
        clearLayers();
        hideMessage();
        const filters = getFilters();
        const incidents = data.incidents.filter((incident) => (
            (!filters.ward || String(incident.ward_id || '') === filters.ward)
            && filtersMatch(incident, filters.incidentSearch, ['job_id', 'type', 'location', 'caller_name', 'caller_phone'])
        ));
        const vehicles = data.vehicles.filter((vehicle) => (
            (!filters.ward || String(vehicle.ward_id || '') === filters.ward)
            && filtersMatch(vehicle, filters.vehicleSearch, ['number', 'status', 'ward', 'incident_id'])
        ));

        incidents.forEach(renderIncident);
        vehicles.forEach(renderVehicle);
        renderDensity(incidents);

        if (vehiclesVisible) vehicleLayer.addTo(map);
        else map.removeLayer(vehicleLayer);
        if (incidentsVisible) incidentLayer.addTo(map);
        else map.removeLayer(incidentLayer);
        if (densityVisible) densityLayer.addTo(map);
        else map.removeLayer(densityLayer);
        applyRowFilters();

        if (!incidents.some(validCoordinates) && !vehicles.some(validCoordinates)) {
            showMessage('No saved GPS coordinates are available for the current page and filters.');
        }
        if (!window.L.markerClusterGroup) {
            showMessage('Marker clustering could not load; markers are still shown individually.');
        }
    };

    const setLayerToggle = (name, visible) => {
        const button = document.querySelector(`[data-map-layer="${name}"]`);
        button?.setAttribute('aria-pressed', String(visible));
    };

    document.querySelectorAll('[data-map-layer]').forEach((button) => {
        button.addEventListener('click', () => {
            const layer = button.dataset.mapLayer;
            if (layer === 'vehicles') vehiclesVisible = !vehiclesVisible;
            if (layer === 'incidents') incidentsVisible = !incidentsVisible;
            if (layer === 'density') densityVisible = !densityVisible;
            setLayerToggle(layer, {
                vehicles: vehiclesVisible,
                incidents: incidentsVisible,
                density: densityVisible,
            }[layer]);
            render();
        });
    });

    document.querySelector('[data-map-ward-filter]')?.addEventListener('change', (event) => {
        const url = new URL(window.location.href);
        if (event.target.value) url.searchParams.set('map_ward_id', event.target.value);
        else url.searchParams.delete('map_ward_id');
        url.searchParams.delete('incidents_page');
        window.location.assign(url);
    });

    document.querySelector('[data-map-search-vehicle]')?.addEventListener('input', render);
    document.querySelector('[data-map-search-incident]')?.addEventListener('input', render);

    document.querySelector('[data-map-search-vehicle]')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const query = event.currentTarget.value.trim().toLowerCase();
        const vehicle = data.vehicles.find((item) => item.number.toLowerCase().includes(query));
        if (vehicle) focusMarker(vehicleMarkers.get(vehicle.number), '[data-map-vehicle]', 'data-map-vehicle', vehicle.number);
    });

    document.querySelector('[data-map-search-incident]')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const query = event.currentTarget.value.trim().toLowerCase();
        const incident = data.incidents.find((item) => item.job_id.toLowerCase().includes(query));
        if (incident) focusMarker(incidentMarkers.get(incident.job_id), '[data-map-incident]', 'data-map-incident', incident.job_id);
    });

    document.querySelectorAll('[data-map-vehicle]').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button, input, select')) return;
            focusMarker(vehicleMarkers.get(row.dataset.mapVehicle), '[data-map-vehicle]', 'data-map-vehicle', row.dataset.mapVehicle);
        });
    });
    document.querySelectorAll('[data-map-incident]').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button, input, select')) return;
            focusMarker(incidentMarkers.get(row.dataset.mapIncident), '[data-map-incident]', 'data-map-incident', row.dataset.mapIncident);
        });
    });

    document.querySelector('[data-map-fullscreen]')?.addEventListener('click', async () => {
        try {
            if (!document.fullscreenElement) await root.closest('.ops-live-map-shell').requestFullscreen();
            else await document.exitFullscreen();
            window.setTimeout(() => map.invalidateSize(), 100);
        } catch (error) {
            console.error('Map fullscreen could not be changed.', error);
            showMessage('Fullscreen mode is not available in this browser.');
        }
    });

    document.addEventListener('fullscreenchange', () => map.invalidateSize());
    map.on('zoomend', () => root.classList.toggle('is-zoomed', map.getZoom() >= 14));
    window.addEventListener('vmc-map-visible', () => window.setTimeout(() => map.invalidateSize(), 100));
    window.addEventListener('resize', () => map.invalidateSize());

    const refreshMap = async () => {
        const button = document.querySelector('[data-map-refresh]');
        if (button?.disabled) return;
        if (button) {
            button.disabled = true;
            button.textContent = 'Refreshing…';
        }
        hideMessage();
        try {
            const url = new URL(root.dataset.mapEndpoint, window.location.href);
            url.search = window.location.search;
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(`Map refresh failed (${response.status}).`);
            const result = await response.json();
            data = {
                incidents: Array.isArray(result.data?.incidents) ? result.data.incidents : [],
                vehicles: Array.isArray(result.data?.vehicles) ? result.data.vehicles : [],
                center: Array.isArray(result.data?.center) ? result.data.center : null,
            };
            root.dataset.mapPayload = JSON.stringify(data);
            render();
        } catch (error) {
            console.error('Live map refresh failed.', error);
            showMessage('Map refresh failed. Check your connection and try again.');
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = 'Refresh map';
            }
        }
    };

    document.querySelector('[data-map-refresh]')?.addEventListener('click', refreshMap);
    const refreshInterval = Number(root.dataset.mapAutoRefresh);
    if (Number.isFinite(refreshInterval) && refreshInterval >= 5000) {
        window.setInterval(refreshMap, refreshInterval);
    }

    fitMapToRecords();
    render();
    const selectedVehicle = data.vehicles.find((vehicle) => (
        vehicle.number === root.dataset.mapSelectedVehicle
    ));
    if (selectedVehicle && validCoordinates(selectedVehicle)) {
        map.setView([selectedVehicle.latitude, selectedVehicle.longitude], Math.max(map.getZoom(), 15));
        vehicleMarkers.get(selectedVehicle.number)?.openPopup();
    }
    const selectedIncident = data.incidents.find((incident) => (
        incident.job_id === root.dataset.mapSelectedIncident
    ));
    if (selectedIncident && validCoordinates(selectedIncident)) {
        map.setView([selectedIncident.latitude, selectedIncident.longitude], Math.max(map.getZoom(), 15));
        incidentMarkers.get(selectedIncident.job_id)?.openPopup();
    }
})();
