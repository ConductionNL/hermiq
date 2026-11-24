# Hand-over to buildiq: the form-fill route

For buildiq's `ai-smart-paste-into-forms` handler, registered with nextcloud-vue's `form-smart-paste`
renderer as `{ available, fill }`. Hermiq is the only side that talks to a model; buildiq sends the
pasted text and the form's fields and gets proposals back.

## `GET /index.php/apps/hermiq/api/assistant/form-fill/available`

Signed-in user. `200 {"available": true|false}`; `401` without a user. `available` is false until an
administrator has acknowledged and enabled the `form-fill` AI feature and a chat model is configured.
Wire it to the renderer's `available()`.

## `POST /index.php/apps/hermiq/api/assistant/form-fill`

Signed-in user (never on a public form), rate-limited to 30 calls a minute per user.

Request:

```json
{
  "text": "Met vriendelijke groet, ... (at most 10,000 characters)",
  "fields": [
    {"key": "email", "label": "E-mailadres", "type": "email", "options": []}
  ],
  "language": "nl"
}
```

- `fields`: 1 to 50; `key` matches `^[A-Za-z0-9_.-]{1,64}$` and is unique; `label` at most 200
  characters; `type` a short lowercase word; `options` at most 100 strings (a choice field).
- `language`: BCP-47, defaults to `nl`.

Answers:

- `200 {"available": true, "values": {"email": "jan.jansen@voorbeeld.nl"}, "draft": true, "notice": "...", "provider": "..."}`:
  `values` only ever holds requested keys; a value outside a field's `options`, or text in a number
  field, is dropped rather than proposed. Show `notice` and let the person accept or edit; never save
  without them.
- `200 {"available": false, "reason": "feature-not-enabled" | "provider-error"}`: offer no proposals.
- `400 {"error": "..."}`: the request broke a limit above. `401` without a user. `500` unexpected.

Wire `fill(text, fields)` to this POST and return `{ values }` to the renderer.

Checked by the "Form fill" folder of `tests/integration/hermiq.postman_collection.json`.
