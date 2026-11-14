<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071a35">
    <title>@yield('title', 'Dashboard') · {{ config('app.name', 'Responda') }}</title>
    <link rel="stylesheet" href="{{ asset('css/dispatch.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/operations-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard-reference.css') }}">
    <link rel="stylesheet" href="{{ asset('css/master-data.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tracking.css') }}">
    <link rel="stylesheet" href="{{ asset('css/vehicle-details.css') }}">
    <link rel="stylesheet" href="{{ asset('css/reports.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard-polish.css') }}?v={{ filemtime(public_path('css/dashboard-polish.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/accessibility-typography.css') }}?v={{ filemtime(public_path('css/accessibility-typography.css')) }}">
</head>
<body class="operations-dashboard-page {{ request()->routeIs('dashboard') || request()->routeIs('home') ? 'operations-dashboard-layout' : '' }}">
@php
    $currentUser = auth()->user();
    $currentUser?->loadMissing('roles.permissions');
    $navigation = [
        ['section' => null, 'links' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'permission' => 'dashboard.view', 'icon' => 'dashboard'],
        ]],
        ['section' => 'Emergency desk', 'dropdown' => ['label' => 'Jobs', 'icon' => 'jobs'], 'links' => [
            ['label' => 'All jobs', 'route' => 'app.jobs', 'permission' => 'jobs.view', 'icon' => 'jobs'],
            ['label' => 'New registration', 'route' => 'app.jobs.new', 'permission' => 'jobs.create', 'icon' => 'add'],
            ['label' => 'Pending jobs', 'route' => 'app.jobs.pending', 'permission' => 'jobs.view', 'icon' => 'clock'],
            ['label' => 'Duplicate calls', 'route' => 'app.jobs.duplicates', 'permission' => 'jobs.view', 'icon' => 'duplicate'],
            ['label' => 'Transferred to 112', 'route' => 'app.jobs.transferred', 'permission' => 'jobs.view', 'icon' => 'transfer'],
            ['label' => 'Active jobs', 'route' => 'app.jobs.active', 'permission' => 'jobs.view', 'icon' => 'active'],
            ['label' => 'Assigned jobs', 'route' => 'app.jobs.assigned', 'permission' => 'jobs.view', 'icon' => 'assigned'],
            ['label' => 'En route', 'route' => 'app.jobs.en-route', 'permission' => 'jobs.view', 'icon' => 'route'],
            ['label' => 'Arrived', 'route' => 'app.jobs.arrived', 'permission' => 'jobs.view', 'icon' => 'pin'],
            ['label' => 'In progress', 'route' => 'app.jobs.in-progress', 'permission' => 'jobs.view', 'icon' => 'clock'],
            ['label' => 'Completed', 'route' => 'app.jobs.completed', 'permission' => 'jobs.view', 'icon' => 'check'],
            ['label' => 'Incident archive', 'route' => 'app.archive', 'permission' => 'jobs.archive', 'icon' => 'archive'],
        ]],
        ['section' => 'Field operations', 'links' => [
            ['label' => 'Live GPS tracking', 'route' => 'app.tracking', 'permission' => 'gps.view', 'icon' => 'pin'],
            ['label' => 'Fire stations', 'route' => 'app.stations', 'permission' => 'stations.view', 'icon' => 'station'],
            ['label' => 'Ward management', 'route' => 'app.wards', 'permission' => 'wards.view', 'icon' => 'ward'],
            ['label' => 'Vehicles', 'route' => 'app.vehicles', 'permission' => 'vehicles.view', 'icon' => 'vehicle'],
            ['label' => 'Teams & members', 'route' => 'app.teams', 'permission' => 'teams.view', 'icon' => 'team'],
        ]],
        ['section' => 'Administration', 'links' => [
            ['label' => 'Reports & analytics', 'route' => 'app.reports', 'permission' => 'reports.view', 'icon' => 'report'],
            ['label' => 'User management', 'route' => 'users.index', 'permission' => 'users.view', 'icon' => 'user'],
            ['label' => 'Roles & permissions', 'route' => 'app.roles', 'permission' => 'roles.view', 'icon' => 'roles'],
        ]],
    ];
