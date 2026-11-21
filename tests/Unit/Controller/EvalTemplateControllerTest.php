<?php

/**
 * Unit tests for EvalTemplateController (reuse-scheduled-bias-evals).
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\EvalTemplateController;
use OCA\Hermiq\Service\Eval\EvalOwnership;
use OCA\Hermiq\Service\Eval\EvalTemplateCopier;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Controller\EvalTemplateController
 * @uses   \OCA\Hermiq\Service\Eval\EvalTemplateCopier
 * @uses   \OCA\Hermiq\Service\Eval\EvalOwnership
 * @uses   \OCA\Hermiq\Service\SeedCustodyService
 */
class EvalTemplateControllerTest extends TestCase {

	/**
	 * The datasets saved through the object service double.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The controller for $uid (null: nobody signed in), over one dataset and one agent.
	 *
	 * @param string|null       $uid     The caller.
	 * @param ObjectEntity|null $dataset What the dataset id resolves to; null: not found.
	 * @param Agent|null        $agent   What the agent id resolves to; null: not found.
	 *
	 * @return EvalTemplateController
	 */
	private function controller(?string $uid, ?ObjectEntity $dataset, ?Agent $agent): EvalTemplateController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap([['agentId', '', 'agent-1']]);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$objects = $this->createMock(originalClassName: ObjectService::class);
		if ($dataset === null) {
			$objects->method('find')->willThrowException(new DoesNotExistException('no such object'));
		} else {
			$objects->method('find')->willReturn($dataset);
		}

		$objects->method('saveObject')->willReturnCallback(function (array $object): ObjectEntity {
			$this->saved[] = $object;
			$copy = new ObjectEntity();
			$copy->setUuid('copy-1');
			$copy->setObject($object);
			return $copy;
		});

		$agents = $this->createMock(originalClassName: AgentMapper::class);
		if ($agent === null) {
			$agents->method('findByUuid')->willThrowException(new DoesNotExistException('no such agent'));
		} else {
			$agents->method('findByUuid')->willReturn($agent);
		}

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new EvalTemplateController(
			request: $request,
			userSession: $session,
			copier: new EvalTemplateCopier(
				objectService: $objects,
				ownership: new EvalOwnership(objectService: $objects, agentMapper: $agents, seedCustody: new SeedCustodyService(groupManager: $groups))
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end controller()

	/**
	 * A seeded template.
	 *
	 * @param bool $template Whether it is a template.
	 *
	 * @return ObjectEntity
	 */
	private static function dataset(bool $template = true): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('tpl-1');
		$entity->setOwner('__system__');
		$entity->setObject(
			[
				'name'        => 'Bias check: public services (template)',
				'template'    => $template,
				'templateKey' => 'bias-public-services',
				'cases'       => [['prompt' => 'A', 'expectationType' => 'counterfactualPair', 'inputA' => 'A', 'inputB' => 'B', 'attribute' => 'name']],
			]
		);
		return $entity;
	}//end dataset()

	/**
	 * An agent owned by $owner.
	 *
	 * @param string $owner The owner.
	 *
	 * @return Agent
	 */
	private static function agent(string $owner): Agent {
		$agent = new Agent();
		$agent->setUuid('agent-1');
		$agent->setName('Permit intake helper');
		$agent->setOwner($owner);
		return $agent;
	}//end agent()

	/**
	 * The owner of an agent copies the template for it: 201, a linked, editable dataset.
	 *
	 * @return void
	 */
	public function testTheOwnerGetsAnEditableCopyForTheirAgent(): void {
		$response = $this->controller(uid: 'alice', dataset: self::dataset(), agent: self::agent(owner: 'alice'))->use('tpl-1');

		self::assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		self::assertSame(expected: ['id' => 'copy-1', 'name' => 'Bias check: public services — Permit intake helper'], actual: $response->getData());
		self::assertCount(expectedCount: 1, haystack: $this->saved);
		self::assertSame(expected: 'agent-1', actual: $this->saved[0]['agentId']);
		self::assertFalse(condition: $this->saved[0]['template']);
	}//end testTheOwnerGetsAnEditableCopyForTheirAgent()

	/**
	 * Someone else's agent is a 404, and nothing is stored.
	 *
	 * @return void
	 */
	public function testSomeoneElsesAgentIs404(): void {
		$response = $this->controller(uid: 'alice', dataset: self::dataset(), agent: self::agent(owner: 'bob'))->use('tpl-1');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->saved);
	}//end testSomeoneElsesAgentIs404()

	/**
	 * An agent that does not exist is the same 404.
	 *
	 * @return void
	 */
	public function testAMissingAgentIs404(): void {
		$response = $this->controller(uid: 'alice', dataset: self::dataset(), agent: null)->use('tpl-1');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testAMissingAgentIs404()

	/**
	 * A dataset that cannot be read is a 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableDatasetIs404(): void {
		$response = $this->controller(uid: 'alice', dataset: null, agent: self::agent(owner: 'alice'))->use('tpl-1');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testAnUnreadableDatasetIs404()

	/**
	 * A dataset that is not a template is a 400, and nothing is stored.
	 *
	 * @return void
	 */
	public function testANonTemplateIs400(): void {
		$response = $this->controller(uid: 'alice', dataset: self::dataset(template: false), agent: self::agent(owner: 'alice'))->use('tpl-1');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->saved);
	}//end testANonTemplateIs400()

	/**
	 * Nobody signed in is a 401.
	 *
	 * @return void
	 */
	public function testNobodySignedInIs401(): void {
		$response = $this->controller(uid: null, dataset: self::dataset(), agent: self::agent(owner: 'alice'))->use('tpl-1');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testNobodySignedInIs401()
}//end class
