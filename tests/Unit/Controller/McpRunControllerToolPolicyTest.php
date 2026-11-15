<?php

/**
 * A cli turn's tool calls follow the tool policy of the agent's organisation.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\McpRunController;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The organisation OpenRegister stores on the agent decides the cli tool policy.
 *
 * @covers \OCA\Hermiq\Controller\McpRunController
 * @uses   \OCA\Hermiq\Service\Engine\ToolLoop
 * @uses   \OCA\Hermiq\Service\Engine\FacadeToolInvoker
 * @uses   \OCA\Hermiq\Service\Engine\TurnGuard
 * @uses   \OCA\Hermiq\Service\Engine\RunStepBus
 * @uses   \OCA\Hermiq\Service\Agent\AgentAvailability
 * @uses   \OCA\Hermiq\Service\ToolSearchService
 * @uses   \OCA\Hermiq\Service\Workspace\RepoEffectingGrants
 */
final class McpRunControllerToolPolicyTest extends TestCase {

	private const TOOL = 'openregister_contact_search';

	/**
	 * The search tool descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private function descriptor(): array {
		return [
			'name' => self::TOOL,
			'mcpId' => 'openregister.contact.search',
			'description' => 'Search contacts',
			'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
		];
	}//end descriptor()

	/**
	 * An agent as OpenRegister stores it: the organisation on the entity, not in
	 * the object data.
	 *
	 * @param string $organisation The agent's organisation.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $organisation): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['tools' => ['openregister.contact.*']]);
		$agent->setOrganisation($organisation);

		return $agent;
	}//end agent()

	/**
	 * A guardrail service whose policy for `org-1` gives the search tool the
	 * given classification, and whose policy for every other organisation
	 * classifies nothing.
	 *
	 * @param string $classification The search tool's classification in org-1.
	 *
	 * @return GuardrailPolicyService
	 */
	private function guardrail(string $classification): GuardrailPolicyService {
		$service = $this->createMock(GuardrailPolicyService::class);
		$service->method('effectivePolicyFor')->willReturnCallback(
			static fn (string $organisation): array => [
				'source' => 'organisation',
				'inputFilters' => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
				'outputFilters' => ['piiAction' => 'off'],
				'toolPolicy' => ($organisation === 'org-1')
					? [['toolId' => 'openregister.contact.search', 'classification' => $classification]]
					: [],
				'enabled' => true,
			]
		);

		return $service;
	}//end guardrail()

	/**
	 * One cli tools/call for the agent, through the given tool loop.
	 *
	 * @param ObjectEntity $agent    The agent the run token names.
	 * @param ToolLoop     $toolLoop The tool loop.
	 * @param ToolRegistryFacade $facade The tool facade.
	 *
	 * @return array<string, mixed> The JSON-RPC response data.
	 */
	private function cliCall(ObjectEntity $agent, ToolLoop $toolLoop, ToolRegistryFacade $facade): array {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->method('verify')->willReturn(['runId' => 'r', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => 'c1']);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agent);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization') ? 'Bearer good' : '');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$body = '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"' . self::TOOL . '","arguments":{"q":"x"}}}';
		$controller = new class($request, $tokens, $objects, $facade, new ToolGrantResolver(), $toolLoop, new ToolSearchService(), $userManager, $this->createMock(IUserSession::class), $this->createMock(IThrottler::class), new RunStepBus($this->createMock(ICacheFactory::class)), new NullLogger(), $body) extends McpRunController {
			// phpcs:ignore
			public function __construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $bus, $logger, private string $rawBody) {
				parent::__construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $bus, $logger);
			}

			// phpcs:ignore
			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};

		return $controller->handle()->getData();
	}//end cliCall()

	/**
	 * A real tool loop over the facade, with the guardrail service.
	 *
	 * @param ToolRegistryFacade $facade         The tool facade.
	 * @param string             $classification The search tool's classification in org-1.
	 *
	 * @return ToolLoop
	 */
	private function toolLoop(ToolRegistryFacade $facade, string $classification='deny'): ToolLoop {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(30);

		return new ToolLoop(
			$facade,
			new NullLogger(),
			new ToolGrantResolver(),
			new ToolSearchService(),
			$this->createMock(ApprovalService::class),
			$appConfig,
			$this->guardrail(classification: $classification)
		);
	}//end toolLoop()

	/**
	 * The tools of a cli call are built for the organisation stored on the agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
	 */
	public function testToolsAreBuiltForTheAgentsOrganisation(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([$this->descriptor()]);
		$organisations = [];
		$toolLoop = $this->createMock(ToolLoop::class);
		$toolLoop->method('buildFunctionInfos')->willReturnCallback(
			static function (array $functions, mixed $channel, mixed $trace, mixed $agent, ?string $organisation) use (&$organisations): array {
				$organisations[] = $organisation;
				return [];
			}
		);

		$this->cliCall(agent: $this->agent(organisation: 'org-1'), toolLoop: $toolLoop, facade: $facade);

		$this->assertSame(['org-1'], $organisations);
	}//end testToolsAreBuiltForTheAgentsOrganisation()

	/**
	 * A tool the agent's organisation denies is refused on a cli turn and never runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
	 */
	public function testADeniedToolIsRefusedOnACliTurn(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([$this->descriptor()]);
		$facade->expects($this->never())->method('invokeTool');

		$data = $this->cliCall(agent: $this->agent(organisation: 'org-1'), toolLoop: $this->toolLoop(facade: $facade), facade: $facade);

		$this->assertTrue($data['result']['isError']);
		$this->assertStringContainsString('tool_denied_by_policy', (string)json_encode($data['result']));
	}//end testADeniedToolIsRefusedOnACliTurn()

	/**
	 * A tool the agent's organisation classifies `confirm` waits for approval on
	 * a cli turn and does not run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
	 */
	public function testAConfirmToolAwaitsApprovalOnACliTurn(): void {
		$facade = $this->createMock(originalClassName: ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([$this->descriptor()]);
		$facade->expects($this->never())->method('invokeTool');

		$data = $this->cliCall(
			agent: $this->agent(organisation: 'org-1'),
			toolLoop: $this->toolLoop(facade: $facade, classification: 'confirm'),
			facade: $facade
		);

		$this->assertTrue(condition: $data['result']['isError']);
		$this->assertStringContainsString(needle: 'approval_required', haystack: (string)json_encode(value: $data['result']));
	}//end testAConfirmToolAwaitsApprovalOnACliTurn()

	/**
	 * An agent of an organisation that classifies nothing runs the tool.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
	 */
	public function testAnUnclassifiedToolRunsOnACliTurn(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([$this->descriptor()]);
		$facade->expects($this->once())->method('invokeTool')->willReturn(['result' => ['n' => 1], 'isError' => false]);

		$data = $this->cliCall(agent: $this->agent(organisation: 'org-2'), toolLoop: $this->toolLoop(facade: $facade), facade: $facade);

		$this->assertFalse($data['result']['isError']);
	}//end testAnUnclassifiedToolRunsOnACliTurn()
}//end class
