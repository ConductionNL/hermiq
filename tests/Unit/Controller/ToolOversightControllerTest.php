<?php

/**
 * Unit tests for ToolOversightController (agent-tool-governance-and-disclosure).
 *
 * Covers: the grant-annotated tool-catalog read (scope/destructiveHint/granted/
 * requiresExplicitGrant, disclosureActive), the owner-only tool-grants write
 * (single write-path via ObjectService::saveObject, refused for a non-owner),
 * and the tool-invocations oversight read (rich vs degraded source, tenant-
 * scoped correlation, empty state, CSV export) — including the access-denied/
 * not-found guards shared by all three endpoints.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use DateTime;
use OCA\Hermiq\Controller\ToolOversightController;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\Engine\ToolGrantSet;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the tool-catalog / tool-grants / tool-invocations endpoints.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-5
 */
class ToolOversightControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock ToolRegistryFacade.
	 *
	 * @var ToolRegistryFacade&MockObject
	 */
	private ToolRegistryFacade $toolRegistry;

	/**
	 * Mock AuditTrailMapper.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock user session (alice by default).
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock group manager (non-admin by default).
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->toolRegistry = $this->createMock(ToolRegistryFacade::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturn(30);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		// Non-admin by default; the owner/invited checks carry the existing tests.
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(false);

	}//end setUp()

	/**
	 * Build the controller, forcing `richAuditAvailable()` to a fixed value via
	 * an anonymous subclass — the only seam for a class-shape check that is not
	 * behind an injected collaborator (see the controller's docblock).
	 *
	 * @param bool $richAvailable The forced `richAuditAvailable()` return value.
	 *
	 * @return ToolOversightController
	 */
	private function controller(bool $richAvailable = false): ToolOversightController {
		// ⚠️ `$accessRequests` sits BEFORE the logger in the constructor, so it
		// goes here and not on the end — appending would put the logger in its
		// slot and the anonymous class's own `$richAvailable` in the logger's.
		return new class($this->request, $this->objectService, $this->toolRegistry, new ToolGrantResolver(), $this->auditTrailMapper, $this->appConfig, $this->userSession, $this->groupManager, $this->createMock(ToolAccessRequestService::class), $this->createMock(LoggerInterface::class), $richAvailable) extends ToolOversightController {
			/**
			 * @param bool $richAvailable Forced return value.
			 */
			public function __construct(
				IRequest $request,
				ObjectService $objectService,
				ToolRegistryFacade $toolRegistry,
				ToolGrantResolver $grantResolver,
				AuditTrailMapper $auditTrailMapper,
				IAppConfig $appConfig,
				IUserSession $userSession,
				IGroupManager $groupManager,
				ToolAccessRequestService $accessRequests,
				LoggerInterface $logger,
				private readonly bool $richAvailable,
			) {
				parent::__construct(
					$request,
					$objectService,
					$toolRegistry,
					$grantResolver,
					$auditTrailMapper,
					$appConfig,
					$userSession,
					$groupManager,
					$accessRequests,
					$logger
				);
			}//end __construct()

			protected function richAuditAvailable(): bool {
				return $this->richAvailable;
			}//end richAuditAvailable()
		};

	}//end controller()

	/**
	 * Build an agent ObjectEntity.
	 *
	 * @param array<string,mixed> $payload The agent payload.
	 * @param string $owner The owner UID.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $payload, string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end agent()

	/**
	 * Build an AuditTrail MCP-invocation stub row.
	 *
	 * @param string $user Acting user.
	 * @param string $toolId Full tool id.
	 * @param string $at ISO-ish created timestamp.
	 *
	 * @return AuditTrail
	 */
	private function mcpEntry(string $user, string $toolId, string $at): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUser($user);
		$entry->setToolId($toolId);
		$entry->setAction('mcp.' . substr($toolId, (strrpos($toolId, '.') + 1)));
		$entry->setParamsDigest('deadbeef');
		$entry->setResultSummary(['isError' => false, 'id' => 'obj-1']);
		$entry->setObjectUuid('obj-1');
		$entry->setCreated(new DateTime($at));
		return $entry;
	}//end mcpEntry()

	/**
	 * toolCatalog returns the derived catalog annotated with granted/
	 * requiresExplicitGrant per the resolved (grant-filtered, default-denied) set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/design.md#api-design
	 */
	public function testToolCatalogAnnotatesGrantedAndRequiresExplicitGrant(): void {
		$catalog = [
			['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search'],
			['name' => 'pipelinq_lead_delete', 'mcpId' => 'pipelinq.lead.delete'],
		];
		$this->toolRegistry->method('listTools')->willReturn($catalog);
		$this->objectService->method('find')->willReturn($this->agent(['tools' => ['pipelinq.lead.*']]));

		$response = $this->controller()->toolCatalog('agent-1');
		$data = $response->getData();

		$this->assertCount(2, $data['tools'], 'Both catalog tools must be listed, granted or not.');
		$this->assertSame(1, $data['resolvedCount'], 'Only the read verb is resolved (default-deny on delete).');
		$tools = [];
		foreach ($data['tools'] as $tool) {
			$tools[$tool['id']] = $tool;
		}

		$this->assertTrue($tools['pipelinq.lead.search']['granted']);
		$this->assertFalse($tools['pipelinq.lead.search']['destructiveHint']);
		$this->assertFalse($tools['pipelinq.lead.delete']['granted']);
		$this->assertTrue($tools['pipelinq.lead.delete']['destructiveHint']);
		$this->assertTrue($tools['pipelinq.lead.delete']['requiresExplicitGrant']);
		$this->assertFalse($data['disclosureActive']);

	}//end testToolCatalogAnnotatesGrantedAndRequiresExplicitGrant()

	/**
	 * toolCatalog refuses an agent the caller cannot view (private, non-owner,
	 * not invited) with 403 — never leaking the catalog.
	 *
	 * @return void
	 */
	public function testToolCatalogRefusesNonAccessibleAgent(): void {
		$this->objectService->method('find')->willReturn(
			$this->agent(['tools' => [], 'isPrivate' => true], 'carol')
		);

		$response = $this->controller()->toolCatalog('agent-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testToolCatalogRefusesNonAccessibleAgent()

	/**
	 * An instance admin may inspect the tool catalogue of a private agent they do
	 * not own — including a system-owned seeded agent (owner `__system__`) — via
	 * the oversight bypass. Without it, admins get a spurious 403 on the very
	 * agents they are meant to oversee (EU AI Act art.12/14).
	 *
	 * @return void
	 */
	public function testToolCatalogAllowsInstanceAdminOnSystemOwnedAgent(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->objectService->method('find')->willReturn(
			$this->agent(['tools' => [], 'isPrivate' => true], '__system__')
		);
		$this->toolRegistry->method('listTools')->willReturn([]);

		$response = $this->controller()->toolCatalog('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testToolCatalogAllowsInstanceAdminOnSystemOwnedAgent()

	/**
	 * toolCatalog 404s when the agent cannot be found.
	 *
	 * @return void
	 */
	public function testToolCatalogNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller()->toolCatalog('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testToolCatalogNotFound()

	/**
	 * A THROWING agent lookup is the same 404, not a 500.
	 *
	 * `ObjectService::find()` documents `@throws Exception If the object is not
	 * found`, and both `toolCatalog()` and `updateToolGrants()` call
	 * `loadAgentForOversight()` OUTSIDE their own try block — so before the fix
	 * the throw escaped to the dispatcher as a framework 500 with a stack trace
	 * on a `#[NoAdminRequired]` route.
	 *
	 * @return void
	 */
	public function testToolCatalogThrowingLookupIsNotFound(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('no such object'));

		$response = $this->controller()->toolCatalog('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testToolCatalogThrowingLookupIsNotFound()

	/**
	 * updateToolGrants persists the new grant array via ObjectService::saveObject
	 * (single write-path) for the owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/design.md#put-apiagentsagentidtool-grants
	 */
	public function testUpdateToolGrantsPersistsForOwner(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => []], 'alice'));
		$this->request->method('getParam')->with('grants')->willReturn(['pipelinq.lead.*', 'pipelinq.lead.delete']);

		$saved = [];
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) use (&$saved): ObjectEntity {
					$saved[] = $object;
					$entity = new ObjectEntity();
					$entity->setObject($object);
					return $entity;
				}
			);

		$response = $this->controller()->updateToolGrants('agent-1');

		// 🔑 PERSISTED AS A STRUCTURE, even though the caller sent the legacy
		// `string[]`. That is the point of the change: the shape converges on
		// write, so there is never a period in which some agents are one shape
		// and some the other, and no consumer has to split an id to learn which
		// app or verb a grant is for.
		$this->assertSame(
			['pipelinq' => ['lead' => ['*' => ['pipelinq.lead.*'], 'delete' => ['pipelinq.lead.delete']]]],
			$saved[0]['tools'],
			'a legacy list sent by a caller must be normalised to the structure before it is stored'
		);

		// And the same grants come back out, unchanged in meaning.
		$this->assertSame(
			['pipelinq.lead.*', 'pipelinq.lead.delete'],
			ToolGrantSet::fromStored($response->getData()['tools'])->toGrantStrings(),
			'the response must describe exactly the grants that were requested'
		);

	}//end testUpdateToolGrantsPersistsForOwner()

	/**
	 * updateToolGrants refuses a non-owner: 403, and Agent.tools is never
	 * touched (gate-7 no-admin-idor).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md
	 */
	public function testUpdateToolGrantsRefusesNonOwner(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => ['a.b.search']], 'carol'));
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->updateToolGrants('agent-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testUpdateToolGrantsRefusesNonOwner()

	/**
	 * 🔴 A non-owner may not persist a grant list carrying a waiver, and the
	 * refusal is the SAME refusal as for any other grant edit.
	 *
	 * This looks like a duplicate of the test above and is not. The one above
	 * proves the guard exists; this one proves the guard is reached on the path
	 * that matters most, because a waiver is the single most valuable thing an
	 * attacker could add to somebody else's agent — it removes the human who
	 * would otherwise notice. If a future refactor moved waiver handling ahead
	 * of the owner check (to "validate the syntax first", say), the test above
	 * would still pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-non-owner-is-refused-on-hermiqs-tool-grants-endpoint
	 */
	public function testUpdateToolGrantsRefusesANonOwnerAddingAWaiver(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => ['a.b.search']], 'carol'));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$this->request->method('getParam')->with('grants')
			->willReturn(['hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT]);

		$response = $this->controller()->updateToolGrants('agent-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testUpdateToolGrantsRefusesANonOwnerAddingAWaiver()

	/**
	 * 🔴 Adding a waiver writes a DISTINCT audit event naming what was added.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-adding-a-waiver-writes-a-distinct-audit-event
	 */
	public function testAddingAWaiverWritesADistinctAuditEvent(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => ['hermiq.sendMail']], 'alice'));
		$this->request->method('getParam')->with('grants')
			->willReturn(['hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT]);
		$this->objectService->method('saveObject')->willReturnCallback($this->savingEntity());

		$captured = [];
		$this->auditTrailMapper->expects($this->once())->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $object, string $action, array $context) use (&$captured) {
					$captured = ['action' => $action, 'context' => $context];
					return new AuditTrail();
				}
			);

		$this->controller()->updateToolGrants('agent-1');

		$this->assertSame(ToolOversightController::WAIVER_AUDIT_ACTION, $captured['action']);
		$this->assertSame(['hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT], $captured['context']['added']);
		$this->assertSame([], $captured['context']['removed']);
		$this->assertSame('alice', $captured['context']['actor']);

	}//end testAddingAWaiverWritesADistinctAuditEvent()

	/**
	 * 🔴 Removing a waiver is audited too.
	 *
	 * Re-enabling approval is the SAFE direction, so it is tempting to log only
	 * the dangerous one. But a trail that never records the removal cannot show
	 * that a waiver was temporary, and "approval was off for two hours during
	 * the incident" is precisely what an auditor needs to establish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-removing-a-waiver-writes-a-distinct-audit-event
	 */
	public function testRemovingAWaiverWritesADistinctAuditEvent(): void {
		$this->objectService->method('find')->willReturn(
			$this->agent(['tools' => ['hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT]], 'alice')
		);
		$this->request->method('getParam')->with('grants')->willReturn(['hermiq.sendMail']);
		$this->objectService->method('saveObject')->willReturnCallback($this->savingEntity());

		$captured = [];
		$this->auditTrailMapper->expects($this->once())->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $object, string $action, array $context) use (&$captured) {
					$captured = ['action' => $action, 'context' => $context];
					return new AuditTrail();
				}
			);

		$this->controller()->updateToolGrants('agent-1');

		$this->assertSame(ToolOversightController::WAIVER_AUDIT_ACTION, $captured['action']);
		$this->assertSame(['hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT], $captured['context']['removed']);
		$this->assertSame([], $captured['context']['added']);

	}//end testRemovingAWaiverWritesADistinctAuditEvent()

	/**
	 * 🔴 THE CONTROL: an ordinary grant change writes NO waiver event.
	 *
	 * Without this, both tests above would pass on an implementation that fired
	 * the audit on every single grant update — which would make the event
	 * useless for the question it exists to answer, while looking thoroughly
	 * covered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-an-ordinary-grant-change-is-not-reported-as-a-waiver-event
	 */
	public function testAnOrdinaryGrantChangeWritesNoWaiverEvent(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => ['pipelinq.lead.search']], 'alice'));
		$this->request->method('getParam')->with('grants')
			->willReturn(['pipelinq.lead.search', 'pipelinq.lead.get']);
		$this->objectService->method('saveObject')->willReturnCallback($this->savingEntity());

		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$response = $this->controller()->updateToolGrants('agent-1');

		$this->assertSame(200, $response->getStatus(), 'The grant change itself must still succeed.');

	}//end testAnOrdinaryGrantChangeWritesNoWaiverEvent()

	/**
	 * An unchanged waiver set is not an event either — re-saving the same list
	 * must not manufacture a trail of edits nobody made.
	 *
	 * @return void
	 */
	public function testAnUnchangedWaiverSetWritesNoEvent(): void {
		$grants = ['hermiq.sendMail' . ToolGrantResolver::WAIVER_FRAGMENT, 'pipelinq.lead.search'];

		$this->objectService->method('find')->willReturn($this->agent(['tools' => $grants], 'alice'));
		$this->request->method('getParam')->with('grants')->willReturn(array_reverse($grants));
		$this->objectService->method('saveObject')->willReturnCallback($this->savingEntity());

		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$this->controller()->updateToolGrants('agent-1');

	}//end testAnUnchangedWaiverSetWritesNoEvent()

	/**
	 * 🔴 The write payload carries no `@self`, no nulls and no empty objects.
	 *
	 * All three make OpenRegister's schema resolver fail with
	 * `$ref must be a non-empty string` — a 500 for the OWNER on a perfectly
	 * valid request. It only reproduces on an agent whose optional object
	 * fields were never populated, i.e. a freshly created one, which is why the
	 * endpoint looked healthy on a long-lived dev instance for months and broke
	 * the moment an e2e ran it on a clean install.
	 *
	 * `tools` is asserted separately BECAUSE it is exempt from the sweep: an
	 * intentional empty grant list must still be written, and a naive
	 * "strip everything empty" would silently drop the one field this endpoint
	 * exists to set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-only-the-agent-owner-may-persist-a-grant-list-carrying-a-waiver
	 */
	public function testWritePayloadOmitsMetadataNullsAndEmptyObjects(): void {
		$stored = [
			'name' => 'a',
			'tools' => ['x.y.search'],
			'@self' => ['id' => 'agent-1', 'owner' => 'alice'],
			'configuration' => [],
			'prompt' => null,
			'model' => 'gpt',
		];

		$this->objectService->method('find')->willReturn($this->agent($stored, 'alice'));
		$this->request->method('getParam')->with('grants')->willReturn([]);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('agent-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller()->updateToolGrants('agent-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('@self', $saved, 'OR metadata must never be written back.');
		$this->assertArrayNotHasKey('configuration', $saved, 'An empty object must be OMITTED, not sent as {}.');
		$this->assertArrayNotHasKey('prompt', $saved, 'A null must be OMITTED, not sent as null.');
		$this->assertSame('gpt', $saved['model'] ?? null, 'A populated field must still be carried forward.');
		$this->assertSame([], $saved['tools'] ?? null, 'An intentional EMPTY grant list must survive the sweep.');

	}//end testWritePayloadOmitsMetadataNullsAndEmptyObjects()

	/**
	 * A `saveObject` stub returning an entity carrying the written payload.
	 *
	 * @return callable
	 */
	private function savingEntity(): callable {
		return static function (array $object): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid('agent-1');
			$entity->setObject($object);
			return $entity;
		};

	}//end savingEntity()

	/**
	 * toolInvocations (rich source) lists this agent's correlated-owner MCP
	 * invocation rows, newest first, and NEVER a row belonging to a different
	 * agent/tenant's owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-an-operator-reviews-an-agents-tool-activity
	 */
	public function testToolInvocationsRichSourceTenantScopedNewestFirst(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => []], 'alice'));
		$this->objectService->method('findAll')->willReturn([]);

		$older = $this->mcpEntry('alice', 'pipelinq.lead.search', '2026-01-01T00:00:00+00:00');
		$newer = $this->mcpEntry('alice', 'pipelinq.lead.create', '2026-06-01T00:00:00+00:00');
		$other = $this->mcpEntry('mallory', 'pipelinq.lead.delete', '2026-06-02T00:00:00+00:00');
		$this->auditTrailMapper->method('findAll')->willReturn([$older, $newer, $other]);

		$response = $this->controller(richAvailable: true)->toolInvocations('agent-1');
		$data = $response->getData();

		$this->assertTrue($data['available']);
		$this->assertSame('or-mcp-invocation-audit', $data['source']);
		$this->assertCount(2, $data['rows'], 'Only the tenant-scoped (alice-owned) rows must appear.');
		$this->assertSame('pipelinq.lead.create', $data['rows'][0]['toolId'], 'Newest first.');
		$this->assertSame('pipelinq.lead.search', $data['rows'][1]['toolId']);

	}//end testToolInvocationsRichSourceTenantScopedNewestFirst()

	/**
	 * toolInvocations degrades gracefully when the richer shape is absent —
	 * never errors, never fabricates, and indicates the reduced detail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
	 */
	public function testToolInvocationsDegradesGracefullyWhenRichShapeAbsent(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => []], 'alice'));
		$this->objectService->method('findAll')->willReturn([]);
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$response = $this->controller(richAvailable: false)->toolInvocations('agent-1');
		$data = $response->getData();

		$this->assertFalse($data['available']);
		$this->assertSame('run-audit-log', $data['source']);
		$this->assertSame([], $data['rows']);

	}//end testToolInvocationsDegradesGracefullyWhenRichShapeAbsent()

	/**
	 * An agent with no recorded invocations renders an empty row list — never a
	 * fabricated row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-an-agent-has-no-recorded-invocations
	 */
	public function testToolInvocationsEmptyState(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => []], 'alice'));
		$this->objectService->method('findAll')->willReturn([]);
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$response = $this->controller(richAvailable: true)->toolInvocations('agent-1');

		$this->assertSame([], $response->getData()['rows']);

	}//end testToolInvocationsEmptyState()

	/**
	 * `format=csv` returns a downloadable CSV response instead of JSON.
	 *
	 * @return void
	 */
	public function testToolInvocationsCsvExport(): void {
		$this->objectService->method('find')->willReturn($this->agent(['tools' => []], 'alice'));
		$this->objectService->method('findAll')->willReturn([]);
		$this->auditTrailMapper->method('findAll')->willReturn(
			[$this->mcpEntry('alice', 'pipelinq.lead.search', '2026-06-01T00:00:00+00:00')]
		);

		$response = $this->controller(richAvailable: true)->toolInvocations('agent-1', format: 'csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);

	}//end testToolInvocationsCsvExport()

	/**
	 * toolInvocations 404s when the agent cannot be found.
	 *
	 * @return void
	 */
	public function testToolInvocationsNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller()->toolInvocations('missing');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testToolInvocationsNotFound()
}//end class
