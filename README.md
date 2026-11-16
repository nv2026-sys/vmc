## Emergency Dispatch Management System

Laravel 12 application and REST API foundation for a multi-station fire brigade and ambulance dispatch system. Server-rendered Blade pages provide a live, permission-scoped dashboard and searchable station, ward, vehicle, team, incident, user, and role directories. Phases 2–7 cover master data, incident intake, multi-unit dispatch, GPS tracking, operational analytics, archive, and exports.

### Phase 1 implementation

- Sanctum bearer-token login, current-user, and logout endpoints.
- Seeded system roles and permissions with role/permission middleware.
- MySQL/MariaDB migrations for users, stations, wards, station membership, RBAC, tokens, and audit events.
- Optional initial super-admin creation from private `.env` values. No demo user or default password is created.
- Login throttling (five attempts per minute per IP/email) and eight-hour token expiration by default.
- Server-rendered dashboard and searchable master-data directories use Laravel session authentication and station-level access checks.
- Dashboard summaries and recent-incident listings use live, station-scoped database records and respect the viewer's role permissions.
- Hierarchical user administration assigns roles and stations only within the manager's scope, records account changes, and revokes tokens when an account is disabled.
- Automated auth, RBAC, and station-scope tests.

### Phase 2 implementation

- Manage stations, wards, vehicles, teams, and team membership through permission-protected, station-scoped directories and validated create, update, and archive workflows.
- Protect operationally referenced records from unsafe archival, and record master-data changes in the audit log.
- Search and paginate directories while enforcing each user's station access.

### Phase 3 implementation

- Register incidents with a generated public job ID, validated caller/location details, an initial call log, and an append-only received-status history entry.
- Search caller records from the last 30 days and surface same-phone incidents from the last 48 hours before new intake; results remain permission- and station-scoped.
- Close only unassigned, newly received incidents as duplicates after confirming the original job has the same caller number. The duplicate link and actor are retained as an external reference and audit event.
- Transfer only unassigned, newly received incidents to 112 with a required external reference number; duplicate and 112 statuses remain distinct.
- Server-rendered incident registration/detail pages and pending, duplicate, and 112 directories.
- Dashboard carousel rotates recent new-job and incoming-call records, refreshes the permission-scoped feed every 15 seconds, and links directly to the incident.
- Society/area is stored with each incident, included in location-based public job IDs, and shown with caller and contact details in operational views.
- Authenticated API endpoints: `GET /api/v1/jobs`, `POST /api/v1/jobs`, `GET /api/v1/callers/search`, `POST /api/v1/jobs/{id}/duplicate`, and `POST /api/v1/jobs/{id}/transfer-112`.

### Phase 4 implementation

- Assign multiple available vehicles, optionally paired with teams, to one incident in a single transaction. Assignment checks station scope, ward coverage, active station/resource state, and prevents duplicate or already-deployed resources.
- Queue a busy vehicle and its current driver together as the same pair, or queue a busy response team. Queued assignments are shown as `QUEUED`, do not replace the driver's active assignment, and are activated in order when their vehicle, driver, and team become available. Activation is audited, recorded in status history, and notifies the driver/team.
- Set each unit to any supported status (`ASSIGNED`, `EN_ROUTE`, `ARRIVED`, `STARTED`, `IN_PROGRESS`, `COMPLETED`, or `WORK_DONE`) at any time; completed assignments can be reopened only when doing so will not conflict with another active or queued assignment. Every change is retained in status history. The general assignment-status API retains the `STARTED` → `EN_ROUTE`, `REACHED`/`ON_SITE` → `ARRIVED`, and `FINISHED` → `COMPLETED` aliases; the driver `/started` action records the new `STARTED` status at the incident.
- Update vehicle/team availability on dispatch and release, derive the incident's aggregate status from all assignments, and audit each dispatch/status change.
- Vehicle drivers can update only assignments for their assigned team leader or active team membership; station membership alone does not grant driver access to other units.
- Active, assigned, en-route, arrived, in-progress, and completed job queues show live, station-scoped incident records.
- Incident detail page shows active assignments, per-unit status controls, resource selection, and a timeline identifying which vehicle produced each status event.
- Authenticated API endpoints: `POST /api/v1/jobs/{id}/assignments` and `PATCH /api/v1/jobs/{id}/assignments/{assignmentId}/status`.

