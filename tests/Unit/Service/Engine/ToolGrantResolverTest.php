<?php

/**
 * Unit tests for ToolGrantResolver (agent-tool-governance-and-disclosure,
 * hermiq-prefer-tool-hints).
 *
 * Covers the grant-expansion matrix: exact ids pass through verbatim, a schema
 * wildcard grants read verbs only (default-deny on writes), a `:write` modifier
 * additionally grants write verbs, an explicitly-named write verb is granted
 * alongside a read-only wildcard, an empty `Agent.tools` preserves legacy
 * "all discovered tools allowed" behaviour EXCEPT default-deny still strips
 * classifiable derived write ids — PLUS (hermiq-prefer-tool-hints) declared
 * descriptor hints (`scope`/`destructiveHint`/`readOnlyHint`) take precedence
 * over the verb-suffix heuristic, and a hint-less, non-3-segment id now FAILS
 * CLOSED (classified write/destructive) instead of silently passing as read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the schema-scoped grant expansion + default-deny resolver.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
 */
class ToolGrantResolverTest extends TestCase {
	/**
	 * A representative derived catalog for `pipelinq.lead` plus one hand-written
	 * (non-derived) tool, in the LLPhant-descriptor shape `listTools()` returns.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function catalog(): array {
		return [
			['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search', 'description' => 'Search leads'],
			['name' => 'pipelinq_lead_get', 'mcpId' => 'pipelinq.lead.get', 'description' => 'Get a lead'],
			['name' => 'pipelinq_lead_create', 'mcpId' => 'pipelinq.lead.create', 'description' => 'Create a lead'],
			['name' => 'pipelinq_lead_update', 'mcpId' => 'pipelinq.lead.update', 'description' => 'Update a lead'],
			['name' => 'pipelinq_lead_delete', 'mcpId' => 'pipelinq.lead.delete', 'description' => 'Delete a lead'],
			['name' => 'hermiq_sendMail', 'mcpId' => 'hermiq.sendMail', 'description' => 'Send an email'],
		];

	}//end catalog()

	/**
	 * A schema wildcard resolves to read verbs only — write/destructive verbs
	 * are excluded (default-deny).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-schema-wildcard-grants-read-verbs-only
	 */
	public function testWildcardGrantsReadVerbsOnly(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(grants: ['pipelinq.lead.*'], catalog: $this->catalog());

		sort($resolved);
		$this->assertSame(['pipelinq.lead.get', 'pipelinq.lead.search'], $resolved);

	}//end testWildcardGrantsReadVerbsOnly()

	/**
	 * A wildcard plus an explicitly-named write verb includes both the read
	 * verbs and the named write verb.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-granted-only-when-named-explicitly
	 */
	public function testExplicitWriteVerbGrantedAlongsideWildcard(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(
			grants: ['pipelinq.lead.*', 'pipelinq.lead.delete'],
			catalog: $this->catalog()
		);

		sort($resolved);
		$this->assertSame(['pipelinq.lead.delete', 'pipelinq.lead.get', 'pipelinq.lead.search'], $resolved);

	}//end testExplicitWriteVerbGrantedAlongsideWildcard()

	/**
	 * The `:write` modifier expands a wildcard to read AND write verbs.
	 *
	 * @return void
	 */
	public function testWriteModifierGrantsReadAndWriteVerbs(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(grants: ['pipelinq.lead.*:write'], catalog: $this->catalog());

		sort($resolved);
		$this->assertSame(
			['pipelinq.lead.create', 'pipelinq.lead.delete', 'pipelinq.lead.get', 'pipelinq.lead.search', 'pipelinq.lead.update'],
			$resolved
		);

	}//end testWriteModifierGrantsReadAndWriteVerbs()

