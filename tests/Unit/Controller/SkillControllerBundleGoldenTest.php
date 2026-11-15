<?php

/**
 * Golden tests for SkillController's bundle endpoints, bundlePublish() and
 * bundleInstall().
 *
 * Pins every answer with its exact body before both are split into named
 * steps: the 401, each request refusal, the per-skill and per-agent outcomes
 * (published, not found, failed with its log line, dropped by the serialiser),
 * the arguments handed to the serialiser, the push, the catalog and the
 * installer, and the 502/404 failures with their log lines.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillBundleInstaller;
use OCA\Hermiq\Service\SkillBundleSerializer;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden answers of SkillController's bundle endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller's own collaborator set.
 */
class SkillControllerBundleGoldenTest extends TestCase {

	/**
	 * @var SkillService&MockObject
	 */
	private SkillService&MockObject $skills;

	/**
	 * @var SkillBundleSerializer&MockObject
	 */
	private SkillBundleSerializer&MockObject $serializer;

	/**
	 * @var GitHubTemplatePushService&MockObject
	 */
	private GitHubTemplatePushService&MockObject $push;

	/**
	 * @var GitHubTemplateCatalogService&MockObject
	 */
	private GitHubTemplateCatalogService&MockObject $catalog;

	/**
	 * @var SkillBundleInstaller&MockObject
	 */
	private SkillBundleInstaller&MockObject $installer;

	/**
	 * @var AgentAccessService&MockObject
	 */
	private AgentAccessService&MockObject $agents;

	/**
	 * Every logged error line.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * The controller for `alice` (or nobody) over the given request params.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return SkillController
	 */
	private function controller(array $params, bool $signedIn = true): SkillController {
		$this->errors = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) === true ? $params[$key] : $default
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		$this->skills = $this->createMock(SkillService::class);
		$this->serializer = $this->createMock(SkillBundleSerializer::class);
		$this->push = $this->createMock(GitHubTemplatePushService::class);
		$this->catalog = $this->createMock(GitHubTemplateCatalogService::class);
		$this->installer = $this->createMock(SkillBundleInstaller::class);
		$this->agents = $this->createMock(AgentAccessService::class);