### Phase 5 implementation

- Accept authenticated GPS points for active vehicle assignments, validate geographic, speed, and time ranges, reject stale points, and update the latest vehicle coordinates transactionally. The mobile app should POST the device's current fix every 10 seconds while tracking is active.
- Restrict driver uploads and route history to vehicles and assignments tied to their active team membership or leadership; operational tracking respects station scope.
- Vehicle drivers need to be the leader or an active member of the response team attached to the assignment; assignments without a linked team cannot accept driver GPS uploads.
- Record the authenticated receiver for each GPS point and audit accepted location updates.
- Show latest vehicle positions and assignment route history in the tracking page and API; route queries default to the last 24 hours and are capped at 500 points per request.
- Authenticated API endpoints: `GET /api/v1/tracking`, `GET /api/v1/vehicles/{vehicle}/gps`, and `POST /api/v1/vehicles/{vehicle}/gps`.
- GPS update payload: `assignment_id` (when assigned), `latitude`, `longitude`, and optional `speed_kph` and device-captured `recorded_at` (ISO 8601). Each accepted POST appends a row to `vehicle_gps_logs` and refreshes the vehicle's latest coordinates. GPS permission denial or network errors should be handled by the mobile app and retried on its next 10-second cycle.

### Phase 6 implementation

- The permission-protected `/reports` dashboard summarizes incidents by date range and accessible station, including status, priority, incident type, daily volume, station deployments, and completion counts.
- Show average response and resolution times only for jobs with the corresponding recorded status history; these descriptive metrics are not SLA guarantees.
- Report date ranges are limited to 90 days, and station filters outside the signed-in user's station scope are rejected.
- Authenticated API endpoint: `GET /api/v1/reports/operations` (requires `reports.view`; accepts `from`, `to`, and optional `station_id` query parameters).

### Phase 7 implementation

- Archive only completed, duplicate, or transferred incidents with no active unit assignments. Archiving uses reversible soft deletion and retains related call, dispatch, status, and audit history.
- The permission-protected `/archive` directory supports station-scoped search and restore actions; archive/restore actions are audited. API actions: `POST /api/v1/jobs/{id}/archive` and `POST /api/v1/jobs/{id}/restore` (requires `jobs.archive`).
- Export operational reports as CSV, Excel (`.xlsx`), or PDF with the selected reporting period and station filter. Downloads require `reports.export` and reuse report validation and station scoping.

### Phase 8 implementation

- Add baseline browser/API security headers and HSTS only for HTTPS requests.
- Enforce account-active status after Sanctum authentication; reject and revoke tokens for accounts deactivated outside the normal user-management workflow.
- Login throttling limits attempts per IP/account and adds a shared per-IP ceiling to slow password spraying across different account names.
- API access is permission- and station-scoped, catalogue responses are paginated, report and GPS history ranges are bounded, and GPS ingestion is rate-limited.

### Production deployment checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`, a unique secret `APP_KEY`, and an HTTPS `APP_URL`; configure trusted proxies and hosts for the actual reverse-proxy topology.
- The application defaults to `Asia/Kolkata` for Vadodara operations; set `APP_TIMEZONE` explicitly if deploying to a different operating timezone.
- Set `SESSION_SECURE_COOKIE=true`, keep `SESSION_HTTP_ONLY=true` and `SESSION_SAME_SITE=lax`, and use a dedicated MySQL login with a secret supplied by the deployment secret store. Allow database traffic only from the application host and configure TLS with a trusted CA when required by the database host.
- Require PHP 8.2+ with `pdo_mysql`, `gd`, and `zip`, plus MySQL 8.0+ or MariaDB 10.4+. Run `composer install --no-dev --prefer-dist --optimize-autoloader` and `php artisan migrate --force` with a deployment login that has schema-change permission.
- Run `composer audit` during release preparation and update affected dependencies before deployment.
- Seed system permissions when upgrading, then prepare configuration, route, and view caches with `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`.
- Keep `storage/` and `bootstrap/cache/` writable by the application user; configure persistent database/Redis cache, session, and queue services appropriate to the deployment.
- Run queue workers under a process manager, configure scheduled tasks and log rotation, and verify MySQL backups and restore procedures before production traffic.
- Serve only over HTTPS, restrict inbound database access, keep `.env` and generated exports private, and monitor `/up`, queue failures, authentication throttles, and application errors.

