<?php

/**
 * AgentsController keeps an agent's reference models inside the model policy.
 *
 * @category Tests
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
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentsController;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentCatalogInterface;
use OCA\Hermiq\Service\Agent\ReferenceModelSettings;
use OCA\Hermiq\Service\EffectiveModelPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Saving an agent with an ensemble.
 */
#[CoversClass(AgentsController::class)]
#[UsesClass(ReferenceModelSettings::class)]
final class AgentsControllerEnsembleTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The organisations the policy was asked about, in order.
	 *
	 * @var array<int, string>
	 */
	private array $askedFor = [];

	/**
	 * Build the doubles: alice in org-1, whose policy allows only ollama.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(originalClassName: ObjectService::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);
	}//end setUp()

	/**
	 * The controller over the doubles.
	 *
	 * @return AgentsController
	 */
	private function controller(): AgentsController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$policies = $this->createMock(originalClassName: EffectiveModelPolicy::class);
		$policies->method('isAllowed')->willReturnCallback(
			function (string $organisation, string $provider, string $model): bool {
				$this->askedFor[] = $organisation;
				return $provider === 'ollama';
			}
		);
		$organisations = $this->createMock(originalClassName: OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');

		$access = $this->createMock(originalClassName: AgentAccessServiceInterface::class);
		$access->method('canUserModifyAgent')->willReturn(true);

		return new AgentsController(
			request: $this->request,
			objectService: $this->objectService,
			toolRegistry: $this->createMock(originalClassName: ToolRegistryFacade::class),
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			agentAccess: $access,
			catalog: $this->createMock(originalClassName: AgentCatalogInterface::class),
			referenceModels: new ReferenceModelSettings(policies: $policies, organisations: $organisations),
		);
	}//end controller()

	/**
	 * A stored agent of org-9.
	 *
	 * @return ObjectEntity
	 */
	private function storedAgent(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOwner('alice');
		$entity->setOrganisation('org-9');
		$entity->setObject(['name' => 'Beleidsadviseur']);
		return $entity;
	}//end storedAgent()

	/**
	 * Two allowed reference models are saved, normalised, on a new agent.
	 *
	 * @return void
	 */
	public function testANewAgentSavesItsAllowedReferenceModels(): void {
		$this->request->method('getParams')->willReturn(
			[
				'name' => 'Beleidsadviseur',
				'ensemble' => [
					'enabled' => true,
					'referenceModels' => [
						['provider' => 'ollama', 'model' => 'qwen2.5'],
						['provider' => 'ollama', 'model' => 'llama3.1', 'note' => 'dropped'],
					],
				],
			]
		);
		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('agent-new');
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller()->create();

		self::assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		self::assertSame(
			expected: [
				'enabled' => true,
				'referenceModels' => [
					['provider' => 'ollama', 'model' => 'qwen2.5'],
					['provider' => 'ollama', 'model' => 'llama3.1'],
				],
			],
			actual: $saved['ensemble']
		);
		self::assertSame(expected: ['org-1', 'org-1'], actual: $this->askedFor);
	}//end testANewAgentSavesItsAllowedReferenceModels()

	/**
	 * A new agent naming a model outside the policy is refused and not saved.
	 *
	 * @return void
	 */
	public function testANewAgentWithAModelOutsideThePolicyIsRefused(): void {
		$this->request->method('getParams')->willReturn(
			[
				'name' => 'Beleidsadviseur',
				'ensemble' => [
					'enabled' => true,
					'referenceModels' => [
						['provider' => 'ollama', 'model' => 'qwen2.5'],
						['provider' => 'openai', 'model' => 'gpt-4o'],
					],
				],
			]
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->create();

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: ['error' => ReferenceModelSettings::NOT_ALLOWED], actual: $response->getData());
	}//end testANewAgentWithAModelOutsideThePolicyIsRefused()

	/**
	 * An update naming a model outside the agent's own organisation's policy
	 * is refused and leaves the agent unchanged.
	 *
	 * @return void
	 */
	public function testAnUpdateWithAModelOutsideThePolicyLeavesTheAgentUnchanged(): void {
		$this->request->method('getParams')->willReturn(
			[
				'ensemble' => [
					'enabled' => true,
					'referenceModels' => [
						['provider' => 'openai', 'model' => 'gpt-4o'],
						['provider' => 'ollama', 'model' => 'qwen2.5'],
					],
				],
			]
		);
		$this->objectService->method('find')->willReturn($this->storedAgent());
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->update(id: 'agent-1');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: ['error' => ReferenceModelSettings::NOT_ALLOWED], actual: $response->getData());
		self::assertSame(expected: ['org-9'], actual: $this->askedFor);
	}//end testAnUpdateWithAModelOutsideThePolicyLeavesTheAgentUnchanged()

	/**
	 * An update without `ensemble` does not touch it or ask the policy.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnEnsembleKeepsTheStoredOne(): void {
		$stored = $this->storedAgent();
		$stored->setObject(
			[
				'name' => 'Beleidsadviseur',
				'ensemble' => ['enabled' => true, 'referenceModels' => [['provider' => 'openai', 'model' => 'gpt-4o']]],
			]
		);
		$this->request->method('getParams')->willReturn(['name' => 'Adviseur']);
		$this->objectService->method('find')->willReturn($stored);
		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('agent-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller()->update(id: 'agent-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'Adviseur', actual: $saved['name']);
		self::assertSame(expected: $stored->getObject()['ensemble'], actual: $saved['ensemble']);
		self::assertSame(expected: [], actual: $this->askedFor);
	}//end testAnUpdateWithoutAnEnsembleKeepsTheStoredOne()
}//end class
