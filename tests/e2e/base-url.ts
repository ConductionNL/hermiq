/*
 * SPDX-FileCopyrightText: 2026 Hermiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Single source of truth for the Nextcloud instance the e2e suite targets.
 *
 * WHY THIS EXISTS
 * ---------------
 * hermiq resolved its target in six places, each with its own
 * `|| 'http://localhost:8080'`. Two of those were playwright configs, so the
 * one an operator read was not always the one the run used. That is enough on
 * its own to want a single module, but the reason it became urgent is that
 * `http://localhost:8080` is the SHARED development instance: it bind-mounts
 * the host checkouts under `apps-extra/` and holds data colleagues are working
 * on, and this suite seeds and deletes OpenRegister objects.
 *
 * What this module does NOT do is change which instance an unset environment
 * picks. The default is still `http://localhost:8080`, exactly as before.
 * What changes is that landing there now has to be said out loud, through the
 * opt-in in `shared-instance.ts`.
 *
 * The accepted names are all four the fleet uses. The shared quality workflow
 * exports the target as `BASE_URL`, `NEXTCLOUD_URL` and `NC_BASE_URL`, not as
 * `PLAYWRIGHT_BASE_URL`; a resolver that accepts only the last one hard-fails
 * every CI run, which is what happened to openconnector.
 */

import { assertInstancePermitted } from './shared-instance.ts'

/** Environment variable names accepted as the target, in priority order. */
const CANDIDATES = [
	'PLAYWRIGHT_BASE_URL',
	'NEXTCLOUD_URL',
	'NC_BASE_URL',
	'BASE_URL',
] as const

/**
 * The target when nothing in the environment names one.
 *
 * Unchanged from what every call site used before this module existed. It is a
 * shared origin, so an unset environment now fails loudly in
 * `assertInstancePermitted` rather than quietly editing someone else's box.
 */
const DEFAULT_BASE_URL = 'http://localhost:8080'

/**
 * Resolve the base URL of the Nextcloud under test.
 *
 * @throws When the target is the shared development instance and no opt-in
 *         flag names it. See tests/e2e/shared-instance.ts.
 * @return The base URL, without a trailing slash.
 */
export function resolveBaseUrl(): string {
	for (const name of CANDIDATES) {
		const value = process.env[name]
		if (value && value.trim() !== '') {
			return assertInstancePermitted(value.trim().replace(/\/+$/, ''))
		}
	}

	return assertInstancePermitted(DEFAULT_BASE_URL)
}

/** The resolved base URL, evaluated once per process. */
export const BASE_URL = resolveBaseUrl()
