import { createObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'

/**
 * Canonical OpenRegister object store for the 'schedule' schema (hermiq register).
 *
 * Schedules are generic OR objects, so agent-management-ui persists them
 * through this createObjectStore — no bespoke Pinia store, no bespoke CRUD
 * backend (ADR-001, ADR-022).
 *
 * @spec openspec/changes/agent-management-ui/tasks.md#task-2-1
 */
export const useScheduleStore = createObjectStore('schedule', {
	register: 'hermiq',
	schema: 'schedule',
})

/**
 * Canonical OpenRegister object store for the 'agent' schema (hermiq register).
 *
 * Since agent-engine-schemas, an Agent is a plain OR object in the hermiq
 * register — the historical rationale for the src/api/agents.js
 * createObjectStore bypass ("agents are a first-class OR resource at
 * /apps/openregister/api/agents") no longer holds, so agent CRUD moves onto
 * this store (agent-engine-port task 5.2), closing the project's standing
 * store-pattern exception for that file.
 *
 * GROUND-TRUTH ADAPTATION (pre-approved): the change's design.md names
 * `/apps/hermiq/api/objects/hermiq/agent` as the store path, but
 * createObjectStore's default baseUrl is `/apps/openregister/api/objects` and
 * every existing hermiq schema object (schedule) uses that default —
 * "same as every other Hermiq schema object" wins. No hermiq-side objects
 * proxy is added (it would trip gate-17 redundant-controller).
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-5-2
 */
export const useAgentStore = createObjectStore('agent', {
	register: 'hermiq',
	schema: 'agent',
})

/**
 * Canonical OpenRegister object store for the 'evaldataset' schema (agent-evals).
 * Dataset CRUD goes through the generic object path exactly like schedule/agent;
 * only the "run this dataset against an agent" ACTION is a bespoke Hermiq endpoint
 * (src/api/evals.js), because OpenRegister exposes no agent-trigger.
 */
export const useEvalDatasetStore = createObjectStore('evaldataset', {
	register: 'hermiq',
	schema: 'evaldataset',
})

/**
 * Canonical OpenRegister object store for the 'evalrun' schema (agent-evals):
 * read-only in the UI (runs are written server-side by EvalRunService), listed to
 * show per-dataset pass-rate history.
 */
export const useEvalRunStore = createObjectStore('evalrun', {
	register: 'hermiq',
	schema: 'evalrun',
})

// There is deliberately NO `useAgentFlowStore` here.
//
// A flow definition is not an OpenRegister object. `flow-storage/spec.md` (of
// flow-engine-unification) states it outright — "A flow definition SHALL NOT be
// stored as an OpenRegister object […] No other app SHALL own a flow store, a
// flow controller, or a flow execution service" — and the engine reads the
// native `oc_openregister_flows` table, not the objects API.
//
// This store used to exist over a `hermiq/agentflow` schema, which made a
// SECOND copy of every flow: the objects the editor read, and the native rows
// the engine ran. Nothing kept them in step.
//
// The app no longer holds a flow client of its own either. The flow pages are
// the shared `index` and `flow` page types, which read OpenRegister's flow
// endpoints through nc-vue's `useFlowStore`, so what an author sees and what a
// trigger runs are the same record — and the rule quoted above is satisfied by
// the frontend as well as the backend. Hermiq extends the engine the supported
// way: `lib/Flow/*Node.php`, registered on `RegisterFlowNodesEvent`.

/**
 * Canonical OpenRegister object store for the 'agentskill' schema
 * (hermiq-skill-markdown-authoring). A Skill's CREATE goes through the
 * dedicated `SkillController`/`SkillService` import path (`src/api/skills.js`,
 * so `SkillSerializer::fromPackage` is the single source of truth for
 * splitting a package into `frontmatter`+`body`); this store is used ONLY for
 * EDIT — the generic OpenRegister object PUT the SkillsCatalog page's
 * (now-superseded) built-in dialog already issued, mirroring the `agent`/
 * `evaldataset` stores above (`SkillFormModal`, agent-form-slot parity).
 */
export const useSkillStore = createObjectStore('agentskill', {
	register: 'hermiq',
	schema: 'agentskill',
})

export { useSettingsStore }