	/**
	 * An exact-id grant (no wildcard) is passed through verbatim — including a
	 * write verb named explicitly, and a hand-written non-derived id.
	 *
	 * @return void
	 */
	public function testExactIdGrantsPassThroughVerbatim(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(
			grants: ['pipelinq.lead.create', 'hermiq.sendMail'],
			catalog: $this->catalog()
		);

		sort($resolved);
		$this->assertSame(['hermiq.sendMail', 'pipelinq.lead.create'], $resolved);

	}//end testExactIdGrantsPassThroughVerbatim()

	/**
	 * A wildcard for a schema with NO write verbs in the catalog resolves to
	 * only the read verbs actually present — never a fabricated id.
	 *
	 * @return void
	 */
	public function testWildcardOnlyIncludesCatalogPresentVerbs(): void {
		$resolver = new ToolGrantResolver();
		$catalog = [
			['name' => 'openregister_schemas_search', 'mcpId' => 'openregister.schemas.search'],
		];

		$resolved = $resolver->resolve(grants: ['openregister.schemas.*'], catalog: $catalog);

		$this->assertSame(['openregister.schemas.search'], $resolved);

	}//end testWildcardOnlyIncludesCatalogPresentVerbs()

	/**
	 * An empty `Agent.tools` preserves "all discovered tools allowed" for every
	 * derived read id, but strips classifiable derived write/destructive ids
	 * (default-deny still applies) — AND (hermiq-prefer-tool-hints) now also
	 * strips `hermiq.sendMail`, a hint-less 2-segment id, because it FAILS
	 * CLOSED rather than silently passing as read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
	 */
	public function testEmptyGrantsAllowsAllExceptDerivedWritesAndFailsClosedOnHintlessNonDerivedIds(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(grants: [], catalog: $this->catalog());

		sort($resolved);
		$this->assertSame(
			['pipelinq.lead.get', 'pipelinq.lead.search'],
			$resolved,
			'create/update/delete derived ids must be stripped; the hint-less non-derived hand-written id'
			. ' must ALSO be stripped now (fail closed on an unclassifiable id).'
		);

	}//end testEmptyGrantsAllowsAllExceptDerivedWritesAndFailsClosedOnHintlessNonDerivedIds()

	/**
	 * An empty `Agent.tools` resolution classifies each id from its OWN
	 * descriptor's annotations FIRST — a curated (2-segment) tool with
	 * `destructiveHint:true` is stripped even though its shape alone would be
	 * unclassifiable, and a curated tool with `readOnlyHint:true` and a low
	 * `reach` survives even though it would otherwise fail closed.
	 *
	 * 🔴 `pipelinq.createLead` carries NO `reach` and is stripped — but it was
	 * already stripped by its `destructiveHint`, so it proves nothing about the
	 * reach axis on its own. `pipelinq.getLeadSummary` is the row that carries
	 * the weight: it needs BOTH annotations to survive, and dropping either one
	 * fails this test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-hint-less-curated-tool-fails-closed-to-external
	 */
	public function testEmptyGrantsClassifiesCuratedToolsFromHints(): void {
		$resolver = new ToolGrantResolver();
		$catalog = [
			['name' => 'pipelinq_createLead', 'mcpId' => 'pipelinq.createLead', 'destructiveHint' => true],
			[
				'name' => 'pipelinq_getLeadSummary',
				'mcpId' => 'pipelinq.getLeadSummary',
				'readOnlyHint' => true,
				'reach' => 'user',
			],
		];

		$resolved = $resolver->resolve(grants: [], catalog: $catalog);

		$this->assertSame(
			['pipelinq.getLeadSummary'],
			$resolved,
			'destructiveHint:true must be stripped even though the id is a curated 2-segment id;'
			. ' readOnlyHint:true + reach:user must survive.'
		);

	}//end testEmptyGrantsClassifiesCuratedToolsFromHints()