		return new SkillController(
			$request,
			$this->skills,
			$session,
			$logger,
			$this->catalog,
			$this->createMock(SkillMarketplaceService::class),
			$this->serializer,
			$this->push,
			$this->installer,
			new SeedCustodyService($this->createMock(IGroupManager::class)),
			$this->agents
		);
	}//end controller()

	/**
	 * An entity with the given data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-x');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Assert status and body.
	 *
	 * @param JSONResponse $response The response.
	 * @param int $status The status.
	 * @param mixed $body The body.
	 *
	 * @return void
	 */
	private function answer(JSONResponse $response, int $status, mixed $body): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end answer()

	/**
	 * Both endpoints answer 401 to nobody.
	 *
	 * @return void
	 */
	public function testNobodyIs401(): void {
		$this->answer($this->controller(['owner' => 'acme'], signedIn: false)->bundlePublish(), Http::STATUS_UNAUTHORIZED, ['error' => 'Unauthenticated']);
		$this->answer($this->controller(['owner' => 'acme'], signedIn: false)->bundleInstall(), Http::STATUS_UNAUTHORIZED, ['error' => 'Unauthenticated']);
	}//end testNobodyIs401()

	/**
	 * Each bundlePublish request refusal.
	 *
	 * @return void
	 */
	public function testPublishRequestRefusals(): void {
		$cases = [
			[['owner' => 'a b', 'repo' => 'r', 'skillIds' => ['s']], 'invalid_repo'],
			[['owner' => 'acme', 'skillIds' => ['s']], 'invalid_repo'],
			[['owner' => 'acme', 'repo' => 'r', 'skillIds' => 'x', 'agentIds' => []], 'skillIds or agentIds must be a non-empty array'],
			[['owner' => 'acme', 'repo' => 'r'], 'skillIds or agentIds must be a non-empty array'],
			[['owner' => 'acme', 'repo' => 'r', 'skillIds' => ['s'], 'visibility' => 'internal'], 'invalid_visibility'],
		];
		foreach ($cases as [$params, $code]) {
			$controller = $this->controller($params);
			$this->skills->expects($this->never())->method('getSkill');
			$this->answer($controller->bundlePublish(), Http::STATUS_BAD_REQUEST, ['error' => $code]);
		}
	}//end testPublishRequestRefusals()

	/**
	 * Nothing publishable is a 400 that reports every skill's and agent's outcome.
	 *
	 * @return void
	 */
	public function testNothingPublishable(): void {
		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r', 'skillIds' => ['gone', 'broken', 'nul'], 'agentIds' => ['a-gone', 'a-broken']]);
		$this->skills->method('getSkill')->willReturnCallback(
			static function (string $skillId) {
				if ($skillId === 'gone') {
					throw new DoesNotExistException('no');
				}

				if ($skillId === 'broken') {
					throw new RuntimeException('db down');
				}

				return null;
			}
		);
		$this->agents->method('loadAccessibleAgent')->willReturnCallback(
			static function (string $agentId, string $userId) {
				if ($agentId === 'a-broken') {
					throw new RuntimeException('agents down');
				}

				return null;
			}
		);
		$this->push->expects($this->never())->method('publishBundle');

		$this->answer(
			$controller->bundlePublish(),
			Http::STATUS_BAD_REQUEST,
			[
				'error' => 'no_publishable_content',
				'skills' => [['name' => 'gone', 'outcome' => 'not_found'], ['name' => 'broken', 'outcome' => 'failed'], ['name' => 'nul', 'outcome' => 'not_found']],
				'agents' => [['name' => 'a-gone', 'outcome' => 'not_found'], ['name' => 'a-broken', 'outcome' => 'failed']],
			]
		);
		$this->assertSame(
			['Hermiq bundle publish: resolving skill "broken" failed: db down', 'Hermiq bundle publish: resolving agent "a-broken" failed: agents down'],
			$this->errors
		);
	}//end testNothingPublishable()

	/**
	 * A publish serialises what resolved, pushes it, and reports what was serialised.
	 *
	 * @return void
	 */
	public function testPublishReportsWhatWasSerialised(): void {
		$controller = $this->controller(
			['owner' => 'acme', 'repo' => 'bundle', 'skillIds' => ['s1', 's2', 7], 'agentIds' => ['a1', 'a2'], 'visibility' => 'public', 'credentialId' => 'cred-1']
		);
		$this->skills->method('getSkill')->willReturnCallback(fn (string $id) => $id === '7' ? null : $this->entity(['name' => 'skill-' . $id]));
		$this->skills->method('publishFileSelection')->willReturnCallback(static fn (string $id) => $id === 's1' ? [['name' => 'f.md']] : null);
		$this->agents->method('loadAccessibleAgent')->willReturnCallback(fn (string $id, string $uid) => $this->entity(['name' => 'agent-' . $id . '-' . $uid]));

		$seen = [];
		$this->serializer->method('toBundle')->willReturnCallback(
			function (array $skills, ?array &$dropped = null, array $agents = [], ?array &$droppedAgents = null) use (&$seen): array {
				$seen = [$skills, $agents];
				$dropped = [['name' => 'skill-s2', 'reason' => 'cap_reached']];
				$droppedAgents = [['name' => 'agent-a2-alice']];
				return ['tree' => 'x'];
			}
		);
		$this->push->expects($this->once())->method('publishBundle')
			->with(['tree' => 'x'], 'acme', 'bundle', 'public', 'cred-1', 'alice')
			->willReturn(['repoUrl' => 'u', 'commitSha' => 'c', 'created' => false]);

		$this->answer(
			$controller->bundlePublish(),
			Http::STATUS_OK,
			[
				'repoUrl' => 'u',
				'commitSha' => 'c',
				'created' => false,
				'published' => 1,
				'dropped' => 1,
				'truncated' => true,
				'skills' => [
					['name' => 'skill-s1', 'files' => 1, 'outcome' => 'published'],
					['name' => 'skill-s2', 'files' => 0, 'outcome' => 'dropped', 'reason' => 'cap_reached'],
					['name' => '7', 'outcome' => 'not_found'],
				],
				'agentsPublished' => 1,
				'agentsDropped' => 1,
				'agents' => [
					['name' => 'agent-a1-alice', 'outcome' => 'published'],
					['name' => 'agent-a2-alice', 'outcome' => 'dropped', 'reason' => 'dropped'],
				],
			]
		);
		$this->assertSame(
			[
				[['id' => 'uuid-x', 'name' => 'skill-s1', 'files' => [['name' => 'f.md']]], ['id' => 'uuid-x', 'name' => 'skill-s2', 'files' => []]],
				[['id' => 'uuid-x', 'name' => 'agent-a1-alice'], ['id' => 'uuid-x', 'name' => 'agent-a2-alice']],
			],
			$seen
		);
	}//end testPublishReportsWhatWasSerialised()

	/**
	 * A clean publish with defaults, and a failing serialiser or push.
	 *
	 * @return void
	 */
	public function testPublishDefaultsAndFailures(): void {
		$controller = $this->controller(['owner' => 'acme', 'repo' => 'bundle', 'agentIds' => ['a1'], 'credentialId' => '']);
		$this->agents->method('loadAccessibleAgent')->willReturn($this->entity(['name' => 'ag']));
		$this->serializer->method('toBundle')->willReturn(['t' => '1']);
		$this->push->expects($this->once())->method('publishBundle')->with(['t' => '1'], 'acme', 'bundle', 'private', '', 'alice')
			->willReturn(['repoUrl' => 'u', 'commitSha' => 'c', 'created' => true]);
		$data = $controller->bundlePublish()->getData();
		$this->assertSame([0, 0, false, [], 1, 0], [$data['published'], $data['dropped'], $data['truncated'], $data['skills'], $data['agentsPublished'], $data['agentsDropped']]);

		foreach (['serializer', 'push'] as $failing) {
			$controller = $this->controller(['owner' => 'acme', 'repo' => 'bundle', 'agentIds' => ['a1']]);
			$this->agents->method('loadAccessibleAgent')->willReturn($this->entity(['name' => 'ag']));
			if ($failing === 'serializer') {
				$this->serializer->method('toBundle')->willThrowException(new RuntimeException('too big'));
				$this->push->expects($this->never())->method('publishBundle');
			} else {
				$this->serializer->method('toBundle')->willReturn([]);
				$this->push->method('publishBundle')->willThrowException(new RuntimeException('too big'));
			}

			$this->answer($controller->bundlePublish(), Http::STATUS_BAD_GATEWAY, ['error' => 'publish_failed']);
			$this->assertSame(['Hermiq skill bundle publish failed: too big'], $this->errors, $failing);
		}
	}//end testPublishDefaultsAndFailures()

	/**
	 * Each bundleInstall refusal and failure.
	 *
	 * @return void
	 */
	public function testInstallRefusalsAndFailures(): void {
		foreach ([[['owner' => 'acme', 'repo' => 'r r'], 'invalid_repo'], [['owner' => 'acme', 'repo' => 'r', 'ref' => 'a b'], 'invalid_ref']] as [$params, $code]) {
			$controller = $this->controller($params);
			$this->catalog->expects($this->never())->method('fetchBundle');
			$this->answer($controller->bundleInstall(), Http::STATUS_BAD_REQUEST, ['error' => $code]);
		}

		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r', 'ref' => 7, 'credentialId' => 'cred-1']);
		$this->catalog->expects($this->once())->method('fetchBundle')->with('acme', 'r', null, 'alice', 'cred-1')->willThrowException(new RuntimeException('rate limited'));
		$this->answer($controller->bundleInstall(), Http::STATUS_BAD_GATEWAY, ['error' => 'fetch_failed']);
		$this->assertSame(['Hermiq skill bundle fetch failed: rate limited'], $this->errors);

		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r', 'ref' => '']);
		$this->catalog->expects($this->once())->method('fetchBundle')->with('acme', 'r', null, 'alice', null)->willReturn(null);
		$this->answer($controller->bundleInstall(), Http::STATUS_NOT_FOUND, ['error' => 'not_a_bundle']);

		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r', 'ref' => 'v1.0/x']);
		$this->catalog->expects($this->once())->method('fetchBundle')->with('acme', 'r', 'v1.0/x', 'alice', null)->willReturn(['files' => ['a' => 'b'], 'truncated' => false]);
		$this->serializer->method('fromBundle')->willThrowException(new RuntimeException('no manifest'));
		$this->installer->expects($this->never())->method('installParsed');
		$this->answer($controller->bundleInstall(), Http::STATUS_NOT_FOUND, ['error' => 'not_a_bundle']);
		$this->assertSame(['Hermiq bundle install: parse failed: no manifest'], $this->errors);
	}//end testInstallRefusalsAndFailures()

	/**
	 * A bundle install installs the skills and then the agents, and reports both.
	 *
	 * @return void
	 */
	public function testInstall(): void {
		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r']);
		$this->catalog->method('fetchBundle')->willReturn(['files' => ['f' => 'x'], 'truncated' => true]);
		$this->serializer->method('fromBundle')->with(['f' => 'x'])->willReturn(['parsed' => 1]);
		$this->serializer->method('agentsFromBundle')->with(['f' => 'x'])->willReturn(['agents' => 1]);
		$order = [];
		$this->installer->expects($this->once())->method('installParsed')->with(['parsed' => 1], 'alice', 'acme', 'r')->willReturnCallback(
			function () use (&$order): array {
				$order[] = 'skills';
				return ['counts' => ['installed' => 1, 'updated' => 2, 'unchanged' => 3, 'skipped' => 4, 'failed' => 5], 'outcomes' => ['so']];
			}
		);
		$this->installer->expects($this->once())->method('installAgents')->with(['agents' => 1])->willReturnCallback(
			function () use (&$order): array {
				$order[] = 'agents';
				return ['outcomes' => ['ao']];
			}
		);

		$this->answer(
			$controller->bundleInstall(),
			Http::STATUS_OK,
			['installed' => 1, 'updated' => 2, 'unchanged' => 3, 'skipped' => 4, 'failed' => 5, 'truncated' => true, 'skills' => ['so'], 'agents' => ['ao']]
		);
		$this->assertSame(['skills', 'agents'], $order);
	}//end testInstall()
}//end class
