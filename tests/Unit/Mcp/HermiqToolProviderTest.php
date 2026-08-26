<?php

/**
 * Unit tests for HermiqToolProvider (nc-native-tools, ai-course-recommendations,
 * hermiq-prefer-tool-hints).
 *
 * Covers the tool catalogue (six pre-existing + `recommendCourses`, namespaced
 * hermiq.* descriptors) and the never-throws contract: invokeTool returns a
 * structured error for an unauthenticated caller and for an unknown tool id, and
 * `recommendCourses` delegates to the shared `CourseRecommendationEngine` with the
 * acting user's own uid (no separate authorization path).
 *
 * Also covers the hermiq-prefer-tool-hints regression fix: every descriptor now
 * carries honest `readOnlyHint`/`destructiveHint`/`idempotentHint`/`scope` keys
 * so `ToolGrantResolver::isWriteOrDestructive()` classifies these hand-written,
 * 2-segment ids from their OWN declared hints instead of failing closed on their
 * (unclassifiable-by-shape) id text — see ToolGrantResolverTest for the
 * end-to-end grant-resolution proof.
 *
 * Also covers the three agent-memory-tools (`rememberMemory`/`recallMemory`/
 * `forgetMemory`): IDOR scoping to the acting user (never a caller-supplied
 * `subjectUid`), the `no_agent_context` error when `FacadeToolInvoker` has not
 * injected an `agentId` (agent-less chat), the not-found path, and the
 * never-throws contract.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-4-1
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the nc-native-tools HermiqToolProvider.
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 */
class HermiqToolProviderTest extends TestCase {