@endphp
<svg class="navigation-icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="icon-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></symbol>
    <symbol id="icon-jobs" viewBox="0 0 24 24"><path d="M8 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/><rect x="8" y="2" width="8" height="4" rx="1.5"/><path d="M8 11h8M8 15h8M8 19h5"/></symbol>
    <symbol id="icon-add" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="icon-duplicate" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></symbol>
    <symbol id="icon-transfer" viewBox="0 0 24 24"><path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4"/></symbol>
    <symbol id="icon-active" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/></symbol>
    <symbol id="icon-assigned" viewBox="0 0 24 24"><path d="M4 6h16v14H4zM8 6V4h8v2M8 11h8M8 15h5"/></symbol>
    <symbol id="icon-route" viewBox="0 0 24 24"><circle cx="6" cy="18" r="2"/><circle cx="18" cy="6" r="2"/><path d="M8 18h3a3 3 0 0 0 3-3v-3a3 3 0 0 1 3-3"/></symbol>
    <symbol id="icon-pin" viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></symbol>
    <symbol id="icon-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></symbol>
    <symbol id="icon-archive" viewBox="0 0 24 24"><path d="M3 5h18v4H3zM5 9v11h14V9M10 13h4"/></symbol>
    <symbol id="icon-station" viewBox="0 0 24 24"><path d="M4 21V7l8-4 8 4v14M2 21h20M9 10h2m2 0h2m-6 4h2m2 0h2M10 21v-4h4v4"/></symbol>
    <symbol id="icon-ward" viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15m6-12v15"/></symbol>
    <symbol id="icon-vehicle" viewBox="0 0 24 24"><path d="M3 7h11v11H3zM14 11h4l3 3v4h-7z"/><circle cx="7.5" cy="19" r="1.5"/><circle cx="17.5" cy="19" r="1.5"/><path d="M6 4h5m-2.5-2v5"/></symbol>
    <symbol id="icon-team" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20v-1a6 6 0 0 1 12 0v1z"/><path d="M16 5.5a3 3 0 0 1 0 5.8M18 14a5 5 0 0 1 3 4.6v1.4h-3"/></symbol>
    <symbol id="icon-report" viewBox="0 0 24 24"><path d="M4 20V4m0 16h17"/><path d="m7 15 4-4 3 2 6-7"/></symbol>
    <symbol id="icon-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2z"/></symbol>
    <symbol id="icon-roles" viewBox="0 0 24 24"><path d="m12 3 8 3v5c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="icon-notification" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></symbol>
    <symbol id="icon-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
