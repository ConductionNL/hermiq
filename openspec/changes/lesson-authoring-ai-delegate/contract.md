# Contract: lesson-authoring-ai-delegate

## Consumers

- `learniq`, change `lesson-ai-assist-actions` (round 2, wave 2): four assist buttons in `LessonComposer`. Each calls one endpoint below and inserts the result as an editable draft (a `richText` block, or a `competencyIds` suggestion the teacher accepts). The consumer change is out of scope here.
- `learniq`, change `office-file-lesson-onboarding` (optional): may use `simplify` or `outline` to tidy extracted text after the teacher confirms an import (decision D17).

Obligations on every consumer:

1. Send lesson content and goal titles only. Never send a pupil name, number, grade, note or any other pupil data, in any field. Map goal ids to titles before the call and map the returned indexes back after it, so ids never reach the model.
2. Show the teacher, next to the action, that the text goes to an AI model and must not contain pupil data.
3. Treat every result as a draft. Insert it where the teacher can edit or discard it. Never publish it or save it as final without a teacher action.
4. Handle `available: false` as a normal outcome: hide or disable the action, and never show it as a crash.

## Transport and auth

- Base path: `/apps/hermiq/api/lesson-authoring/`. From a browser, build it with `generateUrl('/apps/hermiq/api/lesson-authoring/<action>')` from `@nextcloud/router`.
- **Auth**: a Nextcloud session (`#[NoAdminRequired]`). Any logged-in user may call it. There is no per-object check, because no caller-supplied identifier is looked up.
- **CSRF**: required. `@nextcloud/axios` sends the `requesttoken` header automatically. A call without a valid token gets Nextcloud's own CSRF refusal.
- **Rate limit**: 30 calls per user per 60 seconds per endpoint (`#[UserRateLimit]`). Excess calls get HTTP 429 from Nextcloud.
- **Content type**: JSON request body (`Content-Type: application/json`), JSON response.

## Shared fields

### Content fields (the only content that reaches the model)

| Field | Type | Limits |
|---|---|---|
| `lessonText` | string | 1 to 20,000 characters after trimming |
| `goalTitles` | string[] | 1 to 100 items; each a non-empty string of at most 300 characters |

### Control fields (bounded values, cannot carry free text)

| Field | Type | Allowed | Default |
|---|---|---|---|
| `language` | string | a BCP-47 tag matching `^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8}){0,2}$`, for example `nl`, `en`, `nl-NL` | `nl` |
| `questionCount` | integer | 1 to 10 | 5 |
| `readingLevel` | string | `A1`, `A2`, `B1`, `B2` (CEFR) or `1F`, `2F`, `3F` (Dutch referentieniveaus) | `B1` |

Any other request field is ignored and never reaches the model.

### Success envelope (HTTP 200)

```json
{
  "available": true,
  "action": "outline",
  "draft": true,
  "draftNotice": "This is an AI-generated draft. Check and edit it before you use it.",
  "provider": "nextcloud"
}
```

- `action`: one of `outline`, `questions`, `simplify`, `goal-suggestions`.
- `draft`: always `true` on success. This is the machine-readable draft marker. Render your own translated label from it; `draftNotice` is an English fallback.
- `provider`: the chat provider Hermiq resolved (`nextcloud` means Nextcloud task processing; also `ollama`, `openai`, `fireworks`, `anthropic`). Show it where the teacher can see which model service was used.

Each endpoint adds its own result fields to this envelope, listed below.

### Unavailable envelope (HTTP 200)

```json
{ "available": false, "action": "outline", "reason": "feature-not-enabled" }
```

`reason` is one of:

- `feature-not-enabled`: the `lesson-authoring` AI feature is missing, or it is not `enabled`. It is seeded `disabled`, and a DPO must acknowledge it before an admin can enable it.
- `provider-error`: the feature is enabled, but the model call failed or returned nothing usable (empty text, no questions, or a goal answer that names no goal from the list).

Both are 200 responses by design. An unavailable result is expected, not exceptional.

## Endpoints

### `POST /api/lesson-authoring/outline`

Draft a lesson outline from one or more learning goals.

**Request:**
```json
{
  "goalTitles": ["De leerling kan breuken vergelijken en ordenen"],
  "lessonText": "Optional existing notes to build on.",
  "language": "nl"
}
```
- `goalTitles` (required): the goals the lesson should serve.
- `lessonText` (optional): an existing draft or notes to build on.
- `language` (optional): the language of the outline.

