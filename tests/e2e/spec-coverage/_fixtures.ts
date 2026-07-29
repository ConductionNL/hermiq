/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Seeded-fixture helpers for the hermiq spec-coverage e2e suite.
 *
 * Mirrors docudesk/tests/e2e/workflows/_fixtures.ts: data is seeded through
 * the OpenRegister objects API (hermiq's objects live in the `hermiq`
 * register), authenticated by the Playwright storageState session
 * (tests/e2e/global-setup.ts) plus the live CSRF `requesttoken` harvested
 * from a running app page — the same cookie+token pair the in-app axios
 * client sends, proven by wave2-surfaces.spec.ts's evaldataset cleanup.
 *
 * Register/schema existence is resolved (asserted) via
 * GET /index.php/apps/openregister/api/registers before any slug-form
 * object write, so a missing register fails loudly as "seed missing"
 * rather than as an opaque 404 mid-test.
 *
 * Every seeded entity carries the unique TEST_PREFIX so afterAll cleanup
 * can purge anything a crashing test left behind, and so concurrent runs
 * never collide.
 */

import { type APIRequestContext, type Page, expect } from '@playwright/test'

/** Shared family prefix for ALL spec-coverage artefacts (every run). */
export const TEST_FAMILY = 'e2espec-'

/** Unique-per-run prefix stamped on every seeded entity name. */
export const TEST_PREFIX = `${TEST_FAMILY}${Date.now()}`

/** OpenRegister objects API root (index.php-prefixed so it works with pretty-URLs off). */
export const OR_API = '/index.php/apps/openregister/api'

const NC_USER = process.env.NC_USER || 'admin'
const NC_PASS = process.env.NC_PASS || 'admin'

/**
 * Harvest the live CSRF request-token from a loaded hermiq page.
 *
 * OpenRegister's object write routes are session-cookie + CSRF protected;
 * `OC.requestToken` is the canonical token. Read it from the running app so
 * it always matches the storageState cookie jar Playwright restored.
 *
 * Logs in first when the session is not already authenticated (merged from
 * development): relying on globalSetup alone let specs run unauthenticated and
 * fail as "no data" rather than "not logged in".
 *
 * @param page A page in the authenticated context (will navigate to /apps/hermiq).
 * @return The request-token string.
 */
export async function harvestToken(page: Page): Promise<string> {
	await page.goto('/login', { waitUntil: 'domcontentloaded' })

	const userField = page.locator('#user')
	if (await userField.count() > 0) {
		await userField.fill(NC_USER)
		await page.locator('#password').fill(NC_PASS)
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		// Nextcloud holds persistent long-poll connections, so 'networkidle'
		// never fires; the login field detaching is the "logged in" signal.
		await page.locator('#user').waitFor({ state: 'hidden', timeout: 30_000 })
	}

	await page.goto('/apps/hermiq/', { waitUntil: 'domcontentloaded' })
	const token = await page.evaluate(
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		() => (window as any).OC?.requestToken
			|| document.head.querySelector('meta[name="requesttoken"]')?.getAttribute('content')
			|| '',
	)
	expect(token, 'CSRF request-token must be harvestable from the running app').not.toEqual('')
	return token
}

/**
 * Standard JSON headers carrying the CSRF token for a write request.
 *
 * The union of both branches' header sets: `Content-Type` for the seeding
 * helpers' JSON bodies, `OCS-APIRequest`/`Accept` for the OCS-style routes
 * development's spec calls.
 */