	/**
	 * A declared `destructiveHint:true` on a 3-segment derived id overrides a
	 * read-shaped (`.get`) verb suffix — hints take precedence over the
	 * verb-suffix heuristic, not just fill a gap it leaves.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
	 */
	public function testHintOverridesConflictingVerbSuffix(): void {
		$resolver = new ToolGrantResolver();
		$catalog = [
			['name' => 'pipelinq_lead_get', 'mcpId' => 'pipelinq.lead.get', 'destructiveHint' => true],
		];

		$resolved = $resolver->resolve(grants: [], catalog: $catalog);

		$this->assertSame([], $resolved, 'destructiveHint:true must win over the ".get" verb suffix.');

	}//end testHintOverridesConflictingVerbSuffix()

	/**
	 * Hint-less, isWriteOrDestructive() classification: a 3-segment
	 * `{app}.{schema}.{verb}` id still uses the unchanged verb-suffix heuristic
	 * (regression parity) — but a 2-segment or bare id, which was previously
	 * NEVER classified this way, now FAILS CLOSED (classified write/destructive)
	 * per hermiq-prefer-tool-hints.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
	 */
	public function testIsWriteOrDestructiveHintlessClassification(): void {
		// 3-segment verb-suffix fallback — unchanged (regression).
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.delete'));
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.create'));
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.update'));
		$this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.search'));
		$this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.get'));

		// Hint-less, non-3-segment ids: fail CLOSED (was `false` before hermiq-prefer-tool-hints).
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'hermiq.sendMail'));
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'objects'));

	}//end testIsWriteOrDestructiveHintlessClassification()

	/**
	 * Declared descriptor hints take precedence over the id's own shape —
	 * `scope`, `destructiveHint`, and `readOnlyHint` are each checked, in that
	 * priority order, and can classify a 2-segment (otherwise-unclassifiable) id
	 * either way.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
	 */
	public function testIsWriteOrDestructiveHintClassification(): void {
		// scope
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.createLead', descriptor: ['scope' => 'create']));
		$this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.getLeadSummary', descriptor: ['scope' => 'read']));

		// destructiveHint
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.createLead', descriptor: ['destructiveHint' => true]));
		$this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.createLead', descriptor: ['destructiveHint' => false]));

		// readOnlyHint
		$this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.getLeadSummary', descriptor: ['readOnlyHint' => true]));
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.getLeadSummary', descriptor: ['readOnlyHint' => false]));

		// A descriptor present but carrying none of the three hint keys falls
		// through to the hint-less rules exactly as if no descriptor were given.
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'hermiq.sendMail', descriptor: ['description' => 'Send an email']));
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.create', descriptor: []));
		$this->assertFalse(ToolGrantResolver::isWriteOrDestructive(id: 'pipelinq.lead.get', descriptor: []));

	}//end testIsWriteOrDestructiveHintClassification()

	/**
	 * hasWildcardGrant() detects `.*`/`.*:write` entries and ignores exact ids.
	 *
	 * @return void
	 */
	public function testHasWildcardGrant(): void {
		$resolver = new ToolGrantResolver();

		$this->assertTrue($resolver->hasWildcardGrant(grants: ['pipelinq.lead.*']));
		$this->assertTrue($resolver->hasWildcardGrant(grants: ['pipelinq.lead.*:write']));
		$this->assertFalse($resolver->hasWildcardGrant(grants: ['pipelinq.lead.search', 'hermiq.sendMail']));
		$this->assertFalse($resolver->hasWildcardGrant(grants: []));

	}//end testHasWildcardGrant()

	/**
	 * Convert `HermiqToolProvider::getTools()` descriptors into the catalog shape
	 * `ToolRegistryFacade::listTools()` hands the resolver, mirroring exactly what
	 * `OCA\OpenRegister\Tool\McpProviderBridge::getFunctions()` does at runtime
	 * (or #373: dotted `id` becomes `mcpId`; `readOnlyHint`/`destructiveHint`/
	 * `idempotentHint`/`scope` are forwarded additively when the provider set
	 * them). This is the end-to-end proof that the hermiq-prefer-tool-hints
	 * regression (all 8 NC-native tools fail-closed stripped) is closed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function hermiqCatalog(): array {
		$provider = new HermiqToolProvider(
			$this->createMock(IUserSession::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IContactsManager::class),
			$this->createMock(ICalendarManager::class),
			$this->createMock(IMailer::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(CourseRecommendationEngine::class),
			$this->createMock(MemoryService::class),
			$this->createMock(WebSearchClient::class),
			$this->createMock(WebFetchService::class),
			$this->createMock(DelegationService::class),
			$this->createMock(NcNativeWriteService::class),
			$this->createMock(LoggerInterface::class)
		);

		$catalog = [];
		foreach ($provider->getTools() as $descriptor) {
			$entry = [
				'name' => str_replace('.', '_', $descriptor['id']),
				'mcpId' => $descriptor['id'],
			];

			// 🔴 `reach` is in this list because `McpProviderBridge` forwards it
			// (its `PASSTHROUGH_KEYS`). This fixture must mirror the bridge KEY
			// FOR KEY: it is the only place in Hermiq's suite that models the
			// cross-app boundary the real descriptors cross, and the axis fails
			// CLOSED, so a key missing here does not read as "unannotated" — it
			// reads as `external` and empties the resolved catalogue. That is
			// exactly how the gap was found: this fixture, written before the
			// bridge forwarded `reach`, correctly reported a live app-wide
			// outage rather than a fixture bug.
			foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'scope', 'reach'] as $hintKey) {
				if (array_key_exists($hintKey, $descriptor) === true) {
					$entry[$hintKey] = $descriptor[$hintKey];
				}
			}

			$catalog[] = $entry;
		}

		return $catalog;
	}//end hermiqCatalog()

	/**
	 * Regression proof (hermiq-prefer-tool-hints): with the hints this change
	 * added, an empty `Agent.tools` grant ("all discovered tools allowed",
	 * default-deny still applies) now GRANTS every read-annotated NC-native tool
	 * — before the fix, ALL of these were fail-closed stripped because they are
	 * hint-less 2-segment ids. `sendMail` and `recommendCourses` remain stripped
	 * because they are honestly annotated as write/destructive. `hermiq.recallMemory`
	 * (agent-memory-tools, scope:read) is granted alongside them; `rememberMemory`
	 * (scope:create) and `forgetMemory` (scope:delete) stay stripped like every
	 * other write/destructive-annotated tool.
	 *
	 * 🔴 `webSearch` and `webFetch` were on this list and are deliberately no
	 * longer (agent-capability-reach). Both declare `scope: read` and
	 * `readOnlyHint: true` — honestly, they read — and both send something out
	 * of the instance: a query the model composed, or a URL the model chose.
	 * The CRUD axis has no way to say that, which is the entire argument for
	 * the reach axis, and this list is the argument's receipt. They remain
	 * available; they now have to be named.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-an-egress-read-tool-becomes-gated
	 */
	public function testHermiqNativeToolsResolveViaDeclaredHintsNotFailClosed(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(grants: [], catalog: $this->hermiqCatalog());

		sort($resolved);
		$this->assertSame(
			[
				'hermiq.listCalendarEvents',
				'hermiq.listDeckBoards',
				'hermiq.listFiles',
				// nc-native-write-tools added five tools; only this one survives an
				// empty grant. The other four (createCalendarEvent, upsertContact,
				// createNote, updateNote) are write-classified and therefore
				// default-denied — this list is the receipt for that.
				'hermiq.listNotes',
				'hermiq.readFile',
				'hermiq.recallMemory',
				'hermiq.searchContacts',
				'hermiq.searchTools',
			],
			$resolved,
			'Every readOnlyHint:true/scope:read NC-native tool must be granted by the default-allow'
			. ' resolution now that they declare hints; sendMail (destructive) and recommendCourses'
			. ' (scope:update, writes on staleness) must stay stripped.'
		);

		$this->assertNotContains('hermiq.sendMail', $resolved, 'sendMail is honestly destructive and must stay default-denied.');
		$this->assertNotContains('hermiq.recommendCourses', $resolved, 'recommendCourses persists on staleness and must stay default-denied.');
		$this->assertNotContains('hermiq.rememberMemory', $resolved, 'rememberMemory (scope:create) must stay default-denied.');
		$this->assertNotContains('hermiq.forgetMemory', $resolved, 'forgetMemory (scope:delete) must stay default-denied.');

		// 🔴 The positive control for the reach axis, stated as the property
		// rather than as two more absent ids: these two are stripped WHILE still
		// classifying read on the CRUD axis. If someone reverts the union in
		// `requiresGrant()`, the list assertion above fails — but so would a
		// dozen unrelated edits, and the failure would read as "list drifted".
		// This says why they are absent, so the failure names the cause.
		foreach (['hermiq.webSearch', 'hermiq.webFetch'] as $egressTool) {
			$this->assertNotContains(
				$egressTool,
				$resolved,
				$egressTool . ' declares scope:read and readOnlyHint:true, and still egresses. It must be '
				. 'stripped by REACH, not by the CRUD rule — if this fails, the reach clause of '
				. 'ToolGrantResolver::requiresGrant() has stopped composing.'
			);
			$this->assertFalse(
				ToolGrantResolver::isWriteOrDestructive(id: $egressTool, descriptor: $this->descriptorFor(id: $egressTool)),
				$egressTool . ' must still classify NON-write on the CRUD axis — if this flips, the two '
				. 'axes have been conflated and the test above no longer proves anything about reach.'
			);
		}

	}//end testHermiqNativeToolsResolveViaDeclaredHintsNotFailClosed()

	/**
	 * The catalogue descriptor for one hermiq tool id, as the bridge shapes it.
	 *
	 * @param string $id The dotted tool id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function descriptorFor(string $id): ?array {
		foreach ($this->hermiqCatalog() as $entry) {
			if (($entry['mcpId'] ?? null) === $id) {
				return $entry;
			}
		}

		return null;
	}//end descriptorFor()

	/**
	 * A read-wildcard grant for the hermiq "schema" (`hermiq.*`) expands to the
	 * READ_VERBS (`search`/`get`) suffix form only — NOT applicable to hermiq's
	 * hand-written 2-segment ids (`hermiq.listFiles` is not `hermiq.search` /
	 * `hermiq.get`). Exercising it here documents that the wildcard grammar is
	 * verb-suffix-shaped and hand-written tools must instead be reached via an
	 * exact grant or the legacy "all tools" empty-grant default-deny path
	 * (see testHermiqNativeToolsResolveViaDeclaredHintsNotFailClosed above) —
	 * i.e. this is NOT the mechanism the fix relies on.
	 *
	 * @return void
	 */
	public function testHermiqWildcardGrantDoesNotMatchHandWrittenIds(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(grants: ['hermiq.*'], catalog: $this->hermiqCatalog());

		$this->assertSame([], $resolved, 'The .* wildcard only expands {prefix}.search/{prefix}.get, which do not exist for hand-written ids.');

	}//end testHermiqWildcardGrantDoesNotMatchHandWrittenIds()

	/**
	 * Non-string / empty grant entries are dropped rather than fatal.
	 *
	 * @return void
	 */
	public function testNonStringGrantsAreIgnored(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(grants: [123, '', null, 'hermiq.sendMail'], catalog: $this->catalog());

		$this->assertSame(['hermiq.sendMail'], $resolved);

	}//end testNonStringGrantsAreIgnored()

	/**
	 * The `__none__` sentinel reads as a deliberate no-tools agent.
	 *
	 * @return void
	 */
	public function testExplicitNoToolsRecognisesTheSentinel(): void {
		$resolver = new ToolGrantResolver();

		$this->assertTrue($resolver->isExplicitNoTools(grants: [ToolGrantResolver::NO_TOOLS_SENTINEL]));

	}//end testExplicitNoToolsRecognisesTheSentinel()

	/**
	 * An EMPTY grant list is "all tools, default-denied" — the opposite of the
	 * sentinel, and must never be mistaken for a deliberate no-tools agent.
	 *
	 * @return void
	 */
	public function testEmptyGrantsAreNotExplicitNoTools(): void {
		$resolver = new ToolGrantResolver();

		$this->assertFalse($resolver->isExplicitNoTools(grants: []));

	}//end testEmptyGrantsAreNotExplicitNoTools()

	/**
	 * A real grant alongside the sentinel is NOT a deliberate no-tools agent —
	 * the agent asked for something.
	 *
	 * @return void
	 */
	public function testSentinelMixedWithARealGrantIsNotExplicitNoTools(): void {
		$resolver = new ToolGrantResolver();

		$this->assertFalse(
			$resolver->isExplicitNoTools(grants: [ToolGrantResolver::NO_TOOLS_SENTINEL, 'hermiq.sendMail'])
		);

	}//end testSentinelMixedWithARealGrantIsNotExplicitNoTools()

	/**
	 * Grants that named tools but produced none are reported as broken.
	 *
	 * @return void
	 */
	public function testResolvesToNothingFlagsConfiguredGrantsThatMatchedNothing(): void {
		$resolver = new ToolGrantResolver();

		$this->assertTrue($resolver->resolvesToNothing(grants: ['openregister.schemas'], resolvedTools: []));

	}//end testResolvesToNothingFlagsConfiguredGrantsThatMatchedNothing()

	/**
	 * The two legitimate empties — "all, default-denied" and "none, on purpose"
	 * — are not reported as broken.
	 *
	 * @return void
	 */
	public function testResolvesToNothingIgnoresTheLegitimateEmpties(): void {
		$resolver = new ToolGrantResolver();

		$this->assertFalse($resolver->resolvesToNothing(grants: [], resolvedTools: []));
		$this->assertFalse(
			$resolver->resolvesToNothing(grants: [ToolGrantResolver::NO_TOOLS_SENTINEL], resolvedTools: [])
		);

	}//end testResolvesToNothingIgnoresTheLegitimateEmpties()

	/**
	 * Grants that DID resolve are never reported as broken.
	 *
	 * @return void
	 */
	public function testResolvesToNothingIsFalseWhenToolsResolved(): void {
		$resolver = new ToolGrantResolver();

		$this->assertFalse(
			$resolver->resolvesToNothing(grants: ['hermiq.sendMail'], resolvedTools: [['name' => 'hermiq_sendMail']])
		);

	}//end testResolvesToNothingIsFalseWhenToolsResolved()

	/**
	 * 🔴 The split-order test, with the exact failure string spelled out.
	 *
	 * Splitting on `?` before stripping `#noapproval` yields a closed set whose
	 * last member is `b@example.com#noapproval` — a value no real argument can
	 * ever equal. The grant would not error; it would silently become
	 * unsatisfiable, so the owner who added a waiver to widen their agent's
	 * autonomy would have narrowed it to nothing instead, and the tool would
	 * fail with `grant_constraint_violated` on a value that is plainly in the
	 * list they wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-waiver-on-an-argument-scoped-grant-does-not-corrupt-the-constraint
	 */
	public function testAWaiverOnAnArgumentScopedGrantDoesNotCorruptTheConstraint(): void {
		$resolver = new ToolGrantResolver();
		$grants = ['hermiq.sendMail?to=in:a@example.com,b@example.com' . ToolGrantResolver::WAIVER_FRAGMENT];

		$constraints = $resolver->argumentConstraints(grants: $grants);

		$this->assertSame(
			['a@example.com', 'b@example.com'],
			$constraints['hermiq.sendMail'][0]['to']['values'],
			'The fragment must be stripped BEFORE the ? split, or the last set member absorbs it.'
		);

		foreach ($constraints['hermiq.sendMail'][0]['to']['values'] as $value) {
			$this->assertStringNotContainsString('noapproval', $value);
		}

		// And the base id is clean, so the grant still names a real tool.
		$this->assertSame(['hermiq.sendMail'], $resolver->baseToolIds(grants: $grants));

	}//end testAWaiverOnAnArgumentScopedGrantDoesNotCorruptTheConstraint()

	/**
	 * A waiver on a bare exact-id grant still resolves to the tool itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-waiver-on-a-bare-exact-id-grant-still-resolves-to-the-tool
	 */
	public function testAWaiverOnABareGrantStillResolvesToTheTool(): void {
		$resolver = new ToolGrantResolver();
		$resolved = $resolver->resolve(
			grants: ['hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT],
			catalog: [['name' => 'hermiq_sendMail', 'mcpId' => 'hermiq.sendMail']]
		);

		$this->assertSame(['hermiq.sendMail'], $resolved);
		foreach ($resolved as $id) {
			$this->assertStringNotContainsString('noapproval', $id);
		}

	}//end testAWaiverOnABareGrantStillResolvesToTheTool()

	/**
	 * 🔴 Every stored grant list must parse byte-for-byte as it did before.
	 *
	 * `Agent.tools` is persisted `string[]`, so a parser change is a change to
	 * the meaning of data already on disk. This drives the pre-existing grant
	 * FORMS through the post-change parser and pins the output, which is the
	 * only way to show the fragment support is additive rather than a migration
	 * nobody wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-an-existing-grant-list-parses-unchanged
	 */
	public function testAnExistingGrantListParsesUnchanged(): void {
		$resolver = new ToolGrantResolver();
		$grants = [
			'hermiq.sendMail',
			'pipelinq.lead.*',
			'pipelinq.lead.*:write',
			'openregister.runFlow?flowId=A&label=x',
			'openregister.runFlow?flowId=B',
			'hermiq.readFile?path=in:/a,/b',
			'hermiq.readFile',
			'hermiq.searchTools?',
		];

		$this->assertSame(
			[
				'hermiq.sendMail',
				'pipelinq.lead.*',
				'pipelinq.lead.*:write',
				'openregister.runFlow',
				'hermiq.readFile',
				'hermiq.searchTools',
			],
			$resolver->baseToolIds(grants: $grants)
		);

		$constraints = $resolver->argumentConstraints(grants: $grants);
		$this->assertSame(['openregister.runFlow', 'hermiq.readFile'], array_keys($constraints));
		$this->assertSame('A', $constraints['openregister.runFlow'][0]['flowId']['values'][0]);
		$this->assertSame('x', $constraints['openregister.runFlow'][0]['label']['values'][0]);
		// Two constrained entries for one tool stay SEPARATE alternatives, which
		// is what keeps (A,x) and (B) from merging into a wider grant.
		$this->assertSame('B', $constraints['openregister.runFlow'][1]['flowId']['values'][0]);
		$this->assertArrayNotHasKey('label', $constraints['openregister.runFlow'][1]);
		$this->assertSame(['/a', '/b'], $constraints['hermiq.readFile'][0]['path']['values']);
		$this->assertSame([], $constraints['hermiq.readFile'][1], 'A bare sibling grant contributes an empty set.');
		$this->assertTrue($resolver->hasWildcardGrant(grants: $grants));

		// Nothing in this list is waived — the whole point of "unchanged".
		$this->assertSame([], $resolver->waivedConstraintSets(grants: $grants));

	}//end testAnExistingGrantListParsesUnchanged()

	/**
	 * 🔴 A near-miss fragment is NOT a waiver.
	 *
	 * `#noapprovals`, `#noapproval-please` and a mid-string occurrence all stay
	 * part of the id. That id then matches no catalogue tool, so the grant
	 * quietly grants nothing — the safe direction. The dangerous reading would
	 * be to treat any `#noapproval`-ish text as intent and hand the model
	 * unattended use of the tool the owner fumbled the syntax for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-a-grant-may-carry-a-noapproval-waiver-fragment-parsed-before-any-other-grant-parsing
	 */
	public function testANearMissFragmentIsNotAWaiver(): void {
		$resolver = new ToolGrantResolver();

		foreach (['hermiq.sendMail#noapprovals', 'hermiq.sendMail#noapproval-please', 'hermiq.send#noapprovalMail'] as $grant) {
			$this->assertSame(
				[],
				$resolver->waivedConstraintSets(grants: [$grant]),
				$grant . ' must NOT be read as a waiver.'
			);
		}

		// Case matters too — the vocabulary is closed, not fuzzy.
		$this->assertSame([], $resolver->waivedConstraintSets(grants: ['hermiq.sendMail#NoApproval']));

	}//end testANearMissFragmentIsNotAWaiver()

	/**
	 * 🔴 A waiver is per ENTRY. One narrow waiver must not cover a sibling grant
	 * for the same tool.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-waiver-suppresses-the-approval-gate-and-nothing-else
	 */
	public function testAWaiverCoversOnlyItsOwnEntryNotEveryGrantForTheTool(): void {
		$resolver = new ToolGrantResolver();
		$waived = $resolver->waivedConstraintSets(
			grants: [
				'openregister.runFlow?flowId=A' . ToolGrantResolver::WAIVER_FRAGMENT,
				'openregister.runFlow?flowId=B',
			]
		);

		$this->assertTrue(
			ToolGrantResolver::waives($waived, 'openregister.runFlow', ['flowId' => 'A']),
			'The waived entry covers its own flow.'
		);
		$this->assertFalse(
			ToolGrantResolver::waives($waived, 'openregister.runFlow', ['flowId' => 'B']),
			'Flow B is granted and conforming, but it was never waived — it still meets a human.'
		);

	}//end testAWaiverCoversOnlyItsOwnEntryNotEveryGrantForTheTool()

	/**
	 * 🔴 The absent-tool guard: a tool with NO waiver must never come back waived.
	 *
	 * `violationFor()` returns null for an empty alternatives list, meaning
	 * "conforms". Read through `waives()` that same null would mean "waived", so
	 * without the guard EVERY tool would be waived and the fragment would be the
	 * default rather than an opt-in. This is the single most dangerous line in
	 * the waiver path, so it gets its own test.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-waiver-does-not-make-an-ungranted-tool-runnable
	 */
	public function testAnUnwaivedToolIsNeverReportedAsWaived(): void {
		$resolver = new ToolGrantResolver();
		$waived = $resolver->waivedConstraintSets(grants: ['hermiq.readFile' . ToolGrantResolver::WAIVER_FRAGMENT]);

		$this->assertFalse(
			ToolGrantResolver::waives($waived, 'hermiq.sendMail', []),
			'A tool no waiver names must not inherit one.'
		);
		$this->assertFalse(
			ToolGrantResolver::waives([], 'hermiq.sendMail', []),
			'An empty waiver map must waive nothing at all.'
		);

		// Positive control: the tool that WAS waived still is, so the two
		// assertions above are not passing because the whole path is inert.
		$this->assertTrue(ToolGrantResolver::waives($waived, 'hermiq.readFile', []));

	}//end testAnUnwaivedToolIsNeverReportedAsWaived()

	/**
	 * A waived WILDCARD is refused — it would cover ids added to the catalogue
	 * after the owner wrote the grant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-waiver-suppresses-the-approval-gate-and-nothing-else
	 */
	public function testAWaivedWildcardIsRefused(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			[],
			$resolver->waivedConstraintSets(grants: ['pipelinq.lead.*:write' . ToolGrantResolver::WAIVER_FRAGMENT])
		);

	}//end testAWaivedWildcardIsRefused()
}//end class