**Response (200, available):** the success envelope plus
```json
{ "draftText": "1. Start ...\n2. Instructie ...\n3. Verwerking ...\n4. Afsluiting ..." }
```
`draftText` is plain text, one outline item per line.

### `POST /api/lesson-authoring/questions`

Suggest questions for a lesson.

**Request:**
```json
{
  "lessonText": "Breuken met dezelfde noemer vergelijk je door de tellers te vergelijken ...",
  "goalTitles": ["De leerling kan breuken vergelijken en ordenen"],
  "questionCount": 5,
  "language": "nl"
}
```
- `lessonText` (required).
- `goalTitles` (optional): focus the questions on these goals.
- `questionCount` (optional), `language` (optional).

**Response (200, available):** the success envelope plus
```json
{ "questions": ["Welke breuk is groter: 3/8 of 5/8?", "..."] }
```
`questions` holds 1 to `questionCount` strings, without numbering.

### `POST /api/lesson-authoring/simplify`

Rewrite a text at a lower reading level. This changes the text, never a pupil's level or placement.

**Request:**
```json
{
  "lessonText": "De fotosynthese is het proces waarbij planten ...",
  "readingLevel": "A2"
}
```
- `lessonText` (required).
- `readingLevel` (optional): the target level.

The output stays in the language of the input text, so this endpoint takes no `language` field.

**Response (200, available):** the success envelope plus
```json
{ "draftText": "Planten maken hun eigen voedsel. ...", "readingLevel": "A2" }
```

### `POST /api/lesson-authoring/goal-suggestions`

Suggest which of the given goals a lesson covers. The model only chooses from the caller's list and never invents a goal.

**Request:**
```json
{
  "lessonText": "We vergelijken breuken met dezelfde noemer en zetten ze op volgorde ...",
  "goalTitles": [
    "De leerling kan breuken vergelijken en ordenen",
    "De leerling kan procenten berekenen",
    "De leerling kan breuken op een getallenlijn plaatsen"
  ]
}
```
- `lessonText` (required).
- `goalTitles` (required): the candidate goals, in the caller's order.

**Response (200, available):** the success envelope plus
```json
{
  "suggestedGoals": [
    { "index": 0, "title": "De leerling kan breuken vergelijken en ordenen" },
    { "index": 2, "title": "De leerling kan breuken op een getallenlijn plaatsen" }
  ]
}
```
- `index` is the 0-based position in the request's `goalTitles`. Map it back to your own goal id.
- Entries are unique and sorted by `index`. An empty list means the model found no match among the given goals. That is a valid answer, and it is distinct from `provider-error`.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 200 + `available: true` | Draft produced | Feature enabled, model answered, output parsed |
| 200 + `available: false` | Feature unavailable | `feature-not-enabled` or `provider-error` (see above) |
| 400 | Bad request | A required field is missing, or a field breaks its type or limits. Body: `{"error": "<which field and why>"}`. Checked before the gate and before any model call |
| 401 | Unauthenticated | No Nextcloud session. Body: `{"error": "Unauthenticated"}` |
| 412 | CSRF check failed | Nextcloud refused the request because the `requesttoken` was missing or invalid |
| 429 | Too many requests | The per-user rate limit was exceeded |
| 500 | Unexpected failure | An unexpected error outside the model call. Body: `{"error": "Could not run the lesson authoring action"}` |

## Logging

Hermiq writes one `info` line per call to the Nextcloud log: action, user id, outcome (`ok`, `feature-not-enabled`, `provider-error`), provider, lesson text length and goal count. It never logs the lesson text, the goal titles or the model output. A provider failure also logs a `warning` with the exception message only.

## Versioning

Unversioned, like every other Hermiq REST endpoint (`/api/translate`, `/api/recommendations`). New optional request fields and new response fields may be added without notice. Consumers must ignore response fields they do not know. Removing or renaming a field, or changing a type, is a breaking change.

## Breaking Change Policy

A breaking change needs a PR that says so in its title and body, and a matching change in learniq that lands in the same release. Nothing is removed while a released learniq still reads it.

## SLA

No formal SLA. Response time is bounded by the configured provider's latency (`ProviderFactory::generateText()`); task processing through a local model can take tens of seconds, so show a busy state. Availability follows the DPO gate: until the feature is acknowledged and enabled, every call answers `feature-not-enabled`.