### Recommended project structure

```text
app/
  Http/Controllers/Api/V1/   Versioned REST endpoints
  Http/Middleware/           Authentication and permission checks
  Http/Requests/             Request validation
  Http/Resources/            Stable API response shapes
  Models/                    Eloquent entities and relationships
  Policies/                  Resource and station authorization
  Services/                  Transactions and business workflows
database/
  factories/
  migrations/
  seeders/
resources/views/               Server-rendered Blade pages
public/css/                    Static styles for the Blade pages
routes/api.php                API routes
tests/Feature/                API and workflow tests
tests/Unit/                   Isolated domain tests
```

### Relational ER design

Application identifiers use `BIGINT`; MySQL stores dispatch timestamps without per-value timezone offsets, so configure the application timezone consistently across application and database hosts. Foreign keys are explicit, operational data uses soft deletes where appropriate, and job status changes are designed as immutable history rows.

| Table | Core columns | Relationships / constraints |
|---|---|---|
| `users` | `id`, `name`, normalized unique `email`, `password`, `is_active`, `last_login_at`, timestamps, `deleted_at` | Login identities; roles and station access are many-to-many. |
| `roles` | `id`, unique `code`, `name`, `description`, `is_system`, timestamps, `deleted_at` | Seven seeded system roles; many-to-many permissions and users. |
| `permissions` | `id`, unique `code`, `name`, `description`, timestamps | Many-to-many roles through `role_permissions`. |
| `role_permissions` | `role_id`, `permission_id`, timestamps | Composite primary key; both FKs cascade on role/permission removal. |
| `user_roles` | `user_id`, `role_id`, nullable `assigned_by`, timestamps | Composite primary key; assignment actor references `users`. |
| `fire_stations` | `id`, unique `code`, `name`, `address`, `contact_number`, nullable `admin_user_id`, coordinates, `is_active`, timestamps, `deleted_at` | Admin references `users`; one-to-many vehicles/teams; many-to-many wards and station-scoped users. |
| `wards` | `id`, unique `code`, `name`, `description`, `is_active`, timestamps, `deleted_at` | Many-to-many fire stations; referenced by incidents. |
| `station_wards` | `fire_station_id`, `ward_id`, timestamps | Composite primary key maps station coverage. |
| `user_fire_stations` | `user_id`, `fire_station_id`, nullable `assigned_by`, timestamps | Composite primary key limits user access to assigned stations (or a station they administer). |
| `vehicles` | `id`, `fire_station_id`, unique `vehicle_number`, type, registration/GPS identifiers, coordinates, availability/maintenance status, timestamps, `deleted_at` | Belongs to a station; referenced by assignments and GPS logs. |
| `teams` | `id`, `fire_station_id`, `leader_user_id`, `name`, contact, availability, timestamps, `deleted_at` | Belongs to a station; members are users through `team_members`. |
| `team_members` | `team_id`, `user_id`, qualification/designation, membership dates, timestamps | Composite primary key records team membership. |
| `emergency_jobs` | `id`, unique public `job_id`, caller details, call source, incident type/priority/time, `ward_id`, address/coordinates, remarks, status, `created_by`, timestamps, `deleted_at` | One-to-many call logs, assignments, history, remarks, and external references. |
| `job_call_logs` | `id`, `job_id`, caller/source details, `received_at`, `created_by` | Each call/report belongs to one job. |
| `job_assignments` | `id`, `job_id`, `fire_station_id`, `vehicle_id`, nullable `team_id`/leader, `assigned_by`, `assigned_at`, status, remarks, cancellation reason, timestamps | Each deployment belongs to one job and vehicle; team/station FKs capture the responding unit. |
| `job_status_history` | `id`, `job_id`, nullable `assignment_id`, previous/new status, actor, `changed_at`, remarks, optional coordinates | Append-only status events; assignment FK is null for job-level transitions. |
| `job_remarks` | `id`, `job_id`, `user_id`, body, timestamps | Each remark belongs to a job and author. |
| `vehicle_gps_logs` | `id`, `vehicle_id`, nullable `assignment_id`, latitude/longitude, optional speed, `recorded_at` | Append-only GPS samples; index vehicle and recorded time. |
| `job_external_references` | `id`, `job_id`, reference type/number, transfer/confirmation details, actor, timestamps | Separate rows distinguish 112 transfer from duplicate closure. |
| `notifications` | Laravel notification ID, `notifiable_type/id`, type, JSON data, `read_at`, `created_at` | Polymorphic recipient; retain Laravel's UUID notification ID. |
| `audit_logs` | `id`, nullable actor, event, subject type/id, IP/user agent, JSON metadata, `created_at` | Append-only event log; actor FK becomes null when a user is removed. |

