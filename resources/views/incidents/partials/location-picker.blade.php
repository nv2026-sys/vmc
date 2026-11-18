<div class="incident-location-picker" data-incident-location-picker>
    <div>
        <h2>Incident / pickup location</h2>
        <p class="muted">Click the map or drag the pin to set coordinates. You can also enter latitude and longitude below.</p>
    </div>
    <div class="incident-location-map" data-incident-location-map role="application" aria-label="Pick incident location on map"></div>
    <div class="incident-location-coordinates">
        <label>Latitude
            <input type="number" name="latitude" value="{{ old('latitude') }}" min="-90" max="90" step="any" data-incident-latitude>
        </label>
        <label>Longitude
            <input type="number" name="longitude" value="{{ old('longitude') }}" min="-180" max="180" step="any" data-incident-longitude>
        </label>
    </div>
    <p class="muted" data-incident-location-message role="status">No coordinates selected.</p>
    <p class="incident-location-attribution">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>.</p>
</div>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{{ asset('css/incident-location-picker.css') }}?v={{ filemtime(public_path('css/incident-location-picker.css')) }}">
<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script defer src="{{ asset('incident-location-picker.js') }}?v={{ filemtime(public_path('incident-location-picker.js')) }}"></script>
