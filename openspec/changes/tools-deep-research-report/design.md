# Design: tools-deep-research-report

Kind: code. Size M. A research runner that drives the existing tools in phases, a source ledger, a citation check, a report writer into Files, and the chat controls. It rides on the handoff of `flows-hand-off-and-wait` and on the token usage `models-several-models-per-turn` makes every provider report.

## Context at development db6b74dc

- `lib/Mcp/HermiqToolProvider.php:465-512` the `webSearch` and `webFetch` descriptors, `:1185-1210` their handlers; `:513-553` `delegateAgent`.
- `lib/Service/WebResearch/WebFetchService.php:72` the untrusted delimiter, `:112` `fetch()`, `:164` and `:207-214` the size cap.
- `lib/Service/Engine/FacadeToolInvoker.php:448` the governed `__call()` chain every tool call passes.
- `lib/Service/Llm/ProviderFactory.php:219` `MAX_TOOL_ITERATIONS`.
- `lib/Service/Engine/ResponseGenerationHandler.php:358` the citation instruction.
- `lib/Service/NcNative/AgentArtefactMarker.php` `TAG_NAME` "Agent authored", `markFile()`; `lib/Service/NcNative/NotesWriteService.php:223-248` marks in the same operation and reports a failed mark as a failed write.
- `lib/Service/BudgetService.php:189` `isBlocked()`; `lib/Flow/TenantKillSwitchCheck.php` and `ScheduleService::isOrganisationEngaged()` for the kill switch.
- `openspec/changes/flows-hand-off-and-wait/design.md` D1 and D2, the handoff and its delivery into the conversation.

## D1. A research run is a handoff, not a long turn

One chat turn has at most ten tool rounds, and research needs dozens. A research run is therefore a background job that owns its own loop and reports back through the handoff mechanism: a `Handoff` of kind `research` with the question, the plan, the ledger, the caps and the progress. The chat turn that starts it returns at once with the plan.

Rejected: raising the tool round cap for one turn. A request that holds for twenty minutes breaks on every proxy timeout and holds a PHP worker the whole time.

## D2. Phases

1. Plan: one model call writes three to eight sub-questions. They are posted in the conversation as "Research plan" and the run continues unless the user presses "Stop research".
2. Gather: for each sub-question the runner lets the model call the research tools it is granted, `hermiq.webSearch` and `hermiq.webFetch` at least, through `FacadeToolInvoker`, so grants, guardrails, the egress guard, tracing and redaction apply exactly as in chat. Each fetched page enters the ledger. The model writes notes per sub-question with ledger numbers.
3. Write: one model call writes the report from the notes and the ledger, in Markdown, with citations in the form `[3: "exact passage"]`.
4. Check: hermiq checks every citation (D3), then renders the report.

The agent owner's prompt and the organisation's model policy apply to every model call. The run uses the agent's own provider path, so fallbacks and the data-use and residency checks from the other changes apply too.

## D3. A citation must point at something that was read

The ledger stores, per source, the text `webFetch` returned. A citation `[n: "passage"]` passes when source `n` exists and the passage occurs in its text after normalising whitespace, case and quotation marks. A failing citation is removed and its sentence gets "(unverified)". The report ends with a sources list: number, title, URL, fetch time. The run records how many citations passed and failed.

Rejected: trusting the model's citations. A model that invents a plausible URL is the failure a research report exists to avoid.

## D4. Caps and control

Defaults per run, set per instance and lowered per agent: 40 tool calls, 20 fetched sources, 20 minutes, 200,000 tokens. Before each model call the runner checks `BudgetService::isBlocked()` and the organisation's kill switch; either stops the run with a clear reason. The token cap counts the usage every provider reports once `models-several-models-per-turn` has landed. When a cap is reached the runner skips to the write phase with what it has and says so in the report's first line: "This report stopped early: the source limit of 20 was reached."

"Stop research" in the chat ends the run at the next step and writes nothing.

## D5. The report in Files

The report is written as the requesting user into `Hermiq/Onderzoek/<yyyy-mm-dd> <title>.md` in their own Files, and marked with the "Agent authored" tag in the same operation; a failed mark deletes the file and fails the run, as `NotesWriteService` does. The conversation gets a message with a five-line summary, the counts ("14 sources, 31 citations checked, 2 unverified") and a link to the file.

## D6. Starting a research run

- In the chat, a "Deep research" switch next to the send button, shown when the agent holds `hermiq.webSearch` and `hermiq.webFetch`.
- As a tool, `hermiq.startResearch` `{question, depth}` with `depth` `quick` (half the caps) or `thorough` (the caps), for agents and schedules. Descriptor: reach `user` (it writes into the acting user's Files), `scope: create`, `destructiveHint: false`, so it is default-denied like any write tool. The research itself reaches outside only through `webSearch` and `webFetch`, which carry their own reach.

## Declarative versus imperative

The run state lives on the `Handoff` object, which is declared in the register by `flows-hand-off-and-wait`; this change adds its `research` fields there (`plan`, `ledger`, `caps`, `progress`). The phases, the check and the file write are runtime work.

## Seed data

- An agent template "Onderzoeksassistent" (not installed by default): tools `hermiq.webSearch`, `hermiq.webFetch`, `hermiq.startResearch`, prompt "Schrijf zakelijk en in het Nederlands. Noem bij elke bewering de bron."
- A `Handoff` example of kind `research`: question "Welke gemeenten gebruiken een algoritmeregister en wat publiceren ze?", five sub-questions, a ledger of three sources, `status: done`.

## Risks

- Pages that instruct the agent. Mitigation: every page arrives through `webFetch`'s untrusted delimiter and the guardrail filters, and the runner only offers the tools the agent was granted.
- A long, costly run. Mitigation: four caps, the budget check before every model call, and the stop button.
- A report that looks authoritative but is thin. Mitigation: the counts of sources and unverified citations sit at the top of the conversation message and in the report.
