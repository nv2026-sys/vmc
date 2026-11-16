# VMC Website Manual Test Cases

## 1. Purpose and test setup

Use this checklist to manually verify the VMC web application and its connected APIs after deployment or a release. Run it in a development or staging environment with fictional data; do not create test incidents or alter operational records in production.

### Prepare test data

- Confirm the app is reachable over the expected URL and the database contains seeded RBAC roles, permissions, stations, wards, vehicles, teams, users, and demo incidents.
- Configure passwords for the demo accounts through a secure administrator workflow. Demo email addresses are listed in [README.md](./README.md); demo passwords are generated and are not documented.
- Have test users available for Super Admin, Control Room Admin, Station Admin, Dispatcher, Report Viewer, Vehicle Driver, and Team Member roles. For station-scope tests, create users and resources in two different stations.
- For dispatch and driver tests, prepare a new incident, two available vehicles (ideally at different stations), an available team with an assigned driver, and an incident ward covered by the responding station.
- For driver session tests, use two separate devices or API clients for the same driver. For GPS tests, use fictional coordinates in the test environment.
- Use a separate browser profile or sign out when switching users. Keep an authorized administrator session available to restore test data.
- For API cases, use Postman or another HTTP client. Replace `{{base_url}}`, `{{token}}`, `{{job_id}}`, `{{job_numeric_id}}`, `{{assignment_id}}`, `{{vehicle_id}}`, and `{{notification_id}}` with test-environment values. Send `Accept: application/json`; send `Content-Type: application/json` with JSON request bodies.

Record a result for each case: **Pass**, **Fail**, or **Blocked**, and include the actual result, test user/role, environment, and any reproducible steps. A blocked case should include the missing prerequisite.

## 2. Sign-in, sessions, and access control

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| AUTH-01 | Open sign-in page | Visit `/login` in a signed-out browser. | Sign-in form loads; protected operational pages are not exposed. | [ ] |
| AUTH-02 | Sign in successfully | Sign in with an active test account. | User is authenticated and redirected into the application; navigation and data match that user's permissions and station scope. | [ ] |
| AUTH-03 | Reject bad credentials | Try an incorrect password and an unknown email. | Both fail with a generic authentication error; no session is created. | [ ] |
| AUTH-04 | Reject inactive account | Sign in with a disabled account. | Sign-in is rejected without revealing account-sensitive details. | [ ] |
| AUTH-05 | Sign out | Select Sign out, then revisit the dashboard and a protected URL. | Session ends; protected pages require sign-in again. | [ ] |
| AUTH-06 | Enforce page permissions | Sign in as a role without access to a protected page, then enter its URL directly. | Access is denied; navigation visibility is not the only protection. | [ ] |
| AUTH-07 | Enforce station scope | As a Station Admin or Dispatcher assigned to Station A, search for and open Station B records by URL and filters. | Out-of-scope records are not disclosed or changed; out-of-scope writes are rejected. | [ ] |
| AUTH-08 | Restrict user hierarchy | Attempt to create or edit an equal- or higher-privilege account as a lower-level administrator. | The action is unavailable or denied; the account remains unchanged. | [ ] |
| AUTH-09 | Confirm CSRF and validation behavior | Submit a stale/invalid form or omit required fields. | The request is rejected safely with a useful validation message; no partial changes are saved. | [ ] |
| AUTH-10 | Allow one driver device session | Log in as the same vehicle driver on Device A, then log in on Device B. Call `GET /api/v1/auth/me` with both tokens. | Device A's token is rejected; Device B's token remains valid. Other user roles retain their existing session behavior. | [ ] |

## 3. Dashboard and navigation

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| DASH-01 | Load dashboard | Sign in as a dashboard-authorized user and open `/` or `/dashboard`. | Dashboard renders without errors and shows only permitted station data and widgets. | [ ] |
| DASH-02 | Check summary counts | Compare visible incident, vehicle, and activity counts with the test records visible to that user. | Counts are consistent with accessible records; restricted records are not included. | [ ] |
| DASH-03 | Open incident activity | Select a recent incident or incoming-call card. | Correct incident detail opens; caller, location, and status are consistent. | [ ] |
| DASH-04 | Refresh dashboard activity | Leave the dashboard open while a test incident or status is updated, then wait for the configured refresh or refresh the page. | The activity feed reflects new data and remains permission-scoped. | [ ] |
| DASH-05 | View map data | Open the dashboard map as a user with GPS and job permissions. | Authorized vehicles/incidents are plotted at available coordinates; missing coordinates do not break the page. | [ ] |
| DASH-06 | Check navigation by role | Sign in using Dispatcher, Driver, Report Viewer, and Admin accounts. | Each role sees only relevant navigation items; direct URL checks still enforce permissions. | [ ] |

