# Design: tools-custom-tools-without-code

Kind: code. Size M. One schema, a descriptor builder and an invoker behind `HermiqToolProvider`, one page and one editor.

## Context at development db6b74dc

- `lib/Mcp/HermiqToolProvider.php:648-657` `getTools()` merges the constant tables; `:675-765` `invokeTool()` dispatches by id and never throws.
- `lib/Service/Engine/FacadeToolInvoker.php`: `FLOW_QUEUEING_TOOL_IDS` `:258`, `FLOW_OWNER_ARGUMENT` `:273`, `withFlowOwner()` `:680`, `isWaived()` `:1017-1041` (a grant entry can pin `flowId` and waive approval for that flow), `dispatchToFacade()` `:1213`, the untrusted markers used for `webFetch` results through `WebFetchService.php:72`.
- `lib/Service/SkillMarketplaceService.php:458-470` and `lib/Support/FleetAppId.php:85`, `:108`: integriq's `CallService`. Integriq at `413357ec`: `CallService::call()` `lib/Service/CallService.php:2957`.
- `lib/Settings/hermiq_register.json:2590-2597` the `Agent.tools` grant grammar resolved by OpenRegister's `ToolGrantResolver`.
- `openspec/specs/agent-capability-reach/spec.md` the reach vocabulary and the rule that an undeclared reach is `external`.
- `src/views/McpTools.vue:4-16` the read-only catalogue page, `src/manifest.json` route `/mcp-tools`.

## D1. The CustomTool object

`CustomTool` in the `hermiq` register: `slug` (`[a-z0-9_]{3,40}`, unique on the instance), `name`, `description` (what the model reads), `inputSchema` (a JSON Schema object whose properties are `string`, `number`, `integer`, `boolean` or a string `enum`, with `required`), `changesData` (`no`, `yes`, `irreversible`), `target`, `enabled`. `target` is one of:

- `{type: "flow", flowId}`: an OpenRegister flow.
- `{type: "integriq", source, method, path, bodyTemplate}`: an integriq source, an HTTP method, a path under the source, and a JSON body template in which `{{input.<name>}}` inserts an argument. With no template the arguments are sent as the JSON body.

Only organisation admins and instance admins create and edit custom tools; OpenRegister's multitenancy puts each one in its organisation. Agent owners grant them.

## D2. A two-segment id, on purpose

The tool id is `hermiq.custom_<slug>`. A dotted `hermiq.custom.<slug>` would have three segments, and OpenRegister's grant grammar reads three segments as `{app}.{schema}.{verb}`: a grant `hermiq.custom.*` would then silently grant every custom tool whose slug happens to be `search` or `get`. Two segments keep custom tools on the exact-id path only, so no wildcard ever grants one.

## D3. Descriptors built per call

`getTools()` merges a fourth list built from the enabled `CustomTool` objects the acting user can read. Hints come from `changesData`: `no` gives `readOnlyHint: true`; `yes` gives `scope: update`, `readOnlyHint: false`; `irreversible` adds `destructiveHint: true`. Reach comes from the target: an integriq target is `external`, because the call leaves the instance. A flow target inherits the reach OpenRegister declares for `openregister.runFlow`, and falls back to `external` when none is declared, as the reach spec requires.

The admin's `changesData` can only make a tool stricter than its target implies, never looser: an integriq target can be marked read-only, but its reach stays `external`, so it is still default-denied.

## D4. Invocation

`invokeTool()` routes `hermiq.custom_*` to a `CustomToolInvoker`. It reloads the object as the acting user (disabled or unreadable means `unknown_tool`), validates the arguments against `inputSchema` and refuses a mismatch with `invalid_argument` naming the field, then:

- flow target: calls `openregister.runFlow` through the same tool facade with `flowId` fixed to the tool's flow and the arguments as the flow input. The owner rule of `FacadeToolInvoker` applies unchanged, so a run without a resolvable owner is refused.
- integriq target: renders the body, and calls `CallService::call()` on the source as the acting user. The answer's body is cut to 20 KB, delimited with the untrusted markers and returned. Hermiq opens no HTTP client of its own, as `nc-native-tools` requires.

Errors come back as the usual `{error: {code, message}}` envelope; `invokeTool()` still never throws.

## D5. Governance is the existing governance

A custom tool is granted only by its exact id in `Agent.tools`. It goes through `FacadeToolInvoker`'s whole chain: the grant constraints, the organisation's guardrail classification, the approval gate for write-classified or `instance`-and-up tools, the waiver rules, tracing and redaction. The tool step on the run names the custom tool, its version (the object's audit entry) and the target.

Rejected: a separate permission for custom tools. A second permission model next to the grant is the one that drifts.

## D6. The screens

A "Custom tools" index page, manifest-driven on the `CustomTool` schema, reachable from the MCP tools page through "Add a custom tool". The editor modal (its own file under `src/modals/`) has name, slug, description, a small input builder (name, type, required, description), "What does this tool change?" with the three choices, the target picker (an OpenRegister flow, or an integriq source with method, path and body template), and "Try it", which runs the tool once as the admin with sample inputs and shows the answer, after the warning "This really runs the flow or calls the system."

## Declarative versus imperative

`CustomTool` is declared in the register, including the target shape and the enums. Descriptor building and invocation are runtime code in the provider, because the MCP tool ABI is PHP.

## Seed data

- `CustomTool` "Vergunningcheck" for "Gemeente Voorbeeld": `slug: "vergunningcheck"`, description "Checks whether an activity at an address needs a permit, and names the rule.", inputs `adres` (string, required) and `activiteit` (enum: `terras`, `evenement`, `kap`), `changesData: "no"`, target flow "Vergunningcheck".
- `CustomTool` "Adres opzoeken in BAG": `slug: "bag_adres"`, input `postcode` and `huisnummer`, `changesData: "no"`, target integriq source "bag-api", `GET`, path `/adressen?postcode={{input.postcode}}&huisnummer={{input.huisnummer}}`, key `YOUR_API_KEY_HERE` held by the source in the broker.

## Risks

- A description that misleads the model. Mitigation: only admins write custom tools, every tool step names the tool, and the description is in the audited object.
- A slug that shadows a built-in tool. Mitigation: the `custom_` prefix is reserved for custom tools; built-in ids never use it.
- An outside system answering with instructions. Mitigation: the answer is delimited as untrusted and capped.
