# Tasks: session-api-rename

## 1. Record the auth posture before moving anything

- [ ] 1.1 Build a table of every route being moved: path, verb, controller method, and its EXACT auth attribute (`#[PublicPage]` / `#[NoAdminRequired]` / `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting]` / none). This table is the review artifact the rename is checked against.
- [ ] 1.2 Note which methods carry a per-object authorization guard in the body, separately from the attribute. An attribute says who may call; the guard says which objects they may touch, and a rename can drop either.

Acceptance criteria
- Every route being moved appears in the table with its attribute recorded before any edit.

## 2. Rename the controller and services

- [ ] 2.1 `ConversationController` → `SessionController`; rename the services and DTOs behind it.
- [ ] 2.2 Point reads and writes at the `session` schema.
- [ ] 2.3 Carry every auth attribute across verbatim. A missing attribute makes the endpoint UNREACHABLE (NC middleware rejects before the controller runs), and a widened one is an authorization hole — both are silent at deploy time.
- [ ] 2.4 Carry every in-body authorization guard across. A `#[NoAdminRequired]` method without its per-object guard is an IDOR.

Acceptance criteria
- Diffing task 1.1's table against the new code shows zero attribute changes.
- No guard was dropped.

## 3. Add the new routes and keep the old ones as aliases

- [ ] 3.1 Add `/api/sessions` (GET, POST), `/api/sessions/{uuid}` (GET, PATCH, DELETE), `/api/sessions/{uuid}/messages` (GET), `/api/sessions/{uuid}/restore` (POST), `/api/sessions/{uuid}/permanent` (DELETE).
- [ ] 3.2 Move the feedback route to `/api/sessions/{sessionUuid}/messages/{messageId}/feedback`, renaming the parameter too.
- [ ] 3.3 Keep every `/api/conversations/*` route as a deprecated alias pointing at the SAME controller methods — not copies, so the two cannot diverge. Deleting them here would 404 every existing integration on deploy with no transition.
- [ ] 3.4 Log at info level when an alias is hit, so the eventual retirement is decided on traffic data rather than optimism.

Acceptance criteria
- Both path families return identical responses for the same request.
- The alias log line fires when an old path is used.

## 4. Prove every route actually resolves

- [ ] 4.1 Call every new route once and assert the status code. A `routes.php` entry naming a method that does not exist is a ReflectionException 500 AT RUNTIME, not a startup failure — "the app still loads" proves nothing.
- [ ] 4.2 Call every deprecated alias once and assert the same status.
- [ ] 4.3 Confirm no route resolves to a method that vanished in the rename.

Acceptance criteria
- Every route in both families was hit and its status code recorded.

## 5. Quality

- [ ] 5.1 `composer check:strict` clean; fix any pre-existing issues touched.
- [ ] 5.2 Confirm the frontend still works UNCHANGED through the aliases — this spec must not break the UI, because the frontend does not move until the next spec.

Acceptance criteria
- Strict gates pass and the Chat page still functions with no frontend change.