Application primary keys and foreign keys use `BIGINT`; the Laravel notification table retains its framework UUID ID. MySQL stores dispatch timestamps without per-value timezone offsets, and status values use constrained domain codes. `emergency_jobs` has one-to-many call logs, assignments, status history, remarks, and external references. `job_assignments` is the only job-to-vehicle/team association; vehicle availability must be locked and checked transactionally when dispatching so a vehicle cannot be double-booked. Duplicate closure and transfer to 112 remain distinct job statuses. Station membership and authorization are separate checks: a permission does not grant access to another station's records.

The migrations establish the identity and authorization tables as well as `vehicles`, `teams`, `team_members`, `emergency_jobs`, `job_call_logs`, `job_assignments`, `job_status_history`, `job_remarks`, `vehicle_gps_logs`, `job_external_references`, and Laravel `notifications`. Phase 2 provides searchable directories and create, update, and safe archive workflows for stations, wards, vehicles, teams, and team membership. All writes are permission-checked, station-scoped, transactional, validated, and audit logged. Records with operational dependencies cannot be archived.

### Setup

Requirements: PHP 8.2+ with `pdo_mysql`, `gd`, and `zip`; Composer; and a reachable MySQL 8.0+ or MariaDB 10.4+ server. The server-rendered pages do not require Node.js/npm.

1. As a database administrator, run `mysql -u root -p < database/mysql/setup.sql` to create the `emergency_dispatch` database.
2. Create a dedicated runtime login using a deployment-managed secret, then grant it only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on `emergency_dispatch.*`. Use a separate deployment login with schema-change permissions for migrations.
3. Copy `.env.example` to `.env`. Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` through deployment configuration/secrets. Configure `MYSQL_ATTR_SSL_CA` when the database host requires TLS with a CA certificate.
4. Generate `APP_KEY` once for a new environment; set `APP_ENV=production`, `APP_DEBUG=false`, and the HTTPS `APP_URL`. Set `INITIAL_SUPER_ADMIN_EMAIL` and a private `INITIAL_SUPER_ADMIN_PASSWORD` of at least 14 characters if creating the first administrator.
5. Before initial migration, temporarily provide Laravel with a deployment SQL login that has permission to create/alter tables in `emergency_dispatch`, then run:

   ```powershell
   composer install --no-dev --prefer-dist --optimize-autoloader
   php artisan migrate --force
   php artisan db:seed --class=RbacSeeder --force
   ```

6. Replace the migration credentials with the restricted `vmc_app` runtime credentials, then run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`. Restart the web and queue-worker processes so they load the production configuration.
7. Deploy behind HTTPS and verify the database connection, login, `/up`, and `php artisan route:list --path=api`. Keep production data out of demo seeders; fictional demo records are seeded only in the local environment.

Do not commit `.env`, put credentials in the SQL setup script, or use a default/shared administrator or database password. If no initial admin credentials are configured, seeding creates the system roles and prints a warning without creating a login.
After upgrading an existing deployment, run `php artisan db:seed --class=RbacSeeder` to add the archive and report-export permissions to existing system roles.

### User hierarchy and local demo records

