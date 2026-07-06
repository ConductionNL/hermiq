import { createObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'

/**
 * Create the canonical OpenRegister object store for the 'example' schema.
 *
 * `createObjectStore` from @conduction/nextcloud-vue handles CSRF headers,
 * pagination, single-flight de-duplication, and consistent error surfacing.
 * Replace 'hermiq' / 'example' with your app's register and schema slug.
 *
 * @spec openspec/specs/frontend-data-stores/spec.md#REQ-STORE-001
 */
export const useObjectStore = createObjectStore('example', {
	register: 'hermiq',
	schema: 'example',
})

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
 * every existing hermiq schema object (schedule, example) uses that default —
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
 * Boot helper: prime settings store on app startup.
 *
 * @spec openspec/specs/frontend-data-stores/spec.md#REQ-STORE-005
 * @return {Promise<{settingsStore: object, objectStore: object}>} Store handles.
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export { useSettingsStore }
