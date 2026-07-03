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