The user hierarchy is Super Admin → Control Room Admin → Station Admin → Dispatcher/Report Viewer → Vehicle Driver → Team Member. Administrators may create and update lower-level accounts only. Station administrators can manage users, wards, vehicles, and teams only within assigned stations; station records themselves are managed by global administrators. The user directory includes each user's roles, station assignments, sign-in time, effective permissions, and recent audit activity.

For local development, `php artisan migrate --seed` creates the RBAC records and initial administrator (when configured), then seeds sample stations, wards, accounts, vehicles, teams, and emergency jobs. To seed or refresh demo data separately:

```powershell
php artisan db:seed --class=RbacSeeder
php artisan db:seed --class=DemoDispatchSeeder
```

The demo seeder runs only in local/testing environments and is repeatable. It creates sample vehicles, response teams, a team membership, three incidents in received/en-route/transferred states, call logs, an assignment, status history, a remark, a GPS point, a 112 handoff reference, and a notification. Re-running it refreshes the sample incident, status-history, notification, and GPS timestamps so the dashboard ticker and mobile flow can be exercised with recent records. All records are explicitly marked as fictional demo drills and are not real emergency data. Demo accounts use `example.test` emails with randomly generated, undisclosed passwords. Sign in as a securely configured super administrator and set individual demo passwords from User management before testing those accounts. Demo account emails are `control.room@example.test`, `central.admin@example.test`, `north.admin@example.test`, `central.dispatch@example.test`, `north.dispatch@example.test`, `vehicle.driver@example.test`, `north.vehicle.driver@example.test`, `team.member@example.test`, and `report.viewer@example.test`.

### Authentication API

- `POST /api/v1/auth/login` — accepts `email`, `password`, optional `device_name`, and optional paired `latitude`/`longitude` GPS coordinates (plus optional `speed_kph` and `recorded_at`). A vehicle driver can have only one active session across API and browser logins: another login is rejected until the active device logs out or its session expires. API sessions are tracked by Sanctum tokens, and browser sessions use Laravel's existing database `sessions` table. When login includes GPS coordinates, the linked vehicle is marked On Road before the location is recorded. The response includes that vehicle's latest location under `data.vehicle.location`.
- `GET /api/v1/auth/me` — requires `Authorization: Bearer <token>`; returns the user, role/permission codes, and assigned station IDs.
- `POST /api/v1/auth/logout` — requires a bearer token and revokes that token.

Login input is normalized and validated. Inactive and unknown accounts receive the same authentication error. Add `permission:<code>` after `auth:sanctum` on protected routes; station-scoped queries must also enforce the user's station access.

### Driver assignment API

The mobile driver workflow is authenticated and restricted to the assigned vehicle driver. Assignment actions accept either the numeric assignment ID or the public incident `job_id` in the path:

- `GET /api/v1/driver/assignments/active` — returns the driver's latest active assignment, or `data: null`.
- `GET /api/v1/fleet/assignments?per_page=15&page=1` — returns every unreleased assignment linked to the authenticated driver, including queued jobs, with pagination metadata. For example, if three jobs are assigned or queued for that driver, all three appear in `data` when `per_page` is at least three. Completed/released assignments are not included.
- `POST /api/v1/driver/assignments/{assignment}/attend` — sets the active assignment to `EN_ROUTE`; optional device coordinates also record a GPS point.
- `POST /api/v1/driver/assignments/{assignment}/started` — marks the driver at the incident as `STARTED`; optional reached coordinates and time are supported.
- `POST /api/v1/driver/assignments/{assignment}/in-progress` — marks the assignment `IN_PROGRESS` (`/{assignment}/IN_PROGRESS` is also supported).
- `POST /api/v1/driver/assignments/{assignment}/decline` — declines only a pending assignment and releases its allocation.
- `POST /api/v1/driver/assignments/{assignment}/escalate` and `/need-help` — append incident remarks and notify active control-room administrators.
- The `/need-help` endpoint accepts `helpType` (`AMBULANCE`, `WATER_TANKER`, `VEHICLE_BREAKDOWN`, `ACCIDENT`, `MEDICAL_EMERGENCY`, `FIRE_EQUIPMENT_PROBLEM`, `ROUTE_PROBLEM`, or `OTHER`) and optional `comments`. It includes driver, vehicle, station, team, incident, and timestamp details in the control-room notification, which appears in the dashboard's Driver alerts panel. The legacy `reason` field remains supported. `/escalate` alerts also appear in that panel and produce a dashboard notification flash.
- `POST /api/v1/driver/assignments/{assignment}/reached` — sets the active assignment to `ARRIVED` and can record device coordinates.
- `POST /api/v1/driver/assignments/{assignment}/report-issues` — appends selected issues and remarks to the incident history.
- `POST /api/v1/driver/assignments/{assignment}/complete` — sets the active assignment to `COMPLETED`, releasing it.
- `POST /api/v1/driver/assignments/{assignment}/work-done` — sets the active assignment to `WORK_DONE`, releasing it.
- `POST /api/v1/driver/assignments/{assignment}/close` — appends a final close remark after completion.