export function jsonHeaders(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'Content-Type': 'application/json',
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * Resolve the hermiq register via GET /api/registers and assert the given
 * schema slug exists on it. Returns the register + schema descriptors so a
 * caller could use numeric ids; the object helpers below use the slug path
 * form (`/api/objects/hermiq/<schema>`) that hermiq's own frontend uses.
 *
 * @param req        The Playwright request context (session-scoped).
 * @param token      The CSRF request-token.
 * @param schemaSlug The schema slug to assert (e.g. 'agent').
 * @return The matched register object and schema descriptor (if enumerable).
 */
export async function resolveRegisterSchema(
	req: APIRequestContext,
	token: string,
	schemaSlug: string,
): Promise<{ register: Record<string, unknown>, schema: Record<string, unknown> | null }> {
	const res = await req.get(`${OR_API}/registers`, { headers: jsonHeaders(token) })
	expect(res.ok(), `GET ${OR_API}/registers HTTP ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const registers = (Array.isArray(body) ? body : (body.results ?? body.data ?? [])) as Array<Record<string, unknown>>
	const register = registers.find((r) => r.slug === 'hermiq')
	expect(register, 'hermiq register must exist (install/repair the hermiq app first)').toBeTruthy()
	// Schemas may be embedded as objects or referenced by id depending on OR
	// version — only assert the slug when the embedded form is available.
	const schemas = (register?.schemas ?? []) as Array<Record<string, unknown> | number | string>
	const embedded = schemas.filter((s): s is Record<string, unknown> => typeof s === 'object' && s !== null)
	const schema = embedded.find((s) => s.slug === schemaSlug) ?? null
	if (embedded.length > 0) {
		expect(schema, `schema '${schemaSlug}' must exist on the hermiq register`).toBeTruthy()
	}
	return { register: register as Record<string, unknown>, schema }
}

/** The persisted seed shape returned by seedObject/seedAgent. */
export interface SeededObject {
	id: string
	name: string
}

/**
 * Create one object in the hermiq register via the slug path form and
 * return its persisted id (uuid) + name.
 *
 * @param req    The Playwright request context.
 * @param token  The CSRF request-token.
 * @param schema The schema slug (e.g. 'agent').
 * @param data   The object payload (must include a `name`).
 * @return The persisted object seed.
 */
export async function seedObject(
	req: APIRequestContext,
	token: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<SeededObject> {
	const res = await req.post(`${OR_API}/objects/hermiq/${schema}`, {
		headers: jsonHeaders(token),
		data,
	})
	const text = await res.text().catch(() => '')
	expect([200, 201], `create ${schema} HTTP ${res.status()} (body: ${text.slice(0, 300)})`).toContain(res.status())
	const body = JSON.parse(text)
	const id = String(body.id ?? body['@self']?.id ?? body.uuid ?? '')
	expect(id, `created ${schema} must carry a persisted id`).not.toEqual('')
	return { id, name: String(data.name ?? '') }
}

/**
 * Seed a minimal valid Agent (schema requires only `name` —
 * lib/Settings/hermiq_register.json components.schemas.Agent).
 *
 * @param req       The Playwright request context.
 * @param token     The CSRF request-token.
 * @param overrides Optional payload overrides.
 * @return The persisted agent seed.
 */
export async function seedAgent(
	req: APIRequestContext,
	token: string,
	overrides: Record<string, unknown> = {},
): Promise<SeededObject> {
	const name = String(overrides.name ?? `${TEST_PREFIX}-agent`)
	return seedObject(req, token, 'agent', {
		name,
		description: 'Seeded by tests/e2e/spec-coverage (safe to delete)',
		...overrides,
	})
}

/**
 * Delete one hermiq object by schema slug + id (best-effort).
 *
 * @param req    The Playwright request context.
 * @param token  The CSRF request-token.
 * @param schema The schema slug.
 * @param id     The object id/uuid.
 * @return The HTTP status (0 on transport failure).
 */
export async function deleteObject(req: APIRequestContext, token: string, schema: string, id: string): Promise<number> {
	const res = await req.delete(`${OR_API}/objects/hermiq/${schema}/${id}`, { headers: jsonHeaders(token) }).catch(() => null)
	return res ? res.status() : 0
}

/**
 * Purge every object of a schema whose name starts with the family prefix —
 * defensive afterAll cleanup that also collects orphans from crashed runs.
 *
 * @param req    The Playwright request context.
 * @param token  The CSRF request-token.
 * @param schema The schema slug to sweep.
 * @return Resolves once all family-prefixed objects are deleted.
 */
export async function cleanupFamily(req: APIRequestContext, token: string, schema: string): Promise<void> {
	const res = await req.get(`${OR_API}/objects/hermiq/${schema}?_limit=200`, { headers: jsonHeaders(token) }).catch(() => null)
	if (!res || !res.ok()) {
		return
	}
	const body = await res.json().catch(() => ({}))
	const list = (Array.isArray(body) ? body : (body.results ?? body.data ?? [])) as Array<Record<string, unknown>>
	for (const obj of list) {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const name = String(obj.name ?? (obj as any)['@self']?.name ?? '')
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const id = String(obj.id ?? (obj as any)['@self']?.id ?? '')
		if (name.startsWith(TEST_FAMILY) && id !== '') {
			// eslint-disable-next-line no-await-in-loop
			await deleteObject(req, token, schema, id)
		}
	}
}