## 4. Master data: stations, wards, vehicles, and teams

Run CRUD cases using records created solely for this test. Verify searches and pagination where the list has enough records.

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| DATA-01 | Browse and search directories | Open Stations, Wards, Vehicles, and Teams; search by a known code/name/number and move between pages. | Lists, search results, and pagination are correct and respect station access. | [ ] |
| DATA-02 | Create station | As a role permitted to manage stations, create a uniquely named test station with valid details. | Station is saved once, appears in its directory, and is unavailable to users without scope. | [ ] |
| DATA-03 | Restrict station management | As a Station Admin, attempt to create or modify a station. | Station-level management is denied to roles not allowed to administer stations. | [ ] |
| DATA-04 | Create and edit ward coverage | Create a test ward and assign it to the intended station; edit its details/coverage. | Ward and station coverage save and display correctly. | [ ] |
| DATA-05 | Create and edit vehicle | Create a vehicle in a station with a unique number and valid fields; edit it and its availability. | Changes persist; duplicate identifiers and invalid fields are rejected. | [ ] |
| DATA-06 | Create team and membership | Create a team at a station, assign an eligible leader and members, then update membership. | Team and membership details persist; only active, eligible users of that station can be assigned. | [ ] |
| DATA-07 | Prevent unsafe archive | Try to archive a station, ward, vehicle, or team referenced by operational data. | The app blocks unsafe archival and explains that linked operational data prevents it. | [ ] |
| DATA-08 | Archive unused master record | Archive a test record with no operational dependencies, then search for it. | The record is soft archived and is no longer offered as an active resource. | [ ] |
| DATA-09 | Verify station-admin scope | As Station Admin A, attempt to create/edit/archive Station B resources through both pages and direct URLs. | Cross-station actions are denied; Station B data remains unchanged. | [ ] |

## 5. User and role administration

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| USER-01 | Browse users | Open Users, search for a test user, open the detail page. | Role, station membership, account state, and permitted activity details are displayed. | [ ] |
| USER-02 | Create lower-level user | As an authorized administrator, create a user with valid email, role, and station assignments. | User is created; their effective permissions and station scope match the assigned role and stations. | [ ] |
| USER-03 | Reject invalid or duplicate user | Submit missing required details, malformed email, or an already-used email. | Validation errors are shown and no duplicate/invalid user is created. | [ ] |
| USER-04 | Update user access | Change a test user's role or station assignment and sign in as that user. | Updated access applies; removed station access no longer works. | [ ] |
| USER-05 | Deactivate user | Disable a test account, then use its web session/token to request a protected page/API. | Access is rejected; tokens are revoked or rejected according to the app's active-account behavior. | [ ] |
| USER-06 | Create custom role | As a role manager, create a role with a limited set of permissions. | Role is saved and its permission effects match the selected grants. | [ ] |
| USER-07 | Prevent unauthorized role changes | Try to create or assign a role beyond the current administrator's authority. | The action is denied; existing roles and permissions remain unchanged. | [ ] |
| USER-08 | Check audit activity | Perform a user or master-data change, then view the authorized audit/activity detail. | The action and actor are recorded; unrelated users cannot view restricted details. | [ ] |

## 6. Incident intake, directories, and lifecycle

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| JOB-01 | Register incident | Open New Incident, enter valid caller, source, type, priority, ward, address, and optional coordinates, then submit. | Incident is created once with a public job ID, initial received status, call log, and status history. | [ ] |
| JOB-02 | Validate incident form | Omit required fields and submit invalid phone, coordinates, or date values. | Field-level validation is shown; no incomplete incident is created. | [ ] |
| JOB-03 | Find recent caller | Search for a test caller with a recent call. | Recent matching records appear within the permitted scope and can be opened. | [ ] |
| JOB-04 | Check duplicate warning | Start a new incident with a caller number matching a recent incident. | Possible duplicates are surfaced before registration; unrelated/out-of-scope records are not revealed. | [ ] |
| JOB-05 | Browse incident queues | Open Pending, Active, Assigned, En Route, Arrived, In Progress, Completed, Duplicates, and Transferred queues. | Each queue displays records matching its state and permissions; opening a row shows the correct incident. | [ ] |
| JOB-06 | Review incident details and history | Open a test incident after status changes, remarks, and dispatch. | Caller/location data, assigned units, status timeline, actor, timestamps, and remarks are consistent. | [ ] |
| JOB-07 | Close as duplicate | For a newly received, unassigned incident with the same caller number as an original, submit duplicate closure and select the original. | Incident becomes Duplicate; link, actor, status history, and audit/reference details are retained. | [ ] |
| JOB-08 | Reject invalid duplicate closure | Try duplicate closure for an assigned/advanced incident or select a record with a different caller number. | Request is rejected; incident status and history remain unchanged. | [ ] |
| JOB-09 | Transfer to 112 | For a new unassigned incident, record a valid 112 reference and transfer it. | Incident becomes Transferred to 112; reference and actor are retained and appear in the detail/history. | [ ] |
| JOB-10 | Validate 112 transfer | Submit without a required reference or attempt transfer after assignment/advancement. | Request is rejected; no incorrect transfer/reference is recorded. | [ ] |

