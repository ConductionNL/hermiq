<?php

/**
 * Tests for ToolAccessRequestService.
 *
 * ⚠️ THE INVARIANT UNDER TEST IS A SECURITY BOUNDARY, not a feature detail:
 * `request()` raises a pending record and notifies a human. It must NEVER write
 * `Agent.tools`. The whole point of `tool-scope-security-default` was that an
 * unconfigured agent holds nothing; a request path that could widen grants by
 * itself would hand back exactly the wildcard the default-deny removed.
 *
 * So the writes are captured and asserted BY SCHEMA, not merely counted — a test
 * that only checked "saveObject was called once" would pass just as happily if
 * the one call were against the Agent schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://hermiq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of discovery-past-the-grant and the request that can widen it.
 */
class ToolAccessRequestServiceTest extends TestCase {

	/**
	 * Writes captured from the fake ObjectService, as [schema, payload] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * Reset the capture between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->writes = [];

	}//end setUp()

	/**
	 * Build the service over fakes.
	 *
	 * @param array<int, array<string, mixed>> $catalog       The instance tool catalogue.
	 * @param array<string, mixed>             $agent         The agent object payload.
	 * @param array<string, mixed>|null        $existing      An existing request, or null.
	 * @param bool                             $appsEnabled   Whether foreign apps are enabled for the user.
	 * @param bool                             $registerBroke Whether OpenRegister is unavailable.
	 *
	 * @return ToolAccessRequestService
	 */
	private function service(
		array $catalog,
		array $agent = [],
		?array $existing = null,
		bool $appsEnabled = true,
		bool $registerBroke = false,
		?INotificationManager $notifications = null,
	): ToolAccessRequestService {
		$objectService = new class($agent, $existing, $this->writes) {
			/**
			 * @param array<string, mixed>      $agent    Agent payload.
			 * @param array<string, mixed>|null $existing Existing request.
			 * @param array<int, mixed>         $writes   Capture, by reference.
			 */
			public function __construct(
				private readonly array $agent,
				private readonly ?array $existing,
				public array &$writes,
			) {
			}

			/**
			 * @param mixed ...$args Positional — a mock cannot observe named arguments.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(...$args): ?array {
				return ($this->agent === [] ? null : $this->agent);
			}

			/**
			 * @param mixed ...$args Positional.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(...$args): array {
				return ($this->existing === null ? [] : [$this->existing]);
			}

			/**
			 * @param array<string, mixed> $object   The payload.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null): array {
				$this->writes[] = [(string)$schema, $object];

				return ($object + ['id' => 'req-new']);
			}
		};

		$facade = new class($catalog) {
			/**
			 * @param array<int, array<string, mixed>> $catalog The catalogue.
			 */
			public function __construct(private readonly array $catalog) {
			}

			/**
			 * @param array<int, string> $toolWhitelist Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function listTools(array $toolWhitelist = []): array {
				return $this->catalog;
			}
		};

		$user = $this->createMock(\OCP\IUser::class);
		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($appsEnabled);
		// `pendingApprovals()` asks whether OpenRegister is installed BEFORE
		// reaching for it, so the double has to answer that question too —
		// without this it returns false and every approval read is empty for a
		// reason the test never intended.
		$appManager->method('isInstalled')->willReturn($registerBroke === false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $facade, $appManager, $userManager, $registerBroke) {
				if (str_contains($id, 'OpenRegister') === true && $registerBroke === true) {
					throw new \RuntimeException('OpenRegister is not installed');
				}

				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\OpenRegister\Service\Mcp\ToolRegistryFacade') {
					return $facade;
				}

				if ($id === \OCP\App\IAppManager::class) {
					return $appManager;
				}

				if ($id === \OCP\IUserManager::class) {
					return $userManager;
				}

				throw new \RuntimeException('not wired in this test: ' . $id);
			}
		);

		return new ToolAccessRequestService(
			$container,
			new ToolGrantResolver(),
			($notifications ?? $this->createMock(INotificationManager::class)),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * A catalogue entry.
	 *
	 * @param string $id    Tool id.
	 * @param string $scope 'read' or 'write'.
	 *
	 * @return array<string, mixed>
	 */
	private function tool(string $id, string $scope = 'read'): array {
		return [
			'mcpId' => $id,
			'name' => str_replace('.', '_', $id),
			'description' => 'does ' . $id,
			'scope' => $scope,
			'readOnlyHint' => ($scope === 'read'),
		];
	}//end tool()

	/**
	 * 🔴 THE LINE THIS CLASS DEFENDS: raising a request writes a request, and
	 * NOTHING against the agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-an-agent-must-be-able-to-request-access-and-must-not-be-able-to-grant-it
	 */
	public function testRequestWritesARequestAndNeverTheAgentsGrants(): void {
		$service = $this->service(
			[$this->tool('hermiq.sendMail', 'write')],
			['uuid' => 'agent-1', 'owner' => 'alice', 'tools' => []]
		);

		$result = $service->request('alice', 'agent-1', 'hermiq.sendMail', 'I need to mail the report.');

		$this->assertSame('pending', $result['status']);
		$this->assertCount(1, $this->writes, 'exactly one object should have been written');
		$this->assertSame(
			'ToolAccessRequest',
			$this->writes[0][0],
			'the ONLY write must be the request record — a write against the Agent schema here would be a self-granted tool'
		);
		$this->assertSame('pending', $this->writes[0][1]['status']);
		$this->assertSame('hermiq.sendMail', $this->writes[0][1]['toolId']);
		$this->assertSame('alice', $this->writes[0][1]['requestedBy']);

	}//end testRequestWritesARequestAndNeverTheAgentsGrants()

	/**
	 * A request that names no agent is refused — there is nobody to ask on
	 * behalf of, and nobody to notify.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-an-agent-must-be-able-to-request-access-and-must-not-be-able-to-grant-it
	 */
	public function testRequestWithoutAnAgentIsRefused(): void {
		$service = $this->service([$this->tool('hermiq.sendMail')]);

		$result = $service->request('alice', null, 'hermiq.sendMail', 'because');

		$this->assertSame('no_agent', $result['error']);
		$this->assertSame([], $this->writes);

	}//end testRequestWithoutAnAgentIsRefused()

	/**
	 * A reason is mandatory: it is the entire content of the decision the human
	 * is being asked to make.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-the-approval-surface-must-show-the-facts-beside-the-agents-argument
	 */
	public function testRequestWithoutAReasonIsRefused(): void {
		$service = $this->service([$this->tool('hermiq.sendMail')]);

		$result = $service->request('alice', 'agent-1', 'hermiq.sendMail', '');

		$this->assertSame('invalid_request', $result['error']);
		$this->assertSame([], $this->writes);

	}//end testRequestWithoutAReasonIsRefused()

	/**
	 * A tool that exists nowhere on the instance cannot be requested — otherwise
	 * the request queue becomes a place to make up capability names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-an-agent-must-be-able-to-request-access-and-must-not-be-able-to-grant-it
	 */
	public function testRequestForAnUnknownToolIsRefused(): void {
		$service = $this->service([$this->tool('hermiq.sendMail')]);

		$result = $service->request('alice', 'agent-1', 'hermiq.notAThing', 'because');

		$this->assertSame('unknown_tool', $result['error']);
		$this->assertSame([], $this->writes);

	}//end testRequestForAnUnknownToolIsRefused()

	/**
	 * 🔴 A REFUSAL STANDS. Re-asking must not create a second record: an agent
	 * that can re-ask will re-ask, and a persistent model against a tired human
	 * is an approval mechanism with a known outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-requests-must-be-bounded-and-a-refusal-must-persist
	 */
	public function testARefusedRequestIsNotReRaisable(): void {
		$service = $this->service(
			[$this->tool('hermiq.sendMail')],
			['uuid' => 'agent-1', 'owner' => 'alice'],
			['agentId' => 'agent-1', 'toolId' => 'hermiq.sendMail', 'status' => 'refused']
		);

		$result = $service->request('alice', 'agent-1', 'hermiq.sendMail', 'please, again');

		$this->assertSame('refused', $result['status']);
		$this->assertStringContainsString('already refused', $result['message']);
		$this->assertSame([], $this->writes, 'a standing refusal must not be overwritten by a fresh request');

	}//end testARefusedRequestIsNotReRaisable()

	/**
	 * Discovery sees PAST the grant — that is the whole feature — but reports
	 * honestly which tools are actually held.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-an-agent-must-be-able-to-discover-tools-it-does-not-hold
	 */
	public function testListAvailableShowsUnheldToolsAndMarksHeldOnes(): void {
		$service = $this->service(
			[$this->tool('hermiq.listFiles'), $this->tool('hermiq.sendMail', 'write')],
			['uuid' => 'agent-1', 'owner' => 'alice', 'tools' => ['hermiq.listFiles']]
		);

		$result = $service->listAvailable('alice', 'agent-1');
		$byId = array_column($result['tools'], null, 'id');

		$this->assertArrayHasKey('hermiq.sendMail', $byId, 'an UNHELD tool must still be discoverable');
		$this->assertFalse($byId['hermiq.sendMail']['held']);
		$this->assertTrue($byId['hermiq.listFiles']['held']);
		$this->assertSame('write', $byId['hermiq.sendMail']['reach']);
		$this->assertSame('read', $byId['hermiq.listFiles']['reach']);

	}//end testListAvailableShowsUnheldToolsAndMarksHeldOnes()

	/**
	 * ⚠️ THE DISCLOSURE BOUND. Seeing past the agent's grants is intended;
	 * seeing past the USER's app access is not. A tool from an app the acting
	 * user cannot use must not appear, or the listing becomes an inventory of
	 * what is installed to anyone who can open a chat.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-discovery-must-be-scoped-to-what-the-acting-user-may-see
	 */
	public function testListAvailableHidesToolsFromAppsTheUserCannotSee(): void {
		$service = $this->service(
			[$this->tool('hermiq.listFiles'), $this->tool('pipelinq.lead.search')],
			['uuid' => 'agent-1', 'owner' => 'alice', 'tools' => []],
			null,
			false
		);

		$ids = array_column($service->listAvailable('alice', 'agent-1')['tools'], 'id');

		$this->assertContains('hermiq.listFiles', $ids, "hermiq's own tools are always visible");
		$this->assertNotContains('pipelinq.lead.search', $ids, 'a foreign app disabled for this user must not be disclosed');

	}//end testListAvailableHidesToolsFromAppsTheUserCannotSee()

	/**
	 * A keyword filters the listing rather than returning everything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-an-agent-must-be-able-to-discover-tools-it-does-not-hold
	 */
	public function testListAvailableFiltersOnTheQuery(): void {
		$service = $this->service(
			[$this->tool('hermiq.listFiles'), $this->tool('hermiq.sendMail')],
			['uuid' => 'agent-1', 'owner' => 'alice', 'tools' => []]
		);

		$ids = array_column($service->listAvailable('alice', 'agent-1', 'mail')['tools'], 'id');

		$this->assertSame(['hermiq.sendMail'], $ids);

	}//end testListAvailableFiltersOnTheQuery()

	/**
	 * The listing is BOUNDED. An unbounded list re-inflates the context this
	 * programme spent a change shrinking.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-requests-must-be-bounded-and-a-refusal-must-persist
	 */
	public function testListAvailableIsBounded(): void {
		$catalog = [];
		for ($i = 0; $i < 60; $i++) {
			$catalog[] = $this->tool('hermiq.tool' . $i);
		}

		$service = $this->service($catalog, ['uuid' => 'agent-1', 'owner' => 'alice', 'tools' => []]);

		$this->assertCount(25, $service->listAvailable('alice', 'agent-1')['tools']);

	}//end testListAvailableIsBounded()

	/**
	 * With OpenRegister absent the service degrades to an empty catalogue and
	 * says so, rather than throwing into the caller's tool loop.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-an-agent-must-be-able-to-discover-tools-it-does-not-hold
	 */
	public function testListAvailableDegradesWhenOpenRegisterIsAbsent(): void {
		$service = $this->service([$this->tool('hermiq.listFiles')], [], null, true, true);

		$result = $service->listAvailable('alice', 'agent-1');

		$this->assertSame([], $result['tools']);
		$this->assertStringContainsString('No tool catalogue', $result['note']);

	}//end testListAvailableDegradesWhenOpenRegisterIsAbsent()

	/**
	 * ⚠️ Reach FAILS TOWARDS 'write'. A descriptor declaring neither `scope` nor
	 * `readOnlyHint` must be presented as a write: over-stating reach costs a
	 * question, under-stating it buys a grant the owner did not mean to give.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-the-approval-surface-must-show-the-facts-beside-the-agents-argument
	 */
	public function testAnUndeclaredReachIsReportedAsWrite(): void {
		$service = $this->service(
			[['mcpId' => 'hermiq.mystery', 'description' => 'undeclared']],
			['uuid' => 'agent-1', 'owner' => 'alice', 'tools' => []]
		);

		$rows = $service->listAvailable('alice', 'agent-1')['tools'];

		$this->assertSame('write', $rows[0]['reach']);

	}//end testAnUndeclaredReachIsReportedAsWrite()

	/**
	 * The agent's OWNER is the one told, and the notification names the tool.
	 *
	 * A grant visible only by re-reading `Agent.tools` is how an agent's
	 * capability drifts from what its owner believes it has, so the announcement
	 * is part of the guarantee, not decoration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-the-owner-must-be-notified-when-a-request-is-raised-and-when-access-is-granted
	 */
	public function testNotifyOwnerTellsTheOwnerWhichToolWasGranted(): void {
		$notification = $this->createMock(\OCP\Notification\INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		$seen = [];
		$notification->expects($this->once())->method('setUser')->willReturnCallback(
			function (string $uid) use ($notification, &$seen) {
				$seen['user'] = $uid;
				return $notification;
			}
		);
		$notification->expects($this->once())->method('setSubject')->willReturnCallback(
			function (string $subject, array $params) use ($notification, &$seen) {
				$seen['subject'] = $subject;
				$seen['tool'] = $params['tool'];
				return $notification;
			}
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects($this->once())->method('notify');

		$service = $this->service(
			[$this->tool('hermiq.sendMail')],
			['uuid' => 'agent-1', 'owner' => 'alice', 'name' => 'Reporter'],
			null,
			true,
			false,
			$notifications
		);

		$service->notifyOwner('agent-1', 'hermiq.sendMail', 'granted');

		$this->assertSame('alice', $seen['user']);
		$this->assertSame('tool_access_granted', $seen['subject']);
		$this->assertSame('hermiq.sendMail', $seen['tool']);

	}//end testNotifyOwnerTellsTheOwnerWhichToolWasGranted()

	/**
	 * An agent with no resolvable owner notifies NOBODY rather than dispatching
	 * to an empty user id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-the-owner-must-be-notified-when-a-request-is-raised-and-when-access-is-granted
	 */
	public function testNotifyOwnerDoesNothingWhenThereIsNoOwner(): void {
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('notify');

		$service = $this->service(
			[$this->tool('hermiq.sendMail')],
			['uuid' => 'agent-1'],
			null,
			true,
			false,
			$notifications
		);

		$service->notifyOwner('agent-1', 'hermiq.sendMail', 'requested');

	}//end testNotifyOwnerDoesNothingWhenThereIsNoOwner()

	/**
	 * A pending request is offered to the chat as a generic approval.
	 *
	 * The shape matters as much as the content: each item carries its own
	 * `kind` and `resolveUrl` so the chat posts a decision where it is told to
	 * rather than knowing how a tool-access request is resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function testPendingApprovalsDescribeHowToResolveThemselves(): void {
		$service = $this->service(
			catalog: [],
			existing: [
				'id' => 'req-1',
				'agentId' => 'agent-1',
				'toolId' => 'hermiq.sendMail',
				'status' => 'pending',
				'reason' => 'to email the report',
			]
		);

		$approvals = $service->pendingApprovals('agent-1');

		$this->assertCount(1, $approvals);
		$this->assertSame('hermiq.sendMail', $approvals[0]['title']);
		$this->assertSame('agent-1', $approvals[0]['agentId']);
		$this->assertStringContainsString(
			'tool-access-requests/req-1',
			$approvals[0]['resolveUrl'],
			'the chat must be told where to post the decision'
		);
	}//end testPendingApprovalsDescribeHowToResolveThemselves()

	/**
	 * With no agent there is nothing to approve, and nothing is looked up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function testPendingApprovalsWithoutAnAgentAreEmpty(): void {
		$service = $this->service(catalog: []);

		$this->assertSame([], $service->pendingApprovals(null));
		$this->assertSame([], $service->pendingApprovals(''));
	}//end testPendingApprovalsWithoutAnAgentAreEmpty()

	/**
	 * 🔴 Without OpenRegister there is nothing pending — and no exception.
	 *
	 * This runs on EVERY turn, so the absence is established by ASKING
	 * (`isInstalled`) rather than by reaching and catching: a per-turn
	 * exception is an expensive way to learn a fact that does not change, and
	 * an uncaught one would surface on a chat that merely opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function testPendingApprovalsAreEmptyWithoutOpenRegister(): void {
		$service = $this->service(
			catalog: [],
			existing: [
				'id' => 'req-1',
				'agentId' => 'agent-1',
				'toolId' => 'hermiq.sendMail',
				'status' => 'pending',
			],
			registerBroke: true
		);

		$this->assertSame(
			[],
			$service->pendingApprovals('agent-1'),
			'an absent OpenRegister means no approvals, not an error'
		);
	}//end testPendingApprovalsAreEmptyWithoutOpenRegister()
}//end class