</svg>
<div class="shell">
    <aside class="sidebar" id="app-sidebar">
        <a class="brand" href="{{ route('dashboard') }}" aria-label="VMC Vadodara dashboard" title="VMC Vadodara dashboard">
            <img class="brand-logo" src="{{ asset('images/vadodara-municipal-corporation-logo.jpeg') }}" alt="Vadodara Municipal Corporation">
        </a>
        <div class="workspace">
            <span class="workspace-icon"><svg aria-hidden="true"><use href="#icon-pin"></use></svg></span>
            <span><strong>Control room</strong><small>Operations workspace</small></span>
        </div>
        <nav class="navigation" aria-label="Main navigation">
            @foreach ($navigation as $group)
                @php
                    $visibleLinks = collect($group['links'])->filter(fn ($link) => !$currentUser || $currentUser->hasPermission($link['permission']));
                @endphp
                @if ($visibleLinks->isNotEmpty())
                    <div class="nav-group">
                        @if ($group['section']) <div class="nav-caption">{{ $group['section'] }}</div> @endif
                        @if (!empty($group['dropdown']))
                            @php
                                $hasActiveLink = request()->routeIs('app.jobs.*', 'app.archive*')
                                    || $visibleLinks->contains(fn ($link) => Route::has($link['route']) && request()->routeIs($link['route']));
                            @endphp
                            <details class="nav-dropdown" @if ($hasActiveLink) open @endif>
                                <summary class="nav-link nav-dropdown-trigger {{ $hasActiveLink ? 'active' : '' }}" aria-label="{{ $group['dropdown']['label'] }}" title="{{ $group['dropdown']['label'] }}">
                                    <span class="nav-icon"><svg aria-hidden="true"><use href="#icon-{{ $group['dropdown']['icon'] }}"></use></svg></span>
                                    <span class="nav-label">{{ $group['dropdown']['label'] }}</span>
                                    <span class="nav-dropdown-chevron" aria-hidden="true">⌄</span>
                                </summary>
                                <div class="nav-dropdown-links">
                                    @foreach ($visibleLinks as $link)
                                        @if (Route::has($link['route']))
                                            @php
                                                $isActiveLink = request()->routeIs($link['route'])
                                                    || ($link['route'] === 'app.jobs' && request()->routeIs('app.jobs.show'))
                                                    || ($link['route'] === 'app.archive' && request()->routeIs('app.archive.*'));
                                            @endphp
                                            <a class="nav-link {{ $isActiveLink ? 'active' : '' }}" href="{{ route($link['route']) }}" aria-label="{{ $link['label'] }}" title="{{ $link['label'] }}">
                                                <span class="nav-icon"><svg aria-hidden="true"><use href="#icon-{{ $link['icon'] }}"></use></svg></span><span class="nav-label">{{ $link['label'] }}</span>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </details>
                        @else
                            @foreach ($visibleLinks as $link)
                                @if (Route::has($link['route']))
                                    <a class="nav-link {{ request()->routeIs($link['route']) || ($link['route'] === 'dashboard' && request()->routeIs('home')) ? 'active' : '' }}" href="{{ route($link['route']) }}" aria-label="{{ $link['label'] }}" title="{{ $link['label'] }}">
                                        <span class="nav-icon"><svg aria-hidden="true"><use href="#icon-{{ $link['icon'] }}"></use></svg></span><span class="nav-label">{{ $link['label'] }}</span>
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    </div>
                @endif
            @endforeach
        </nav>
        <div class="sidebar-footer"><span class="online-dot"></span> RESPONSE NETWORK <span class="version">VMC</span></div>
    </aside>
    <div class="main-column">
        <header class="topbar">
            <div class="app-heading">
                <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
                    <svg aria-hidden="true"><use href="#icon-menu"></use></svg>
                </button>
                <span class="app-alert-icon" aria-hidden="true">🚨</span>
                <span><strong>Emergency Response System</strong><small>Ambulance &amp; Fire Brigade · Vadodara</small></span>
            </div>
            <span class="current-page-title" aria-current="page">@yield('title', 'Dashboard')</span>
            @if (request()->routeIs('dashboard') && $currentUser?->hasPermission('jobs.create'))
                <a class="topbar-action" href="{{ route('app.jobs.new') }}"><span aria-hidden="true">＋</span> Add Incident</a>
            @endif
            <div class="topbar-account">
                @if ($currentUser)
                    @php($unreadNotificationsCount = $currentUser->unreadNotifications()->count())
                    <a class="button button-light button-small notification-link" href="{{ route('notifications.index') }}" aria-label="Notifications{{ $unreadNotificationsCount > 0 ? ', '.$unreadNotificationsCount.' unread' : '' }}">
                        <svg aria-hidden="true"><use href="#icon-notification"></use></svg>
                        <span>Notifications</span>
                        <span class="notification-count" data-notification-count @if ($unreadNotificationsCount < 1) hidden @endif>{{ $unreadNotificationsCount > 99 ? '99+' : $unreadNotificationsCount }}</span>
                    </a>
                @endif
                <span class="ops-live {{ app()->environment('local') ? 'is-demo' : '' }}"><span></span>{{ app()->environment('local') ? 'Demo data' : 'Live' }}</span>
                <span class="topbar-date">{{ now()->format('D, d M Y H:i') }}</span>
                @if ($currentUser)
                    <span class="avatar">{{ collect(explode(' ', $currentUser->name))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                    <span class="account-name">{{ $currentUser->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-light button-small" type="submit">Sign out</button></form>
                @else
                    <span class="preview-tag"><span></span> Preview mode</span>
                    <a class="button button-primary button-small" href="{{ route('login') }}">Sign in</a>
                @endif
            </div>
        </header>
        <main class="content">@yield('content')</main>
        <footer class="footer"><strong>Responda Operations</strong><span>Secure emergency response management</span></footer>
    </div>
</div>
@if ($currentUser)
    <div class="notification-toast" data-notification-toast role="status" aria-live="polite" hidden>
        <strong data-notification-toast-title></strong>
        <span data-notification-toast-message></span>
        <a href="{{ route('notifications.index') }}">View notifications</a>
    </div>
    <script>
        (() => {
            const count = document.querySelector('[data-notification-count]');
            const toast = document.querySelector('[data-notification-toast]');
            const toastTitle = toast?.querySelector('[data-notification-toast-title]');
            const toastMessage = toast?.querySelector('[data-notification-toast-message]');
            if (!count || !toast || !toastTitle || !toastMessage) return;

            let knownIds = null;
            let toastTimeout;

            const refreshNotifications = async () => {
                try {
                    const response = await fetch(@json(route('notifications.latest')), {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) {
                        console.error('Unable to refresh notifications.', response.status);
                        return;
                    }
                    const payload = await response.json();
                    const unread = Number(payload.unread) || 0;
                    count.textContent = unread > 99 ? '99+' : String(unread);
                    count.hidden = unread === 0;

                    const notifications = Array.isArray(payload.notifications) ? payload.notifications : [];
                    const currentIds = new Set(notifications.map((notification) => notification.id));
                    if (knownIds !== null) {
                        const newest = notifications.find((notification) => !knownIds.has(notification.id));
                        if (newest) {
                            const event = newest.event || '';
                            toastTitle.textContent = event === 'dispatch.assigned' || event === 'assignment.created'
                                ? 'New assignment'
                                : event === 'assignment.remark_reply' ? 'Reply to your remark'
                                : event === 'driver_assignment.escalated' ? 'Driver escalation'
                                : event === 'driver_assignment.need_help' ? 'Driver help request'
                                : event.includes('status') ? 'Status updated' : 'New notification';
                            toastMessage.textContent = newest.message || newest.job_id || newest.vehicle_number || '';
                            toast.hidden = false;
                            window.clearTimeout(toastTimeout);
                            toastTimeout = window.setTimeout(() => { toast.hidden = true; }, 8000);
                        }
                    }
                    knownIds = currentIds;
                } catch (error) {
                    console.error('Unable to refresh notifications.', error);
                }
            };

            refreshNotifications();
            window.setInterval(refreshNotifications, 15000);
        })();
    </script>
@endif
<script>
(() => {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.getElementById('app-sidebar');
    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', () => {
        const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
        document.body.classList.toggle('sidebar-hidden', isExpanded);
        toggle.setAttribute('aria-expanded', String(!isExpanded));
        toggle.setAttribute('aria-label', isExpanded ? 'Expand sidebar' : 'Collapse sidebar');
        toggle.setAttribute('title', isExpanded ? 'Expand sidebar' : 'Collapse sidebar');
    });
})();
</script>
</body>
</html>
