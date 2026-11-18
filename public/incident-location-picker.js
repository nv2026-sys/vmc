(() => {
    const picker = document.querySelector('[data-incident-location-picker]');
    if (!picker || !window.L) return;

    const mapElement = picker.querySelector('[data-incident-location-map]');
    const latitudeInput = picker.querySelector('[data-incident-latitude]');
    const longitudeInput = picker.querySelector('[data-incident-longitude]');
    const message = picker.querySelector('[data-incident-location-message]');
    if (!mapElement || !latitudeInput || !longitudeInput || !message) return;

    const fallbackCenter = [22.3072, 73.1812];
    const withinRange = (latitude, longitude) => Number.isFinite(latitude)
        && Number.isFinite(longitude)
        && latitude >= -90 && latitude <= 90
        && longitude >= -180 && longitude <= 180;
    const initialLatitude = Number(latitudeInput.value);
    const initialLongitude = Number(longitudeInput.value);
    const hasInitialLocation = latitudeInput.value !== ''
        && longitudeInput.value !== ''
        && withinRange(initialLatitude, initialLongitude);
    const initialCenter = hasInitialLocation
        ? [initialLatitude, initialLongitude]
        : fallbackCenter;

    const map = window.L.map(mapElement).setView(initialCenter, hasInitialLocation ? 15 : 12);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    let marker = null;
    const setCoordinates = (latitude, longitude) => {
        latitudeInput.value = latitude.toFixed(7);
        longitudeInput.value = longitude.toFixed(7);
        message.textContent = `Selected location: ${latitudeInput.value}, ${longitudeInput.value}`;
        if (marker) {
            marker.setLatLng([latitude, longitude]);
        } else {
            marker = window.L.marker([latitude, longitude], { draggable: true }).addTo(map);
            marker.on('dragend', () => {
                const position = marker.getLatLng();
                setCoordinates(position.lat, position.lng);
            });
        }
    };

    if (hasInitialLocation) {
        setCoordinates(initialLatitude, initialLongitude);
    }

    map.on('click', (event) => {
        setCoordinates(event.latlng.lat, event.latlng.lng);
    });

    const updateMapFromInputs = () => {
        if (latitudeInput.value === '' && longitudeInput.value === '') {
            message.textContent = 'No coordinates selected.';
            return;
        }

        const latitude = Number(latitudeInput.value);
        const longitude = Number(longitudeInput.value);
        if (!withinRange(latitude, longitude)) {
            message.textContent = 'Enter a valid latitude and longitude pair.';
            return;
        }

        setCoordinates(latitude, longitude);
        map.setView([latitude, longitude], Math.max(map.getZoom(), 15));
    };

    latitudeInput.addEventListener('change', updateMapFromInputs);
    longitudeInput.addEventListener('change', updateMapFromInputs);
    window.setTimeout(() => map.invalidateSize(), 0);
})();
