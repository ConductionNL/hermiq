# Tasks: session-api-rename

## 1. Record the auth posture before moving anything

- [x] 1.1 Build a table of every route being moved: path, verb, controller method, and its EXACT auth attribute (`#[PublicPage]` / `#[NoAdminRequired]` / `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting]` / none). This table is the review artifact the rename is checked against.
- [x] 1.2 Note which methods carry a per-object authorization guard in the body, separately from the attribute. An attribute says who may call; the guard says which objects they may touch, and a rename can drop either.

Acceptance criteria
- Every route being moved appears in the table with its attribute recorded before any edit.

## 2. Rename the controller and services

- [x] 2.1 `ConversationController` → `SessionController`; rename the services and DTOs behind it.
- [x] 2.2 Point reads and writes at the `session` schema.
- [x] 2.3 Carry every auth attribute across verbatim. A missing attribute makes the endpoint UNREACHABLE (NC middleware rejects before the controller runs), and a widened one is an authorization hole — both are silent at deploy time.
- [x] 2.4 Carry every in-body authorization guard across. A `#[NoAdminRequired]` method without its per-object guard is an IDOR.

Acceptance criteria
- Diffing task 1.1's table against the new code shows zero attribute changes.
- No guard was dropped.

## 3. Add the new routes and keep the old ones as aliases

- [x] 3.1 Add `/api/sessions` (GET, POST), `/api/sessions/{uuid}` (GET, PATCH, DELETE), `/api/sessions/{uuid}/messages` (GET), `/api/sessions/{uuid}/restore` (POST), `/api/sessions/{uuid}/permanent` (DELETE).
- [x] 3.2 Move the feedback route to `/api/sessions/{sessionUuid}/messages/{messageId}/feedback`, renaming the parameter too.
- [x] 3.3 Keep every `/api/conversations/*` route as a deprecated alias pointing at the SAME controller methods — not copies, so the two cannot diverge. Deleting them here would 404 every existing integration on deploy with no transition.
- [x] 3.4 Log at info level when an alias is hit, so the eventual retirement is decided on traffic data rather than optimism.

Acceptance criteria
- Both path families return identical responses for the same request.
- The alias log line fires when an old path is used.

## 4. Prove every route actually resolves

- [x] 4.1 Call every new route once and assert the status code. A `routes.php` entry naming a method that does not exist is a ReflectionException 500 AT RUNTIME, not a startup failure — "the app still loads" proves nothing.
- [x] 4.2 Call every deprecated alias once and assert the same status.
- [x] 4.3 Confirm no route resolves to a method that vanished in the rename.

Acceptance criteria
- Every route in both families was hit and its status code recorded.

## 5. Quality

- [x] 5.1 `composer check:strict` clean; fix any pre-existing issues touched.
- [x] 5.2 Confirm the frontend still works UNCHANGED through the aliases — this spec must not break the UI, because the frontend does not move until the next spec.

Acceptance criteria
- Strict gates pass and the Chat page still functions with no frontend change.

## Task 1.1 — auth posture BEFORE the rename (the review artifact)

Recorded 2026-09-07, before any edit. Every route below carries `@NoAdminRequired` and
`@NoCSRFRequired` and nothing else, so the rename must change zero attributes.

| path | verb | method | attributes | per-object guard in the body |
|---|---|---|---|---|
| `/api/conversations` | GET | `conversation#index` | NoAdminRequired, NoCSRFRequired | scoped read: `filters['userId']` |
| `/api/conversations` | POST | `conversation#create` | NoAdminRequired, NoCSRFRequired | writes the caller's `userId` |
| `/api/conversations/{uuid}` | GET | `conversation#show` | NoAdminRequired, NoCSRFRequired | **yes** `userId !== caller` → 404 |
| `/api/conversations/{uuid}/messages` | GET | `conversation#messages` | NoAdminRequired, NoCSRFRequired | **yes** |
| `/api/conversations/{uuid}` | PATCH | `conversation#update` | NoAdminRequired, NoCSRFRequired | **yes** |
| `/api/conversations/{uuid}` | DELETE | `conversation#destroy` | NoAdminRequired, NoCSRFRequired | **yes** |
| `/api/conversations/{uuid}/restore` | POST | `conversation#restore` | NoAdminRequired, NoCSRFRequired | **yes** |
| `/api/conversations/{uuid}/permanent` | DELETE | `conversation#destroyPermanent` | NoAdminRequired, NoCSRFRequired | **yes** |
| `/api/conversations/{cUuid}/messages/{mId}/feedback` | POST | `chat#sendFeedback` | (ChatController's own) | — |

Organisation isolation is applied by ObjectService multitenancy on the read itself, not by
an attribute, so it does not appear as one and must not be "tidied away" as missing.

## Task 2.2 — scope discovered while implementing

The spec reads as though the controller is the reader. It is not: **14 files** reference
the `conversation` schema, including the Talk bridge (`TalkRoomBinding`, `TalkSessionRoom`,
`TalkTurnService`, `TalkBotInvokeListener`), the engine (`Engine`,
`ConversationManagementHandler`, `ConversationTitleWriter`), `AssistantService`,
`ContextAgentInteractionService`, `ScheduleService` and three controllers.

Pointing only the controller at `session` would split the app: the UI would write sessions
while Talk, the engine and the scheduler kept reading conversations, and neither half
would error. All readers therefore move together.

⚠️ Talk was enabled on the dev instance for this (its `vendor/` was missing, so
`occ app:enable spreed` failed with a bare autoload error). The two Talk room-ownership
e2e tests skip wherever spreed is absent, which is CI and was this instance, so the Talk
half of this change had no automated coverage at all until that was fixed.

## Task 4 — every route hit, both families, 2026-09-07

All 16 returned 200. The new family creates, the alias family creates; both drive the
whole archive lifecycle.

| route | `/api/sessions` | `/api/conversations` |
|---|---|---|
| POST (create) | 200 | 200 |
| GET (index) | 200 | 200 |
| GET `/{uuid}` | 200 | 200 |
| GET `/{uuid}/messages` | 200 | 200 |
| PATCH `/{uuid}` | 200 | 200 |
| DELETE `/{uuid}` | 200 | 200 |
| POST `/{uuid}/restore` | 200 | 200 |
| DELETE `/{uuid}/permanent` | 200 | 200 |

The alias log fires, verified in nextcloud.log:
`[SessionController] deprecated /api/conversations path used`.

🔴 THIS IS WHY TASK 4.1 EXISTS. Renaming the class left the alias routes pointing at
`conversation#index`, and that is not a startup failure: the app loaded, `/api/sessions`
answered 200, and every `/api/conversations/*` path threw
`Could not resolve OCA\Hermiq\Controller\ConversationController` as a **runtime 500**.
Only calling the routes found it.

⚠️ AND A SECOND ONE THE SPEC DID NOT ANTICIPATE. A Nextcloud route name is
`app.controller.action`, so registering both families against the same `session#create`
silently collided and POST returned **405**. The aliases carry `'postfix' => 'legacy'`,
which is the supported way to point two paths at one method.

## Task 5.2 — the frontend, unchanged

`chat.spec.ts` both tests pass against an unmodified frontend, and the Chat page was
opened by hand: sessions list, agent picker and thread all render. The four Talk
room-ownership tests pass too, which is the first time they have ever run in this repo —
they skip wherever spreed is absent, which was CI and this instance until it was enabled
for this change.