## 7. Dispatch and driver workflow

Use a fresh incident and available resources. For driver API examples, authenticate via `POST /api/v1/auth/login`; use the returned bearer token and the active assignment ID from `GET /api/v1/driver/assignments/active`. Path parameter `{assignment}` accepts the assignment identifier or public incident job ID.

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| DIS-01 | Assign one response unit | From incident detail, assign an eligible available vehicle and driver/team. | Assignment appears in the incident; vehicle/team become deployed and status history is recorded. | [ ] |
| DIS-02 | Assign multiple units | Assign two eligible vehicles to one incident. | Both assignments appear independently and can have their own status histories. | [ ] |
| DIS-03 | Reject unavailable resource | Try assigning a vehicle already deployed, off road, inactive, or outside the incident ward/station scope. | Assignment is rejected; no resource is double-booked or partially updated. | [ ] |
| DIS-04 | Driver sees own active assignment | As the assigned driver, call `GET /api/v1/driver/assignments/active`. | Response returns the driver's current assignment and status, or `data: null` if none exists. | [ ] |
| DIS-05 | Attend / travel | `POST /api/v1/driver/assignments/{assignment}/attend` with `{}`. | `200 OK`; assignment status becomes `EN_ROUTE`. | [ ] |
| DIS-06 | Mark arrived (legacy action) | `POST /api/v1/driver/assignments/{assignment}/reached` with `{}`. | `200 OK`; assignment status becomes `ARRIVED`. | [ ] |
| DIS-07 | Mark started at incident | `POST /api/v1/driver/assignments/{assignment}/started` with optional paired `reachedLatitude`, `reachedLongitude`, and `reachedTime`. | `200 OK`; status is `STARTED`; arrival time/location are recorded when provided. | [ ] |
| DIS-08 | Mark work in progress | `POST /api/v1/driver/assignments/{assignment}/in-progress` with `{}`. | `200 OK`; status is `IN_PROGRESS`. | [ ] |
| DIS-09 | Mark work done | `POST /api/v1/driver/assignments/{assignment}/work-done` with optional `completionNotes`. | `200 OK`; status is `WORK_DONE`; completion time and release are recorded. | [ ] |
| DIS-10 | Complete via existing endpoint | On a different fresh assignment, use `/attend`, `/reached`, then `/complete`. | Existing flow still returns `EN_ROUTE`, `ARRIVED`, then `COMPLETED`; assignment is released. | [ ] |
| DIS-11 | Work done directly after arrival | On a fresh assignment, use `/attend`, `/started`, then `/work-done` without `/in-progress`. | Work is done successfully; status history includes the expected intermediate `IN_PROGRESS` transition. | [ ] |
| DIS-12 | Decline pending assignment | Before attending an assignment, call `/decline` with a required `reason`. | Status becomes `DECLINED`; allocation is released; reason is retained. | [ ] |
| DIS-13 | Reject decline after starting | Attend an assignment, then attempt to decline it. | Request is rejected; active status/allocation remains unchanged. | [ ] |
| DIS-14 | Report driver issue | Call `/report-issues` with a non-empty `selectedIssues` array and optional `issueRemarks`. | Response returns issue details; remark/action history includes the report. | [ ] |
| DIS-15 | Ask for help / escalate | Call `/need-help` with a supported `helpType` and comments, or `/escalate` with a reason. | Action is saved and authorized control-room recipients receive an alert. | [ ] |
| DIS-16 | Close finished assignment | After `/complete` or `/work-done`, call `/close` with a reason. | Close remark is recorded; no second completion transition is created. | [ ] |
| DIS-17 | Reject another driver's assignment access | Authenticate as another driver and use the first driver's assignment ID on an action endpoint. | Request is denied/not found; original assignment is unchanged. | [ ] |
| DIS-18 | Verify status propagation | After each dispatch/driver status update, refresh incident detail, queues, and dashboard. | Assignment, aggregate incident status, vehicle/team state, and timeline reflect the change consistently. | [ ] |
| DIS-19 | Queue a busy vehicle/driver pair and team | While a pair/team is assigned to an active incident, assign the same vehicle and driver together (and optionally the same busy team) to other incidents. Check the fleet list and active-assignment endpoint, then finish the active work. | New assignments show `QUEUED`, the driver's active assignment remains unchanged, and the oldest eligible queued assignment becomes `ASSIGNED` automatically when all its resources are available. Later work remains queued until its turn. A mismatched busy vehicle/driver pair is rejected. | [ ] |
| DIS-20 | Wait for an off-road queued vehicle | Queue the same active vehicle/driver pair for another incident, set the vehicle `OFF_ROAD`, then finish the active assignment. Set the vehicle `ON_ROAD` again. | Assignment remains `QUEUED` while the vehicle is off road, then automatically becomes `ASSIGNED` when the vehicle returns On Road and no other queued resources are busy. | [ ] |
| DIS-21 | Prevent conflicting assignment reopen | Complete an assignment that has a queued next assignment, then attempt to reopen the completed assignment while the next assignment is active or queued. | Reopening is rejected with a status validation message; existing active and queued assignments are unchanged. | [ ] |
| DIS-22 | Accept uppercase in-progress action | With an active assignment, call `POST /api/v1/driver/assignments/{assignment}/IN_PROGRESS` as its assigned driver. | `200 OK`; assignment status and status history become `IN_PROGRESS`. | [ ] |

