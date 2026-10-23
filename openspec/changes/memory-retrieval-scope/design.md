# Design: memory-retrieval-scope

Kind: code. Size M. `ContextRetrievalHandler`, `ResponseGenerationHandler`, the tool resolution for one mode, a few optional `Agent` properties, one admin setting and the agent form. The schema properties are incidental to the code, so the change is `kind: code` under hydra ADR-032.

## Context at development db6b74dc

- `lib/Service/Engine/ContextRetrievalHandler.php:101-140` settings (`ragSearchMode`, `ragNumSources`, `searchFiles`, `searchObjects`, `views`) and view resolution; `:164-230` the source loop with `similarity`; `:334-350` `searchScoped()`.
- `lib/Service/Engine/ResponseGenerationHandler.php:181` `generateResponse()`; `:354-358` the grounding sentence, added only when context text exists.
- `lib/Service/Engine/Engine.php:262` `processMessage()`, where retrieval runs before the model call.
- `lib/Settings/hermiq_register.json:2431` `Agent` (`enableRag`, `ragNumSources`, `searchFiles`, `searchObjects`, `views`, `tools`).
- `src/modals/AgentFormModal.vue:220-241` RAG settings; `src/views/Chat.vue:848-867` per-session defaults; `src/modals/ChatSettingsModal.vue` per-session overrides.
- Nextcloud server at 175c7e6be47 (read only): `lib/public/SystemTag/ISystemTagManager.php:133` `canUserAssignTag()`, `:176` `setTagGroups()`, `:187` `getTagGroups()`; `lib/public/Files/Search/ISearchComparison.php:20-60` comparisons `eq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`.
- `memory-knowledge-bases`, requirement REQ-KB-005: the vector facade's `fileIds` scope.

## D1. Filters resolve to a file set before the vector search

`Agent` gains `ragFileFilters`: `{ owners: [{ type, id }], namePattern, modifiedAfter, modifiedBefore }`, each optional.

At retrieval time `FileScopeResolver` runs one Nextcloud file search as the person in the session, with an `ISearchQuery` built from the filters (`owner` in the listed users or group members, `name` like the pattern, `mtime` between the dates), and collects the file ids. That set becomes the `fileIds` scope of the facade call, intersected with any knowledge base scope. An empty set means no file search, not an unscoped one.

Rejected: filtering the facade's results afterwards. The facade returns the top passages; filtering after the fact throws away most of them and can leave none although matching files exist.

Rejected: asking OpenRegister to store owner, name and date on every chunk and filter there. It duplicates what Files already knows and goes stale when a file is renamed or moved.

## D2. Validated is a restricted Nextcloud tag

An administrator picks one system tag in hermiq's admin settings as the validated tag, stored as `IAppConfig` `validated_tag_id`, and hermiq offers to create "Validated" (`Gevalideerd` in Dutch) as a restricted tag. Who may assign it is Nextcloud's tag setting (`setTagGroups()`), so the organisation decides in one place, for example "only Informatiebeheer". People see the tag on the file in Files.

Rejected: a validated flag stored by hermiq. It would be a second truth next to the file, invisible in Files, and hermiq would have to build its own permission for who may set it.

## D3. The mode, enforced before the model is asked

`Agent` gains `answerOnlyFromValidated` (boolean, default false), `ragMinSimilarity` (number, default 0.5) and `abstentionMessage` (text). When the mode is on, the engine, not the prompt:

1. limits file retrieval to files carrying the validated tag, by adding the tag to the file search of D1;
2. switches object search off and ignores any per-session override that would switch it on;
3. resolves no tools for the turn, so the model cannot fetch anything else;
4. keeps only passages with a similarity at or above `ragMinSimilarity`;
5. when no passage is left, stores the user turn and an assistant turn with `abstentionMessage`, and does not call the provider.

The default abstention reads: "I cannot answer this from the validated documents. Contact the Klantcontactcentrum for help." An owner can edit it.

When passages are left, the model is called with them and the grounding sentence, as today. The enforced part is what the model sees and when it is not asked.

Rejected: an instruction in the prompt, "answer only from the sources". That is what exists today, and the tender row is partial because a prompt is a request, not a limit.

## D4. The chat cannot widen the scope

The per-session RAG settings from `ChatSettingsModal` narrow and never widen. The server intersects them with the agent's scope: a session cannot switch on object search or add a view for an agent in the validated mode, and cannot remove the agent's file filters.

## D5. The answer says why

The assistant turn records `retrievalScope`: the filters applied, whether the validated mode was on, how many passages passed the floor, and `abstained: true` when hermiq answered without the model. The Chat page shows under an abstention: "No validated document matched this question. The assistant did not answer from other knowledge."

## Declarative versus imperative

`Agent.ragFileFilters`, `answerOnlyFromValidated`, `ragMinSimilarity`, `abstentionMessage` and `SessionTurn.retrievalScope` are declared in `lib/Settings/hermiq_register.json` with a register version bump. Resolving filters, checking tags and deciding to abstain are request-time decisions inside the engine, which hydra ADR-031 leaves imperative.

## Seed data

The demo agent "Informatiepunt Veiligheidsregio" gets `answerOnlyFromValidated: true`, `ragMinSimilarity: 0.55`, `ragFileFilters: { "owners": [{ "type": "group", "id": "informatiebeheer" }], "modifiedAfter": "2025-01-01" }` and `abstentionMessage` "Dit kan ik niet beantwoorden op basis van de gevalideerde documenten. Neem contact op met het Klantcontactcentrum." Demo data only, behind the dev-mode flag (hydra ADR-069, decision 5).

## Risks

- A floor set too high abstains on good questions. Mitigation: the per-turn record shows the best similarity that was found, so the owner can tune the floor from real turns.
- The file search is slow on a large instance. Mitigation: it runs once per turn with the owner and date comparisons indexed by the file cache, and the result is cached for the session for five minutes.
- A file loses its tag after an answer was given. The record of that answer still names the file; the next turn no longer finds it.