	/**
	 * Build the provider with a session that resolves to $uid (or null for anonymous).
	 *
	 * @param string|null $uid The acting user id, or null for unauthenticated.
	 * @param CourseRecommendationEngine|null $engine A specific engine double, or a plain mock.
	 * @param MemoryService|null $memoryService A specific MemoryService double, or a plain mock.
	 * @param WebSearchClient|null $webSearchClient A specific WebSearchClient double, or a plain mock.
	 * @param WebFetchService|null $webFetchService A specific WebFetchService double, or a plain mock.
	 * @param DelegationService|null $delegationService A specific DelegationService double, or a plain mock.
	 * @param NcNativeWriteService|null $writeService A specific NcNativeWriteService double, or a plain mock.
	 *
	 * @return HermiqToolProvider
	 */
	private function provider(
		?string $uid,
		?CourseRecommendationEngine $engine = null,
		?MemoryService $memoryService = null,
		?WebSearchClient $webSearchClient = null,
		?WebFetchService $webFetchService = null,
		?DelegationService $delegationService = null,
		?NcNativeWriteService $writeService = null,
		?MailReadService $mailReadService = null,
	): HermiqToolProvider {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new HermiqToolProvider(
			$session,
			$this->createMock(IRootFolder::class),
			$this->createMock(IContactsManager::class),
			$this->createMock(ICalendarManager::class),
			$this->createMock(IMailer::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$engine ?? $this->createMock(CourseRecommendationEngine::class),
			$memoryService ?? $this->createMock(MemoryService::class),
			$webSearchClient ?? $this->createMock(WebSearchClient::class),
			$webFetchService ?? $this->createMock(WebFetchService::class),
			$delegationService ?? $this->createMock(DelegationService::class),
			$writeService ?? $this->createMock(NcNativeWriteService::class),
			$mailReadService ?? $this->createMock(MailReadService::class),
			$this->createMock(ToolAccessRequestService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end provider()

	/**
	 * Each nc-native-write tool id dispatches to its own write-service method,
	 * with the acting user's uid — never a caller-supplied one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-write-tools/specs/nc-native-tools/spec.md#requirement-calendar-and-contacts-expose-createupdate-verbs-scoped-to-the-acting-user
	 */
	public function testWriteToolsDispatchToTheWriteService(): void {
		$writeService = $this->createMock(NcNativeWriteService::class);
		$writeService->expects($this->once())
			->method('createCalendarEvent')
			->with('alice', $this->anything(), '')
			->willReturn(['created' => true]);
		$writeService->expects($this->once())
			->method('upsertContact')
			->with('alice', $this->anything(), '')
			->willReturn(['saved' => true]);
		$writeService->expects($this->once())->method('listNotes')->with('alice')->willReturn(['notes' => []]);
		$writeService->expects($this->once())->method('createNote')->with('alice', $this->anything())->willReturn(['created' => true]);
		$writeService->expects($this->once())->method('updateNote')->with('alice', $this->anything())->willReturn(['updated' => true]);

		$provider = $this->provider('alice', null, null, null, null, null, $writeService);

		$this->assertSame(['created' => true], $provider->invokeTool('hermiq.createCalendarEvent', ['summary' => 'x']));
		$this->assertSame(['saved' => true], $provider->invokeTool('hermiq.upsertContact', ['name' => 'Jansen']));
		$this->assertSame(['notes' => []], $provider->invokeTool('hermiq.listNotes', []));
		$this->assertSame(['created' => true], $provider->invokeTool('hermiq.createNote', ['title' => 'x']));
		$this->assertSame(['updated' => true], $provider->invokeTool('hermiq.updateNote', ['id' => 1]));

	}//end testWriteToolsDispatchToTheWriteService()

	/**
	 * The run-injected agentId reaches the calendar and contact writes, so the
	 * ADR-088 mark records an identity the model could not have supplied itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-write-tools/specs/nc-native-tools/spec.md#requirement-every-object-an-agent-writes-is-marked-as-agent-authored
	 */
	public function testRunInjectedAgentIdReachesTheMarkedWrites(): void {
		$writeService = $this->createMock(NcNativeWriteService::class);
		$writeService->expects($this->once())
			->method('createCalendarEvent')
			->with('alice', $this->anything(), 'agent-7')
			->willReturn(['created' => true]);

		$provider = $this->provider('alice', null, null, null, null, null, $writeService);

		$provider->invokeTool('hermiq.createCalendarEvent', ['summary' => 'x', 'agentId' => 'agent-7']);

	}//end testRunInjectedAgentIdReachesTheMarkedWrites()

	/**
	 * An unauthenticated session cannot reach any write tool.
	 *
	 * @return void
	 */
	public function testWriteToolsAreUnreachableWithoutAnAuthenticatedUser(): void {
		$writeService = $this->createMock(NcNativeWriteService::class);
		$writeService->expects($this->never())->method('createCalendarEvent');
		$writeService->expects($this->never())->method('upsertContact');
		$writeService->expects($this->never())->method('createNote');

		$provider = $this->provider(null, null, null, null, null, null, $writeService);

		foreach (['hermiq.createCalendarEvent', 'hermiq.upsertContact', 'hermiq.createNote'] as $toolId) {
			$result = $provider->invokeTool($toolId, []);
			$this->assertSame('unauthenticated', $result['error']['code']);
		}

	}//end testWriteToolsAreUnreachableWithoutAnAuthenticatedUser()

	/**
	 * Each mail read tool dispatches to its own MailReadService method with the
	 * acting user's uid — never a caller-supplied one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-mail-read-tools/specs/nc-native-tools/spec.md#requirement-mail-reading-is-exposed-read-only-and-scoped-to-the-acting-user
	 */
	public function testMailToolsDispatchToTheMailReadService(): void {
		$mail = $this->createMock(MailReadService::class);
		$mail->expects($this->once())->method('listAccounts')->with('alice')->willReturn(['accounts' => []]);
		$mail->expects($this->once())->method('listMessages')->with('alice', $this->anything())->willReturn(['messages' => []]);
		$mail->expects($this->once())->method('readMessage')->with('alice', $this->anything())->willReturn(['body' => 'x']);

		$provider = $this->provider('alice', null, null, null, null, null, null, $mail);

		$this->assertSame(['accounts' => []], $provider->invokeTool('hermiq.listMailAccounts', []));
		$this->assertSame(['messages' => []], $provider->invokeTool('hermiq.listMailMessages', ['mailboxId' => 1]));
		$this->assertSame(['body' => 'x'], $provider->invokeTool('hermiq.readMailMessage', ['id' => 1]));

	}//end testMailToolsDispatchToTheMailReadService()

	/**
	 * No mail tool is reachable without an authenticated user.
	 *
	 * @return void
	 */
	public function testMailToolsAreUnreachableWithoutAnAuthenticatedUser(): void {
		$mail = $this->createMock(MailReadService::class);
		$mail->expects($this->never())->method('listAccounts');
		$mail->expects($this->never())->method('readMessage');

		$provider = $this->provider(null, null, null, null, null, null, null, $mail);

		foreach (['hermiq.listMailAccounts', 'hermiq.listMailMessages', 'hermiq.readMailMessage'] as $toolId) {
			$this->assertSame('unauthenticated', $provider->invokeTool($toolId, [])['error']['code']);
		}

	}//end testMailToolsAreUnreachableWithoutAnAuthenticatedUser()

	/**
	 * getAppId is the hermiq app slug and every tool id is namespaced by it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
	 */
	public function testToolCatalogueIsNamespaced(): void {
		$provider = $this->provider('alice');

		$this->assertSame('hermiq', $provider->getAppId());

		$tools = $provider->getTools();
		// 6 nc-native-tools + hermiq.searchTools (agent-tool-governance-and-disclosure's
		// progressive-disclosure meta-tool) + hermiq.recommendCourses (ai-course-recommendations)
		// + hermiq.rememberMemory/recallMemory/forgetMemory (agent-memory-tools)
		// + hermiq.webSearch/webFetch (web-research-tool)
		// + hermiq.delegateAgent (sub-agent-delegation)
		// + hermiq.createCalendarEvent/upsertContact/listNotes/createNote/updateNote
		//   (nc-native-write-tools)
		// + hermiq.listMailAccounts/listMailMessages/readMailMessage
		//   (nc-mail-read-tools),
		// + hermiq.listAvailableTools/requestToolAccess
		//   (tool-discovery-and-access-requests),
		// all registered through this same provider.
		$this->assertCount(24, $tools);

		$ids = array_column($tools, 'id');
		$this->assertContains('hermiq.listFiles', $ids);
		$this->assertContains('hermiq.readFile', $ids);
		$this->assertContains('hermiq.searchContacts', $ids);
		$this->assertContains('hermiq.listCalendarEvents', $ids);
		$this->assertContains('hermiq.sendMail', $ids);
		$this->assertContains('hermiq.listDeckBoards', $ids);
		$this->assertContains('hermiq.searchTools', $ids);
		$this->assertContains('hermiq.recommendCourses', $ids);
		$this->assertContains('hermiq.rememberMemory', $ids);
		$this->assertContains('hermiq.recallMemory', $ids);
		$this->assertContains('hermiq.forgetMemory', $ids);
		$this->assertContains('hermiq.webSearch', $ids);
		$this->assertContains('hermiq.webFetch', $ids);
		$this->assertContains('hermiq.delegateAgent', $ids);

		foreach ($ids as $id) {
			$this->assertStringStartsWith('hermiq.', $id);
		}

	}//end testToolCatalogueIsNamespaced()

	/**
	 * Every descriptor carries the honest `readOnlyHint`/`destructiveHint`/
	 * `idempotentHint`/`scope` hint keys (hermiq-prefer-tool-hints) — before this
	 * fix these 2-segment ids carried NO hints at all and were fail-closed
	 * classified write/destructive by `ToolGrantResolver::isWriteOrDestructive()`,
	 * stripping all seven read-shaped tools from any default/wildcard grant.
	 *
	 * `recommendCourses` really persists a cached recommendation on staleness
	 * (`CourseRecommendationEngine::getOrRegenerate()` → `saveObject()`), so it is
	 * annotated as a genuine, non-idempotent `update`, not read-only. `sendMail`
	 * sends externally-visible, irreversible email, so it is annotated
	 * destructive + `create`, not read-only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
	 */
	public function testDescriptorsCarryHonestHintsAndScope(): void {
		$expected = [
			'hermiq.listFiles' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.readFile' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.searchContacts' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.listCalendarEvents' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.sendMail' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'scope' => 'create'],
			'hermiq.listDeckBoards' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.searchTools' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			// Discovery reads the catalogue and writes nothing.
			'hermiq.listAvailableTools' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			// `scope: write` and `readOnlyHint: false` — raising a request PERSISTS a
			// ToolAccessRequest object and notifies the owner. It grants nothing, but
			// declaring it read-only would hide a write behind the default-deny.
			'hermiq.requestToolAccess' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'write'],
			'hermiq.recommendCourses' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'scope' => 'update'],
			'hermiq.rememberMemory' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'scope' => 'create'],
			'hermiq.recallMemory' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.forgetMemory' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'scope' => 'delete'],
			'hermiq.webSearch' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.webFetch' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.delegateAgent' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'scope' => 'create'],
			// nc-native-write-tools, appended by getTools(). createCalendarEvent is
			// destructive because attendees trigger iMIP invitations that cannot be
			// recalled, and updateNote is destructive because Notes keeps no version
			// history — neither deletes anything, and both are irreversible.
			'hermiq.createCalendarEvent' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'scope' => 'create'],
			'hermiq.upsertContact' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'scope' => 'create'],
			'hermiq.listNotes' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.createNote' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'scope' => 'create'],
			'hermiq.updateNote' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'scope' => 'update'],
			// nc-mail-read-tools. Honestly read-only — which is exactly why the
			// write default-deny does NOT protect them, and why MailReadService
			// carries an AI-feature gate instead.
			'hermiq.listMailAccounts' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.listMailMessages' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			'hermiq.readMailMessage' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
		];

		$tools = $this->provider('alice')->getTools();
		$this->assertCount(24, $tools, 'This test must be updated if a tool is added or removed.');

		$seen = [];
		foreach ($tools as $tool) {
			$id = $tool['id'];
			$seen[$id] = true;

			$this->assertArrayHasKey($id, $expected, "Unexpected tool id '{$id}' has no hint expectation.");
			$this->assertSame($expected[$id]['readOnlyHint'], $tool['readOnlyHint'] ?? null, "{$id}: readOnlyHint mismatch.");
			$this->assertSame($expected[$id]['destructiveHint'], $tool['destructiveHint'] ?? null, "{$id}: destructiveHint mismatch.");
			$this->assertSame($expected[$id]['idempotentHint'], $tool['idempotentHint'] ?? null, "{$id}: idempotentHint mismatch.");
			$this->assertSame($expected[$id]['scope'], $tool['scope'] ?? null, "{$id}: scope mismatch.");
		}

		$this->assertSame(array_keys($expected), array_keys($seen), 'Every expected tool id must be present exactly once.');

	}//end testDescriptorsCarryHonestHintsAndScope()

	/**
	 * 🔴 EVERY descriptor MUST declare a `reach`, so a 15th tool cannot ship
	 * without one.
	 *
	 * This test enumerates rather than spot-checks on purpose. `resolve()` fails
	 * closed to `external` for an undeclared reach, which means a forgotten
	 * annotation does NOT crash and does NOT loosen anything — it silently makes
	 * a harmless tool un-callable without approval. That is a usability
	 * regression nobody would trace back to a missing array key, so the
	 * enumeration is the only thing that catches it at the source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-every-tool-descriptor-declares-a-reach-on-a-closed-ordered-vocabulary
	 */
	public function testEveryDescriptorDeclaresItsReach(): void {
		$expected = [
			'hermiq.listFiles' => ToolReachResolver::REACH_USER,
			'hermiq.readFile' => ToolReachResolver::REACH_USER,
			'hermiq.searchContacts' => ToolReachResolver::REACH_USER,
			'hermiq.listCalendarEvents' => ToolReachResolver::REACH_USER,
			'hermiq.sendMail' => ToolReachResolver::REACH_EXTERNAL,
			'hermiq.listDeckBoards' => ToolReachResolver::REACH_USER,
			'hermiq.searchTools' => ToolReachResolver::REACH_SELF,
			// `self`: reads the instance catalogue and this agent's own grants, and
			// returns metadata only — nothing dispatchable, nothing outside the agent.
			'hermiq.listAvailableTools' => ToolReachResolver::REACH_SELF,
			// `user`, not `self`: it writes a request object and raises a notification
			// to the agent's OWNER, so its effect lands on a human, not on the agent.
			'hermiq.requestToolAccess' => ToolReachResolver::REACH_USER,
			'hermiq.recommendCourses' => ToolReachResolver::REACH_USER,
			'hermiq.rememberMemory' => ToolReachResolver::REACH_SELF,
			'hermiq.recallMemory' => ToolReachResolver::REACH_SELF,
			'hermiq.forgetMemory' => ToolReachResolver::REACH_SELF,
			'hermiq.webSearch' => ToolReachResolver::REACH_EXTERNAL,
			'hermiq.webFetch' => ToolReachResolver::REACH_EXTERNAL,
			'hermiq.delegateAgent' => ToolReachResolver::REACH_INSTANCE,
			// `external`, not `user`: an event with attendees dispatches iMIP
			// invitations to third parties, so its blast radius leaves the instance.
			'hermiq.createCalendarEvent' => ToolReachResolver::REACH_EXTERNAL,
			'hermiq.upsertContact' => ToolReachResolver::REACH_USER,
			'hermiq.listNotes' => ToolReachResolver::REACH_USER,
			'hermiq.createNote' => ToolReachResolver::REACH_USER,
			'hermiq.updateNote' => ToolReachResolver::REACH_USER,
			// `user`, honestly: reading changes nothing and sends nothing out. The
			// new exposure is the INFERENCE path — the body reaches whatever engine
			// the run uses — which the reach axis cannot express and the
			// AI-feature gate governs instead.
			'hermiq.listMailAccounts' => ToolReachResolver::REACH_USER,
			'hermiq.listMailMessages' => ToolReachResolver::REACH_USER,
			'hermiq.readMailMessage' => ToolReachResolver::REACH_USER,
		];

		$tools = $this->provider('alice')->getTools();

		foreach ($tools as $tool) {
			$id = $tool['id'];

			$this->assertArrayHasKey(
				ToolReachResolver::REACH_KEY,
				$tool,
				"{$id} declares no `reach`. Every native descriptor must declare one explicitly: "
				. 'an omitted reach resolves to `external`, which quietly forces the tool behind the '
				. 'approval gate forever. Pick the honest value from the ToolReachResolver constants.'
			);
			$this->assertContains(
				$tool[ToolReachResolver::REACH_KEY],
				ToolReachResolver::ORDER,
				"{$id}: reach must come from the closed vocabulary."
			);
			$this->assertArrayHasKey($id, $expected, "Unexpected tool id '{$id}' has no reach expectation.");
			$this->assertSame($expected[$id], $tool[ToolReachResolver::REACH_KEY], "{$id}: reach mismatch.");
		}

		$this->assertSame(
			array_keys($expected),
			array_column($tools, 'id'),
			'Every expected tool id must be present exactly once, in order.'
		);

	}//end testEveryDescriptorDeclaresItsReach()

	/**
	 * Every descriptor declares a `subject` and an `action`.
	 *
	 * 🔴 Why this needs a test rather than a convention: an UNDECLARED subject
	 * is INVISIBLE. `ToolRegistryFacade::describeTools()` deliberately returns
	 * null rather than guessing one from the id — the right call, since a
	 * consumer cannot tell an inferred subject from a real one — so a descriptor
	 * that forgets these two keys produces no error, no warning and no failing
	 * test. It simply arrives at the grant matrix as a tool nobody can group,
	 * and the matrix renders it as a one-off row. That is how 87 of 177 tools
	 * across the fleet ended up undeclared without anyone noticing.
	 *
	 * ⚠️ The `action` vocabulary is deliberately NOT closed to CRUD. Three of
	 * these tools do something no CRUD verb describes — `sendMail` leaves the
	 * instance irreversibly, `requestToolAccess` escalates privilege, and
	 * `delegateAgent` hands the caller's authority to another agent. Filing any
	 * of them under `create` would put the thing that escalates privilege in the
	 * same grant bucket as the things it escalates privilege TO.
	 *
	 * @return void
	 */
	public function testEveryDescriptorDeclaresASubjectAndAnAction(): void {
		$expected = [
			'hermiq.listFiles' => ['file', 'list'],
			'hermiq.readFile' => ['file', 'get'],
			'hermiq.searchContacts' => ['contact', 'search'],
			'hermiq.listCalendarEvents' => ['calendarEvent', 'list'],
			// `send`, not `create`: irreversible and it reaches a third party.
			'hermiq.sendMail' => ['mail', 'send'],
			'hermiq.listDeckBoards' => ['deckBoard', 'list'],
			'hermiq.searchTools' => ['tool', 'search'],
			'hermiq.listAvailableTools' => ['tool', 'list'],
			// `request`: this one asks a HUMAN for a grant.
			'hermiq.requestToolAccess' => ['toolAccess', 'request'],
			'hermiq.recommendCourses' => ['course', 'recommend'],
			'hermiq.rememberMemory' => ['memory', 'create'],
			'hermiq.recallMemory' => ['memory', 'search'],
			'hermiq.forgetMemory' => ['memory', 'delete'],
			'hermiq.webSearch' => ['web', 'search'],
			'hermiq.webFetch' => ['web', 'get'],
			// `delegate`: hands this agent's authority to another one.
			'hermiq.delegateAgent' => ['agent', 'delegate'],
			'hermiq.createCalendarEvent' => ['calendarEvent', 'create'],
			// `upsert`: it may create OR update, so declaring either one alone
			// would let a grant reading "may update contacts" also create them.
			'hermiq.upsertContact' => ['contact', 'upsert'],
			'hermiq.listNotes' => ['note', 'list'],
			'hermiq.createNote' => ['note', 'create'],
			'hermiq.updateNote' => ['note', 'update'],
			'hermiq.listMailAccounts' => ['mailAccount', 'list'],
			'hermiq.listMailMessages' => ['mailMessage', 'list'],
			'hermiq.readMailMessage' => ['mailMessage', 'get'],
		];

		$tools = $this->provider('alice')->getTools();

		foreach ($tools as $tool) {
			$id = $tool['id'];

			foreach (['subject', 'action'] as $key) {
				$this->assertArrayHasKey(
					$key,
					$tool,
					"{$id} declares no `{$key}`. An undeclared one is not an error anywhere — "
					. 'describeTools() returns null rather than guessing, so the tool simply '
					. 'arrives at the grant matrix ungroupable and renders as a one-off row.'
				);
				$this->assertNotSame('', trim((string)$tool[$key]), "{$id}: `{$key}` must not be empty.");
			}

			$this->assertArrayHasKey($id, $expected, "Unexpected tool id '{$id}' has no taxonomy expectation.");
			$this->assertSame(
				$expected[$id],
				[$tool['subject'], $tool['action']],
				"{$id}: subject/action mismatch."
			);
		}

		$this->assertSame(
			array_keys($expected),
			array_column($tools, 'id'),
			'Every expected tool id must be present exactly once, in order.'
		);

	}//end testEveryDescriptorDeclaresASubjectAndAnAction()

	/**
	 * 🔴 THE POSITIVE CONTROL for the axis itself.
	 *
	 * A `reach` that merely restates `scope` would be a second name for the same
	 * fact, and every gating decision built on it would be the CRUD decision
	 * wearing a hat. The axis earns its existence only if the two disagree in
	 * BOTH directions — same scope splitting across reaches, and one reach
	 * spanning several scopes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-every-tool-descriptor-declares-a-reach-on-a-closed-ordered-vocabulary
	 */
	public function testReachIsNotARestatementOfScope(): void {
		$tools = $this->provider('alice')->getTools();

		$byId = [];
		foreach ($tools as $tool) {
			$byId[$tool['id']] = $tool;
		}

		// Same scope, different reach: both `create`, yet one never leaves the
		// agent and the other lands in a stranger's inbox. This pair is the
		// whole reason the axis exists.
		$this->assertSame('create', $byId['hermiq.rememberMemory']['scope']);
		$this->assertSame('create', $byId['hermiq.sendMail']['scope']);
		$this->assertNotSame(
			$byId['hermiq.rememberMemory'][ToolReachResolver::REACH_KEY],
			$byId['hermiq.sendMail'][ToolReachResolver::REACH_KEY],
			'rememberMemory and sendMail share a scope and must NOT share a reach.'
		);

		// Same reach, different scope: `delete` that is reversible and private
		// sits at the SAME reach as a plain `read`. CRUD severity does not
		// survive the translation.
		$this->assertSame('delete', $byId['hermiq.forgetMemory']['scope']);
		$this->assertSame('read', $byId['hermiq.searchTools']['scope']);
		$this->assertSame(
			$byId['hermiq.forgetMemory'][ToolReachResolver::REACH_KEY],
			$byId['hermiq.searchTools'][ToolReachResolver::REACH_KEY],
			'A private, reversible delete reaches no further than a read.'
		);

		// And a `read` scope reaching `external` — the webFetch shape, where the
		// CRUD verb is the most reassuring thing about the tool.
		$this->assertSame('read', $byId['hermiq.webFetch']['scope']);
		$this->assertSame(
			ToolReachResolver::REACH_EXTERNAL,
			$byId['hermiq.webFetch'][ToolReachResolver::REACH_KEY]
		);

	}//end testReachIsNotARestatementOfScope()

	/**
	 * An unauthenticated caller gets a structured error, never an exception.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-7
	 */
	public function testUnauthenticatedReturnsError(): void {
		$result = $this->provider(null)->invokeTool('hermiq.listFiles', []);

		$this->assertArrayHasKey('error', $result);
		$this->assertSame('unauthenticated', $result['error']['code']);

	}//end testUnauthenticatedReturnsError()

	/**
	 * An unknown tool id returns a structured error, never an exception.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-7
	 */
	public function testUnknownToolReturnsError(): void {
		$result = $this->provider('alice')->invokeTool('hermiq.doesNotExist', []);

		$this->assertArrayHasKey('error', $result);
		$this->assertSame('unknown_tool', $result['error']['code']);

	}//end testUnknownToolReturnsError()

	/**
	 * sendMail with missing arguments returns an invalid_argument error (no throw).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nc-native-tools/tasks.md#task-1-5
	 */
	public function testSendMailWithoutArgumentsReturnsError(): void {
		$result = $this->provider('alice')->invokeTool('hermiq.sendMail', ['to' => '']);

		$this->assertArrayHasKey('error', $result);
		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testSendMailWithoutArgumentsReturnsError()

	/**
	 * recommendCourses delegates to the shared CourseRecommendationEngine with the
	 * ACTING user's own uid — no separate authorization path, no request-supplied
	 * learnerId (spec.md "Recommendation access is self-scoped").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-4-1
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-ranked-recommendations-are-chat-companion-reachable-via-a-domain-mcp-tool
	 */
	public function testRecommendCoursesDelegatesToEngineWithActingUid(): void {
		$engine = $this->createMock(CourseRecommendationEngine::class);
		$engine->expects($this->once())
			->method('getOrRegenerate')
			->with($this->equalTo('alice'))
			->willReturn(['learnerId' => 'alice', 'status' => 'fresh', 'recommendations' => []]);

		$result = $this->provider('alice', $engine)->invokeTool('hermiq.recommendCourses', []);

		$this->assertSame('fresh', $result['status']);
		$this->assertArrayNotHasKey('error', $result);

	}//end testRecommendCoursesDelegatesToEngineWithActingUid()

	/**
	 * A failure inside the engine (e.g. Scholiq absent, or any other Throwable) never
	 * crosses the MCP boundary as an exception — invokeTool()'s own outer catch turns
	 * it into the same structured `{error: {code, message}}` envelope every other
	 * tool failure uses (spec.md "A tool failure never crosses the MCP boundary as
	 * an exception").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-ranked-recommendations-are-chat-companion-reachable-via-a-domain-mcp-tool
	 */
	public function testRecommendCoursesNeverThrowsAcrossTheMcpBoundary(): void {
		$engine = $this->createMock(CourseRecommendationEngine::class);
		$engine->method('getOrRegenerate')->willThrowException(new RuntimeException('scholiq unreachable'));

		$result = $this->provider('alice', $engine)->invokeTool('hermiq.recommendCourses', []);

		$this->assertArrayHasKey('error', $result);
		$this->assertSame('tool_failed', $result['error']['code']);

	}//end testRecommendCoursesNeverThrowsAcrossTheMcpBoundary()

	/**
	 * A Memory/UserProfile ObjectEntity with the given payload (mirrors
	 * MemoryServiceTest's helper).
	 *
	 * @param array<string, mixed> $payload The object data.
	 *
	 * @return ObjectEntity
	 */
	private function memoryObject(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('mem-uuid');
		$entity->setObject($payload);
		return $entity;
	}//end memoryObject()

	/**
	 * Without an `agentId` in arguments (agent-less chat — `FacadeToolInvoker`
	 * never injects one when the run has none), every memory tool returns the
	 * structured `no_agent_context` error rather than guessing which agent's
	 * Memory to touch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
	 */
	public function testMemoryToolsWithoutAgentContextReturnError(): void {
		$provider = $this->provider('alice');

		foreach (['hermiq.rememberMemory', 'hermiq.recallMemory', 'hermiq.forgetMemory'] as $toolId) {
			$result = $provider->invokeTool($toolId, []);
			$this->assertArrayHasKey('error', $result, "{$toolId} must error without an agentId.");
			$this->assertSame('no_agent_context', $result['error']['code'], "{$toolId} must report no_agent_context.");
		}

	}//end testMemoryToolsWithoutAgentContextReturnError()

	/**
	 * rememberMemory(scope: agent) delegates to appendMemoryEntry() with the
	 * run-injected agentId, and surfaces the newly-appended entry's id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
	 */
	public function testRememberMemoryAgentScopeDelegatesToAppendMemoryEntry(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->expects($this->once())
			->method('appendMemoryEntry')
			->with($this->equalTo('agent-1'), $this->equalTo('the sky is blue'))
			->willReturn(
				$this->memoryObject(
					[
						'entries' => [['id' => 'entry-1', 'text' => 'the sky is blue', 'createdAt' => '2026-01-01T00:00:00+00:00']],
						'needsConsolidation' => false,
					]
				)
			);

		$result = $this->provider('alice', null, $memoryService)->invokeTool(
			'hermiq.rememberMemory',
			['agentId' => 'agent-1', 'content' => 'the sky is blue', 'scope' => 'agent']
		);

		$this->assertTrue($result['remembered']);
		$this->assertSame('agent', $result['scope']);
		$this->assertSame('entry-1', $result['entryId']);
		$this->assertFalse($result['needsConsolidation']);

	}//end testRememberMemoryAgentScopeDelegatesToAppendMemoryEntry()

	/**
	 * rememberMemory(scope: user) delegates to appendUserProfileEntry() with the
	 * ACTING user's own uid — a caller-supplied `subjectUid` argument (should the
	 * LLM ever pass one, though the declared inputSchema has no such property) is
	 * never consulted, matching every other `HermiqToolProvider` tool's IDOR
	 * posture.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
	 */
	public function testRememberMemoryUserScopeUsesActingUidNeverCallerSupplied(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->expects($this->once())
			->method('appendUserProfileEntry')
			->with($this->equalTo('agent-1'), $this->equalTo('alice'), $this->equalTo('likes tea'))
			->willReturn($this->memoryObject(['entries' => [['id' => 'entry-2', 'text' => 'likes tea', 'createdAt' => '2026-01-01T00:00:00+00:00']]]));

		$result = $this->provider('alice', null, $memoryService)->invokeTool(
			'hermiq.rememberMemory',
			['agentId' => 'agent-1', 'content' => 'likes tea', 'scope' => 'user', 'subjectUid' => 'mallory']
		);

		$this->assertTrue($result['remembered']);
		$this->assertSame('user', $result['scope']);

	}//end testRememberMemoryUserScopeUsesActingUidNeverCallerSupplied()

	/**
	 * rememberMemory rejects an empty content or an invalid scope value without
	 * calling MemoryService at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
	 */
	public function testRememberMemoryInvalidArgumentsReturnError(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->expects($this->never())->method('appendMemoryEntry');
		$memoryService->expects($this->never())->method('appendUserProfileEntry');

		$provider = $this->provider('alice', null, $memoryService);

		$missingContent = $provider->invokeTool('hermiq.rememberMemory', ['agentId' => 'agent-1', 'content' => '  ', 'scope' => 'agent']);
		$this->assertSame('invalid_argument', $missingContent['error']['code']);

		$badScope = $provider->invokeTool('hermiq.rememberMemory', ['agentId' => 'agent-1', 'content' => 'x', 'scope' => 'nope']);
		$this->assertSame('invalid_argument', $badScope['error']['code']);

	}//end testRememberMemoryInvalidArgumentsReturnError()

	/**
	 * recallMemory merges MemoryService::recallEntries() (Memory/UserProfile
	 * matches) with the existing recallSessions() (SessionTurn matches) into one
	 * combined result — no second search index.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-recall-tool
	 */
	public function testRecallMemoryMergesEntriesAndSessionTurns(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->expects($this->once())
			->method('recallEntries')
			->with($this->equalTo('agent-1'), $this->equalTo('alice'), $this->equalTo('budget'))
			->willReturn(
				[
					'memoryEntries' => [['id' => 'e1', 'text' => 'budget is 8000 chars', 'createdAt' => '2026-01-01T00:00:00+00:00']],
					'userProfileEntries' => [],
				]
			);

		$turn = $this->memoryObject(['role' => 'user', 'content' => 'what is the budget?', 'createdAt' => '2026-01-02T00:00:00+00:00']);
		$memoryService->expects($this->once())
			->method('recallSessions')
			->with($this->equalTo('agent-1'), $this->equalTo('budget'))
			->willReturn([$turn]);

		$result = $this->provider('alice', null, $memoryService)->invokeTool(
			'hermiq.recallMemory',
			['agentId' => 'agent-1', 'query' => 'budget']
		);

		$this->assertSame('budget', $result['query']);
		$this->assertCount(1, $result['memoryEntries']);
		$this->assertSame('e1', $result['memoryEntries'][0]['id']);
		$this->assertCount(0, $result['userProfileEntries']);
		$this->assertCount(1, $result['sessionTurns']);
		$this->assertSame('user', $result['sessionTurns'][0]['role']);

	}//end testRecallMemoryMergesEntriesAndSessionTurns()

	/**
	 * recallMemory rejects an empty query without calling MemoryService at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
	 */
	public function testRecallMemoryMissingQueryReturnsError(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->expects($this->never())->method('recallEntries');

		$result = $this->provider('alice', null, $memoryService)->invokeTool('hermiq.recallMemory', ['agentId' => 'agent-1', 'query' => ' ']);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testRecallMemoryMissingQueryReturnsError()

	/**
	 * forgetMemory delegates to MemoryService::forgetEntry() with the ACTING
	 * user's own uid (never a caller-supplied `subjectUid`) and surfaces a found
	 * result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
	 */
	public function testForgetMemoryDelegatesWithActingUidAndReturnsFound(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->expects($this->once())
			->method('forgetEntry')
			->with($this->equalTo('agent-1'), $this->equalTo('alice'), $this->equalTo('entry-1'))
			->willReturn(['found' => true, 'scope' => 'memory']);

		$result = $this->provider('alice', null, $memoryService)->invokeTool(
			'hermiq.forgetMemory',
			['agentId' => 'agent-1', 'id' => 'entry-1', 'subjectUid' => 'mallory']
		);

		$this->assertTrue($result['found']);
		$this->assertSame('memory', $result['scope']);

	}//end testForgetMemoryDelegatesWithActingUidAndReturnsFound()

	/**
	 * forgetMemory returns a structured not-found result — never an exception —
	 * when MemoryService reports no match in either object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
	 */
	public function testForgetMemoryNotFoundReturnsStructuredResult(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->method('forgetEntry')->willReturn(['found' => false, 'scope' => null]);

		$result = $this->provider('alice', null, $memoryService)->invokeTool(
			'hermiq.forgetMemory',
			['agentId' => 'agent-1', 'id' => 'no-such-entry']
		);

		$this->assertFalse($result['found']);
		$this->assertArrayNotHasKey('error', $result);

	}//end testForgetMemoryNotFoundReturnsStructuredResult()

	/**
	 * forgetMemory rejects an empty id without calling MemoryService at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
	 */
	public function testForgetMemoryMissingIdReturnsError(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->expects($this->never())->method('forgetEntry');

		$result = $this->provider('alice', null, $memoryService)->invokeTool('hermiq.forgetMemory', ['agentId' => 'agent-1', 'id' => '']);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testForgetMemoryMissingIdReturnsError()

	/**
	 * A failure inside MemoryService never crosses the MCP boundary as an
	 * exception for any of the three memory tools — invokeTool()'s outer catch
	 * turns it into the same structured error envelope every other tool uses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
	 */
	public function testMemoryToolFailureNeverThrowsAcrossTheMcpBoundary(): void {
		$memoryService = $this->createMock(MemoryService::class);
		$memoryService->method('appendMemoryEntry')->willThrowException(new RuntimeException('object store unreachable'));

		$result = $this->provider('alice', null, $memoryService)->invokeTool(
			'hermiq.rememberMemory',
			['agentId' => 'agent-1', 'content' => 'x', 'scope' => 'agent']
		);

		$this->assertArrayHasKey('error', $result);
		$this->assertSame('tool_failed', $result['error']['code']);

	}//end testMemoryToolFailureNeverThrowsAcrossTheMcpBoundary()

	/**
	 * webSearch delegates to WebSearchClient with the acting uid (for the broker's
	 * sessionless-caller path) and the query argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
	 */
	public function testWebSearchDelegatesToClientWithActingUid(): void {
		$client = $this->createMock(WebSearchClient::class);
		$client->expects($this->once())
			->method('search')
			->with(query: 'nextcloud news', actingUserId: 'alice')
			->willReturn(['query' => 'nextcloud news', 'results' => []]);

		$result = $this->provider('alice', null, null, $client)->invokeTool(
			'hermiq.webSearch',
			['query' => 'nextcloud news']
		);

		$this->assertSame(['query' => 'nextcloud news', 'results' => []], $result);

	}//end testWebSearchDelegatesToClientWithActingUid()

	/**
	 * webSearch rejects an empty/missing query before ever reaching the client.
	 *
	 * @return void
	 */
	public function testWebSearchMissingQueryReturnsError(): void {
		$client = $this->createMock(WebSearchClient::class);
		$client->expects($this->never())->method('search');

		$result = $this->provider('alice', null, null, $client)->invokeTool('hermiq.webSearch', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testWebSearchMissingQueryReturnsError()

	/**
	 * webFetch delegates to WebFetchService with the url argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-webfetch-extracts-readable-text-with-a-content-type-gate
	 */
	public function testWebFetchDelegatesToService(): void {
		$service = $this->createMock(WebFetchService::class);
		$service->expects($this->once())
			->method('fetch')
			->with(url: 'https://example.org/page')
			->willReturn(['url' => 'https://example.org/page', 'truncated' => false, 'content' => 'hello']);

		$result = $this->provider('alice', null, null, null, $service)->invokeTool(
			'hermiq.webFetch',
			['url' => 'https://example.org/page']
		);

		$this->assertSame('https://example.org/page', $result['url']);

	}//end testWebFetchDelegatesToService()

	/**
	 * webFetch rejects an empty/missing url before ever reaching the service.
	 *
	 * @return void
	 */
	public function testWebFetchMissingUrlReturnsError(): void {
		$service = $this->createMock(WebFetchService::class);
		$service->expects($this->never())->method('fetch');

		$result = $this->provider('alice', null, null, null, $service)->invokeTool('hermiq.webFetch', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testWebFetchMissingUrlReturnsError()

	/**
	 * A WebSearchClient/WebFetchService exception never crosses the MCP boundary —
	 * invokeTool()'s outer catch turns it into the same structured error envelope
	 * every other tool uses (mirrors testMemoryToolFailureNeverThrowsAcrossTheMcpBoundary()).
	 *
	 * @return void
	 */
	public function testWebResearchToolFailureNeverThrowsAcrossTheMcpBoundary(): void {
		$client = $this->createMock(WebSearchClient::class);
		$client->method('search')->willThrowException(new RuntimeException('unexpected'));

		$result = $this->provider('alice', null, null, $client)->invokeTool('hermiq.webSearch', ['query' => 'x']);

		$this->assertArrayHasKey('error', $result);
		$this->assertSame('tool_failed', $result['error']['code']);

	}//end testWebResearchToolFailureNeverThrowsAcrossTheMcpBoundary()
}//end class