## 8. Vehicle tracking and operational status

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| GPS-01 | Open tracking page | Sign in as a user with GPS permission and open `/tracking`. | Authorized map and vehicle list load; records remain station-scoped. | [ ] |
| GPS-02 | Receive driver GPS sample | For an eligible active assignment/team, `POST /api/v1/vehicles/{vehicle}/gps` with `assignment_id`, `latitude`, `longitude`, and optional speed/time. | `201 Created`; point is appended and latest vehicle coordinates update. | [ ] |
| GPS-03 | Reject invalid or stale GPS | Submit out-of-range coordinates/speed/time or a point older than the last accepted point. | Validation error; no stale/invalid point is accepted. | [ ] |
| GPS-04 | Verify route history | Request `GET /api/v1/vehicles/{vehicle}/gps` or inspect vehicle route in tracking UI. | Authorized location history is returned in time order; other stations' routes are inaccessible. | [ ] |
| GPS-05 | Change linked vehicle road status | As assigned vehicle driver, `PATCH /api/v1/vehicles/{vehicle}/operational-status` with `{"status":"OFF_ROAD"}`, then `{"status":"ON_ROAD"}`. | Each valid change updates operational status and appends actor/time history; authorized recipients are notified. | [ ] |
| GPS-06 | Reject invalid vehicle status | Submit a status other than `ON_ROAD` or `OFF_ROAD`, or attempt to update a vehicle not linked to the driver. | Request is rejected; vehicle status/history are unchanged. | [ ] |
| GPS-07 | Verify logout status | As a driver with a linked vehicle, call `POST /api/v1/auth/logout`, then retry an authenticated endpoint. | Token is revoked; linked vehicle is marked `OFF_ROAD` and status history records logout source. | [ ] |
| GPS-08 | Record location at driver login | Log in as a linked vehicle driver with paired `latitude`, `longitude`, and optional `recorded_at`; then inspect the login response and vehicle GPS history. | Login succeeds; a GPS sample is recorded and the linked vehicle's latest location matches the submitted point. | [ ] |
| GPS-09 | Reject invalid login coordinates | Attempt driver login with an out-of-range coordinate or only one of `latitude` / `longitude`. | Validation fails; no GPS sample or partial vehicle-location update is recorded. | [ ] |

## 9. Reports, exports, archive, and notifications

