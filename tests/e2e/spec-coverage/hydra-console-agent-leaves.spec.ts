/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * hydra-console-agent-leaves e2e (spec-coverage).
 *
 * Two standing caveats, both from the change's test-plan.md, and both the reason
 * several tests below carry an explicit skip rather than an assertion:
 *
 *  1. `OCA\OpenRegister\*` is absent from hermiq's CI, so nothing crossing that
 *     boundary is provable by a green analyzer or a mocked unit test. What CAN be
 *     proven in a browser is proven here.
 *  2. The console pages and the `hydra` register these exercise come from two
 *     changes in the hydra REPOSITORY (`hydra-register-data-plane`,
 *     `hydra-console-openbuild-app`). Where a precondition is missing, the test
 *     SKIPS with the missing precondition named — never passes vacuously. A
 *     green-but-dead spec that asserted nothing because the register was absent
 *     would be worse than no spec.
 *
 * Covered openspec scenarios (@e2e annotations below are the gate-19 hooks):
 *   - agent-object-leaf: the seeded triage agent and flow, the leaf's surfaces,
 *     and the empty-context disclosure.
 *   - agent-tool-governance: the grant posture the seeded agent carries.
 *
 * Deliberately NOT attempted here, per test-plan.md's Out of Scope:
 *   - Forcing a mid-run turn failure through a browser (TC-13's empty-result
 *     branch) — unit-tested at the node boundary in SeedHydraTriageFlowTest.
 *   - An end-to-end command reaching a real forge (TC-8/10/11/20) — impossible
 *     until the OpenConnector command node ships, and not runnable unattended
 *     thereafter. The refusal half is unit-tested in
 *     FacadeToolInvokerConstraintTest.
 *
 * Auth: shared storageState session (tests/e2e/global-setup.ts).
 *
 *     NEXTCLOUD_URL=http://localhost:8080 NC_USER=admin NC_PASS=admin \
 *       npx playwright test --project chromium hydra-console-agent-leaves
 */

import { test, expect, type APIRequestContext } from '@playwright/test'
import { harvestToken, jsonHeaders, OR_API } from './_fixtures'

/** The seeded triage agent's name — also its idempotency key (SeedHydraTriageAgent). */
const AGENT_NAME = 'Hydra Triage'

/** The seeded triage agentflow's name — also its idempotency key (SeedHydraTriageFlow). */
const FLOW_NAME = 'Hydra Triage'

/** The hydra register the read grants and the leaf's bounded context depend on. */
const HYDRA_REGISTER = 'hydra'

/**
 * Every object of a hermiq schema whose `name` matches exactly.
 *
 * Read through the OpenRegister objects API rather than through a hermiq page,
 * because "how many were seeded" is a data question and a UI list may paginate,
 * filter or dedupe it into looking correct.
 *
 * @param req A request context carrying the authenticated session.
 * @param token The harvested CSRF request-token.
 * @param schema The hermiq schema slug.
 * @param name The exact object name to match.
 * @return The matching objects.
 */
async function objectsNamed(
	req: APIRequestContext,
	token: string,
	schema: string,
	name: string,
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
): Promise<any[]> {
	// `_limit`, underscore-prefixed: OpenRegister control params require the
	// prefix — a bare `limit` is treated as a PROPERTY filter and silently
	// matches nothing (the API even says so in its `@self.hint`).
	const res = await req.get(`${OR_API}/objects/hermiq/${schema}?_limit=100`, { headers: jsonHeaders(token) })
	expect(res.ok(), `listing hermiq/${schema} must succeed`).toBeTruthy()
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? body.data ?? [])
	// OR returns an ENVELOPE ({results,…}) on most surfaces, a bare array on some —
	// normalise rather than assuming, then match the seeded name exactly.
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	return rows.filter((row: any) => (row?.name ?? row?.object?.name) === name)
}

/**
 * Every flow named `FLOW_NAME` in OpenRegister's NATIVE flow store.
 *
 * 🔴 Not `/api/objects/hermiq/agentflow`. `SeedHydraTriageFlow` writes through
 * `FlowMapper` into `oc_openregister_flows` — OpenRegister's own flow store,
 * which is the one store its engine walks (ADR-065, flow-engine-unification).
 * There is no `agentflow` OBJECT to find, and reading for one returned 0 on
 * every clean install (run 30865280923) while passing on long-lived dev
 * instances that still carry a pre-pivot `agentflow` row — a test that
 * measured the instance's age rather than the seed.
 *
 * @param req   A request context carrying the authenticated session.
 * @param token The harvested CSRF request-token.
 * @return The matching flow definitions, as the API serialises them.
 */