All lifecycle changes use the existing assignment and status-history records. VMC route history is available through `GET /api/v1/vehicles/{vehicle}/gps`; the API does not send device coordinates to an external routing service.

### User notifications

Authenticated web users can open **Notifications** from the top bar to see their own assignment and vehicle status alerts, mark one notification read, or mark all as read. New dispatches notify the assigned driver and team; status and On Road/Off Road changes notify the other assignment participants and authorized users for that station, plus authorized control-room administrators. When authorized staff updates an assignment with a remark, the assigned driver receives a reply notification and dashboard flash. Notifications are stored in Laravel's `notifications` table and are scoped to each recipient. Driver apps can retrieve and mark their own notifications through `GET /api/v1/fleet/notifications` and `PATCH /api/v1/fleet/notifications/{notificationId}/read`.

### Master-data API

All endpoints below require a Sanctum bearer token. Catalogue endpoints support `search`, `page`, and `per_page` (1–50); results include pagination metadata.

- `GET|POST /api/v1/fire-stations`; `PUT|PATCH|DELETE /api/v1/fire-stations/{id}` — station directory and management.
- `GET|POST /api/v1/wards`; `PUT|PATCH|DELETE /api/v1/wards/{id}` — wards and their station assignments.
- `GET|POST /api/v1/vehicles`; `PUT|PATCH|DELETE /api/v1/vehicles/{id}` — vehicle details, station, status, GPS identity, and service date.
- `GET|POST /api/v1/teams`; `PUT|PATCH|DELETE /api/v1/teams/{id}` — team details, station, leader, members, and availability.

Read and write permissions are separate (`stations.view/manage`, `wards.view/manage`, `vehicles.view/manage`, `teams.view/manage`). Control Room Admins can manage master data across stations; Fire Station Admins can manage wards, vehicles, and teams within assigned stations. Only Super Admins and Control Room Admins can create stations. Vehicle/team leaders and members must be active users of that station. Deletes are soft archives and return validation errors when operational history references the record; change its status instead.

### Verification

```powershell
php artisan test
vendor\bin\pint.bat --test
composer audit
```

Tests use SQLite in memory by default. Configure a separate MySQL test database if validating MySQL migrations and behavior.

### Development phases

1. **Foundation (implemented):** inspect/scaffold, MySQL configuration, API auth, RBAC, station/ward schema.
2. **Master data (implemented):** searchable station, ward, vehicle, and team directories; station-scoped create/update/archive forms and APIs; team membership; role-based permissions; audit logging.
3. **Incident intake (implemented):** incident registration, caller search, duplicate detection, and 112 handoff workflows.
4. **Dispatch operations (implemented):** multi-vehicle assignment, sequential status transitions, timeline, and history.
5. **Vehicle tracking (implemented):** authenticated GPS ingestion, latest-location map, and assignment route history.
6. **Operational reports and analytics (implemented):** date- and station-filtered incident trends, distributions, response metrics, and deployment workload.
7. **Archive and exports (implemented):** reversible incident archiving and scoped CSV/Excel/PDF operational report downloads.
8. **Security, API, performance, and deployment verification (implemented):** account-active API enforcement, layered login throttling, security response headers, bounded query surfaces, and production deployment checklist.