| ID | Scenario | Steps | Expected result | Result |
|---|---|---|---|---|
| REP-01 | Load operations report | Open `/reports` as a user with report-view permission. | Summary and breakdowns load for the default period and only accessible stations. | [ ] |
| REP-02 | Filter report period and station | Change date range and station filter to allowed values. | Data and metrics update to match selected filters. | [ ] |
| REP-03 | Reject invalid report filters | Try a range longer than 90 days, invalid dates, or an out-of-scope station ID. | Validation/authorization error is returned; restricted report data is not disclosed. | [ ] |
| REP-04 | Export reports | Use CSV, XLSX, and PDF exports with an allowed period/station filter. | Each file downloads in the requested format with matching scoped data. | [ ] |
| REP-05 | Restrict export separately | As a report viewer without export permission, try each export URL directly. | Export is denied even if report viewing is allowed. | [ ] |
| ARC-01 | Archive eligible incident | Complete or transfer a test incident with no active assignments; archive it. | Incident disappears from active queues and is available in Archive; history remains. | [ ] |
| ARC-02 | Reject archive with active assignment | Try to archive an incident with a currently assigned resource. | Archive is blocked and active assignment remains intact. | [ ] |
| ARC-03 | Restore archived incident | Restore a test archived incident from `/archive`. | Incident returns to the appropriate active directory; history and references remain. | [ ] |
| NOT-01 | Receive and read notification | Cause a dispatch/status change that should notify the test user; open Notifications and mark one read. | Correct recipient sees the notification; read state updates only for that notification. | [ ] |
| NOT-02 | Mark all notifications read | Select Mark all read. | All of the current user's unread notifications become read; other users' states do not change. | [ ] |
| NOT-03 | Verify recipient scoping | Sign in as another user and inspect notifications. | The other user cannot see the first user's notification content. | [ ] |

## 10. API smoke and authorization checks

These are API-focused checks complementary to the browser workflows. Use `/api/v1/auth/login` for a bearer token. Do not copy real tokens into this file or test evidence.

| ID | Scenario | Request | Expected result | Result |
|---|---|---|---|---|
| API-01 | Login and current user | `POST /api/v1/auth/login`, then `GET /api/v1/auth/me` with bearer token. | Login returns token/user; current-user response matches signed-in identity, roles, permissions, and station scope. | [ ] |
| API-02 | Reject unauthenticated API call | Call a protected API route without a token. | `401 Unauthorized`; no protected data is returned. | [ ] |
| API-03 | Browse API jobs and callers | `GET /api/v1/jobs` and `GET /api/v1/callers/search` using a permitted user. | Responses are valid JSON, paginated/scoped as applicable, and contain only authorized records. | [ ] |
| API-04 | Browse API catalogues | Request permitted catalogue endpoints such as `/api/v1/fire-stations`, `/api/v1/wards`, `/api/v1/vehicles`, and `/api/v1/teams`. | Results and pagination metadata are valid; unsupported permissions and station scope are enforced. | [ ] |
| API-05 | API job creation and validation | `POST /api/v1/jobs` with valid incident data, then repeat with missing/invalid required data. | Valid request creates one incident; invalid request returns validation errors without partial data. | [ ] |
| API-06 | API reports and tracking | Call `/api/v1/reports/operations` and `/api/v1/tracking` with authorized and unauthorized station filters/users. | Authorized response is scoped; unauthorized permissions or stations are denied. | [ ] |
| API-07 | API logout | `POST /api/v1/auth/logout`, then retry `/api/v1/auth/me` using the same token. | Logout returns no-content success; the revoked token can no longer access protected endpoints. | [ ] |
## 11. Driver status API request examples

Example `STARTED` request (use a current ISO-8601 timestamp):

```http
POST {{base_url}}/api/v1/driver/assignments/{{assignment_id}}/started
Authorization: Bearer {{token}}
Accept: application/json
Content-Type: application/json

{
  "reachedLatitude": 22.3072,
  "reachedLongitude": 73.1812,
  "reachedTime": "2026-11-13T10:00:00+05:30"
}
```

Example `IN_PROGRESS` request:

```http
POST {{base_url}}/api/v1/driver/assignments/{{assignment_id}}/in-progress
Authorization: Bearer {{token}}
Accept: application/json
Content-Type: application/json

{}
```

The uppercase route `POST {{base_url}}/api/v1/driver/assignments/{{assignment_id}}/IN_PROGRESS` is also supported.

Example `WORK_DONE` request:

```http
POST {{base_url}}/api/v1/driver/assignments/{{assignment_id}}/work-done
Authorization: Bearer {{token}}
Accept: application/json
Content-Type: application/json

{
  "completionNotes": "Response work completed successfully"
}
```

> The general job assignment status API retains the legacy `STARTED` → `EN_ROUTE` alias. To record the driver-specific `STARTED` state at the incident, use the driver `/started` endpoint.