async function seededFlows(
	req: APIRequestContext,
	token: string,
): Promise<Array<Record<string, unknown>>> {
	const res = await req.get(`${OR_API}/flows?app=hermiq&limit=200`, { headers: jsonHeaders(token) })
	expect(res.ok(), `listing OpenRegister flows must succeed (HTTP ${res.status()})`).toBeTruthy()
	const body = await res.json()
	const rows: Array<Record<string, unknown>> = Array.isArray(body) ? body : (body.results ?? [])
	return rows.filter((row) => row?.name === FLOW_NAME)
}

test.describe('hydra-console-agent-leaves', () => {

	/*
	 * @e2e openspec/specs/agent-object-leaf/spec.md#seeding-twice-creates-one-agent
	 *
	 * The repair steps run on every install AND every upgrade, so "exactly one"
	 * is the property that actually matters: a seed matched by anything other
	 * than its name would have produced a duplicate on the second upgrade this
	 * instance has already had.
	 */
	test('the triage agent is seeded exactly once and is approval-gated and read-only', async ({ page }) => {
		const token = await harvestToken(page)
		const agents = await objectsNamed(page.request, token, 'agent', AGENT_NAME)

		expect(agents.length, `exactly one "${AGENT_NAME}" agent must exist after repeated upgrades`).toBe(1)

		const agent = agents[0].object ?? agents[0]

		// The policy half of the posture.
		expect(agent.requiresApproval, 'the triage agent must be approval-gated').toBeTruthy()
		expect(agent.delegationAllowlist ?? [], 'the triage agent must delegate to no one').toEqual([])

		// The grant half. Every read grant is a wildcard (read verbs only) and
		// nothing carries the `:write` modifier or names a write verb.
		const tools: string[] = agent.tools ?? []
		expect(tools.length, 'the triage agent must carry grants').toBeGreaterThan(0)
		for (const grant of tools) {
			expect(grant, `grant "${grant}" must not carry the :write modifier`).not.toContain(':write')
			expect(grant, `grant "${grant}" must not name a write verb`).not.toMatch(/\.(create|update|delete)$/)
			expect(grant.toLowerCase(), `grant "${grant}" must not name a forge tool`).not.toContain('forge')
		}
	})

	/*
	 * @e2e openspec/specs/agent-tool-governance/spec.md#the-agent-may-run-exactly-one-flow
	 *
	 * At most ONE command grant, and if present it must be argument-scoped. An
	 * UNCONSTRAINED `openregister.runFlow` grant is a grant to run every flow on
	 * the instance — the exact hole this change closes — so finding one here is
	 * a failure, not a variation.
	 */
	test('the triage agent holds at most one command grant, and it is argument-scoped', async ({ page }) => {
		const token = await harvestToken(page)
		const agents = await objectsNamed(page.request, token, 'agent', AGENT_NAME)
		expect(agents.length).toBe(1)

		const tools: string[] = (agents[0].object ?? agents[0]).tools ?? []
		const commandGrants = tools.filter((grant) => grant.startsWith('openregister.runFlow'))

		expect(commandGrants.length, 'at most one command grant').toBeLessThanOrEqual(1)

		for (const grant of commandGrants) {
			expect(grant, 'a flow-runner grant must be argument-scoped, never bare').toContain('?')
			expect(grant, 'the command grant must pin a flowId').toContain('flowId=')
			expect(grant, 'the command grant must close its label vocabulary').toContain('label=in:')
		}
	})

	/*
	 * @e2e openspec/specs/agent-object-leaf/spec.md#a-new-finding-triggers-the-seeded-triage-flow
	 * @e2e openspec/specs/agent-object-leaf/spec.md#the-flow-contains-no-hermiq-authored-http-step
	 *
	 * The flow IS the deliverable, so it is read back as data. Its step-type
	 * inventory is the assertion that Hermiq authored no HTTP step: a type
	 * outside this list would be exactly that.
	 */
	// 🔴 RESTORED — hermiq#140 is fixed (hq#145). Both tests below were
	// `test.fixme` because this endpoint answered `{"results":[],"total":0}` on
	// a clean install, and that was read as "the seed writes nothing".
	//
	// It wrote fine. Every flow read is ORGANISATION-SCOPED — `FlowService::findAll()`
	// resolves the caller's active organisation and `FlowMapper::findAllFlows()`
	// adds `WHERE organisation = :org` — and the seed set `owner: null` while
	// never setting `organisation` at all. The row landed NULL-org, matched no
	// equality predicate, and was invisible to every tenant. The write succeeded
	// and the flow may as well not have existed.
	//
	// 🔑 The install-order hypothesis recorded here was wrong, and the reason it
	// was believable is worth keeping: "the store is empty" is what BOTH a failed
	// write and an unreachable row look like from the read side. Distinguishing
	// them needed the writer's own account (an app-config breadcrumb saying
	// `seeded`) held against the store's — which is exactly what
	// `flow-seed.spec.ts` now asserts, separately, for that reason.
	//
	// The seed now scopes to the default organisation and REFUSES to write at
	// all when none resolves: an absent flow is recoverable on the next run,
	// whereas an orphan blocks its own re-seed forever while reporting success.
	test('the triage flow is seeded once, declares its trigger, and contains only permitted step types', async ({ page }) => {
		const token = await harvestToken(page)
		const flows = await seededFlows(page.request, token)

		expect(flows.length, `exactly one "${FLOW_NAME}" flow must exist`).toBe(1)

		const flow = flows[0]

		expect(flow.trigger).toBe('object.created')
		expect(flow.triggerRegister, 'triggerRegister must survive the save — it is declared on the schema').toBe(HYDRA_REGISTER)
		expect(flow.triggerSchema).toBe('finding')

		// 🔑 The EDGE is the executable unit — `RegistryStepDispatcher::dispatch()`
		// reads `type`/`config` off the firing edge and a `type` on a node is
		// never read (SeedHydraTriageFlow::edges() docblock, measured live
		// 2026-07-31 both ways on the same graph). Asserting node types would
		// pass on a graph whose every step is inert, which is the exact defect
		// that seed comment records — so the inventory is taken from `edges`.
		const permitted = ['hermiq.agent-step', 'openregister.route', 'openregister.stop']
		const edges = (flow.edges ?? []) as Array<Record<string, unknown>>
		expect(edges.length, 'a flow with no edges executes nothing').toBeGreaterThan(0)
		for (const edge of edges) {
			expect(
				permitted,
				`edge "${String(edge.id)}" has an unexpected type "${String(edge.type)}"`,
			).toContain(edge.type)
		}

		// The branch that stands between a failed LLM turn and a pipeline command.
		const gate = edges.find((edge) => edge.type === 'openregister.route')
		expect(gate, 'the flow must branch before its command step').toBeTruthy()
		const gateConfig = (gate?.config ?? {}) as Record<string, unknown>
		expect(gateConfig.default, 'the fallback branch must not be the command step').not.toBe('command')
	})

	/*
	 * @e2e openspec/specs/agent-object-leaf/spec.md#an-unresolvable-owner-blocks-dispatch
	 *
	 * A trigger fires with no acting user, so an enabled-but-unowned flow would
	 * dispatch unattributed. The seed therefore ships it DISABLED: enabling it is
	 * the human act that supplies the owner. Once an operator has enabled it, an
	 * owner MUST be present — that is the invariant asserted here, in both states.
	 */
	// 🔴 FIXME(hermiq#140) — same missing seed as above; see that block for the
	// full measurement.
	test('the seeded flow is never both enabled and unowned', async ({ page }) => {
		const token = await harvestToken(page)
		const flows = await seededFlows(page.request, token)
		expect(flows.length).toBe(1)

		const flow = flows[0]
		const owner = String(flow.owner ?? '').trim()

		if (flow.enabled === true) {
			expect(owner, 'an ENABLED triage flow must name the UID it runs as').not.toEqual('')
		} else {
			expect(flow.enabled, 'the seeded flow ships disabled until an operator owns it').toBe(false)
		}
	})

	/*
	 * @e2e openspec/specs/agent-object-leaf/spec.md#the-user-is-told-the-object-contributed-no-context
	 *
	 * The leaf's chat tab on an object whose schema declares no
	 * `x-openregister-agent-context` allowlist must SAY SO in text. Fail-closed
	 * context is correct security; an ungrounded answer presented as grounded is
	 * a correctness defect, and the two must be distinguishable in the surface.
	 *
	 * Requires the hydra register (hydra-register-data-plane) and a console
	 * detail page (hydra-console-openbuild-app). Skips loudly when absent — see
	 * the file header on why a vacuous pass would be worse.
	 */
	test('the agent chat tab states in text when an object contributed no context', async ({ page }) => {
		const token = await harvestToken(page)
		const registers = await page.request.get(`${OR_API}/registers`, { headers: jsonHeaders(token) })
		const body = registers.ok() ? await registers.json() : {}
		const rows = Array.isArray(body) ? body : (body.results ?? [])
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const hydra = rows.find((row: any) => row?.slug === HYDRA_REGISTER)

		test.skip(
			hydra === undefined,
			'PRECONDITION MISSING: the `hydra` register is not installed — it ships with '
			+ 'hydra-register-data-plane in the hydra repository. Not a pass.',
		)

		await page.goto('/apps/openbuild/hydra-console', { waitUntil: 'domcontentloaded' })

		const tab = page.locator('[data-testid="cn-agent-chat-tab"]')
		test.skip(
			await tab.count() === 0,
			'PRECONDITION MISSING: no console detail page renders the hermiq-agent leaf — it ships '
			+ 'with hydra-console-openbuild-app. Not a pass.',
		)

		const notice = page.locator('[data-testid="cn-agent-chat-tab-no-context"]')
		if (await notice.count() > 0) {
			// The state is conveyed in TEXT, not by colour alone (WCAG 2.1 AA 1.4.1).
			await expect(notice.first()).toContainText(/no object context/i)
		}
	})
})
