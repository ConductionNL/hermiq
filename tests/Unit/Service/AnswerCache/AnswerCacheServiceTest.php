<?php

/**
 * Unit tests for AnswerCacheService: eligibility, the key, the re-checked
 * lookup and the store.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AnswerCache
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
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AnswerCache;

use LLPhant\Chat\Message;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\AnswerCache\AnswerCacheService
 */
class AnswerCacheServiceTest extends TestCase {

	/** 2026-11-14T12:00:00Z */
	private const NOW = 1794657600;

	private ObjectService&MockObject $objects;

	private OrganisationModelPolicyService&MockObject $policy;

	private AnswerCacheService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->policy = $this->createMock(OrganisationModelPolicyService::class);
		$this->policy->method('isAllowed')->willReturn(true);
		$versions = $this->createMock(AgentVersionService::class);
		$versions->method('currentVersionId')->willReturn('version-3');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new AnswerCacheService($this->objects, $versions, $this->policy, $session, $time, new NullLogger());
	}

	/**
	 * An eligible turn in organisation org-a: the cache on, no tools, built messages.
	 *
	 * @param array<string, mixed> $agentData Overrides of the agent's data.
	 * @param string               $question  The new message.
	 */
	private function turn(array $agentData = [], string $question = 'Wanneer wordt het oud papier opgehaald?'): ResponseTurnRun {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject($agentData + ['prompt' => 'P', 'temperature' => 0.2, 'answerCache' => ['enabled' => true, 'ttlMinutes' => 10080]]);
		$run = new ResponseTurnRun($question, []);
		$run->agent = $agent;
		$run->progress->organisation = 'org-a';
		$run->progress->driver = new ChatDriver(provider: 'ollama', chat: null, model: 'qwen2.5', credentialId: 'c', baseUrl: 'https://x');
		$run->messageHistory = [Message::system('P'), Message::user($question)];

		return $run;
	}

	/**
	 * A stored entry for the given turn.
	 *
	 * @param array<string, mixed> $overrides Overrides of the entry's data.
	 */
	private function entry(ResponseTurnRun $run, array $overrides = [], string $organisation = 'org-a'): ObjectEntity {
		$entry = new ObjectEntity();
		$entry->setUuid('entry-1');
		$entry->setOrganisation($organisation);
		$entry->setObject($overrides + [
			'agentId' => 'agent-1',
			'agentVersionId' => 'version-3',
			'keyHash' => $this->service->key($run, 'version-3'),
			'answer' => 'Elke tweede dinsdag.',
			'provider' => 'ollama',
			'model' => 'qwen2.5',
			'expiresAt' => '2026-11-20T12:00:00+00:00',
			'hitCount' => 2,
		]);

		return $entry;
	}

	public function testOnlyAToolFreeTurnOfAnAgentWithTheCacheOnIsEligible(): void {
		$this->assertTrue($this->service->isEligible($this->turn()));
		$this->assertFalse($this->service->isEligible($this->turn(['answerCache' => ['enabled' => false]])));
		$this->assertFalse($this->service->isEligible($this->turn(['answerCache' => 'on'])));

		$tools = $this->turn();
		$tools->progress->functions = [['name' => 'hermiq.listCalendarEvents']];
		$this->assertFalse($this->service->isEligible($tools));

		$dry = $this->turn();
		$dry->dryRun = true;
		$this->assertFalse($this->service->isEligible($dry));

		$attached = $this->turn();
		$attached->attachments = [['fileId' => 1]];
		$this->assertFalse($this->service->isEligible($attached));

		$again = $this->turn();
		$again->answerCacheBypass = true;
		$this->assertFalse($this->service->isEligible($again));

		$noAgent = $this->turn();
		$noAgent->agent = null;
		$this->assertFalse($this->service->isEligible($noAgent));
	}

	public function testTheKeyCoversOrganisationVersionModelTemperatureAndTheWholeInput(): void {
		$base = $this->service->key($this->turn(), 'version-3');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);
		$this->assertSame($base, $this->service->key($this->turn(), 'version-3'));

		$otherOrganisation = $this->turn();
		$otherOrganisation->progress->organisation = 'org-b';
		$otherModel = $this->turn();
		$otherModel->progress->driver = new ChatDriver(provider: 'ollama', chat: null, model: 'llama3', credentialId: 'c', baseUrl: 'https://x');
		$otherContext = $this->turn();
		$otherContext->messageHistory = [Message::system('P'), Message::system('CONTEXT: another document'), Message::user('Wanneer wordt het oud papier opgehaald?')];

		foreach ([
			$this->service->key($otherOrganisation, 'version-3'),
			$this->service->key($this->turn(), 'version-4'),
			$this->service->key($otherModel, 'version-3'),
			$this->service->key($this->turn(['temperature' => 0.9]), 'version-3'),
			$this->service->key($otherContext, 'version-3'),
		] as $other) {
			$this->assertNotSame($base, $other);
		}
	}

	public function testAnExactRepeatIsServedAndItsHitCounted(): void {
		$run = $this->turn();
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['agentId' => 'agent-1', 'keyHash' => $this->service->key($run, 'version-3')], 'limit' => 10], false)
			->willReturn([$this->entry($run)]);
		$this->objects->expects($this->once())->method('saveObject')->with(
			$this->callback(static fn (array $data): bool => $data['hitCount'] === 3 && $data['lastHitAt'] === '2026-11-14T12:00:00+00:00'),
			$this->callback(static fn (ObjectSaveOptions $options): bool => $options->target->register === 'hermiq'
				&& $options->target->schema === 'agentanswercache' && $options->uuid === 'entry-1'),
			false
		);

		$this->assertSame(['answer' => 'Elke tweede dinsdag.', 'entryId' => 'entry-1', 'layer' => 'exact'], $this->service->lookup($run));
	}

	public function testAnEntryOfAnotherOrganisationIsNeverServed(): void {
		$run = $this->turn();
		$this->objects->method('findAll')->willReturn([$this->entry($run, [], 'org-b')]);
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertNull($this->service->lookup($run));
	}

	public function testAnExpiredEntryOrOneOfAnotherVersionIsNotServed(): void {
		$run = $this->turn();
		$this->objects->method('findAll')->willReturn([
			$this->entry($run, ['expiresAt' => '2026-11-14T11:59:59+00:00']),
			$this->entry($run, ['agentVersionId' => 'version-2']),
			$this->entry($run, ['expiresAt' => 'not a date']),
		]);

		$this->assertNull($this->service->lookup($run));
	}

	public function testAnEntryThePolicyNoLongerAllowsIsDeletedAndTheModelCalled(): void {
		$policy = $this->createMock(OrganisationModelPolicyService::class);
		$policy->expects($this->once())->method('isAllowed')->with('org-a', 'ollama', 'qwen2.5')->willReturn(false);
		$versions = $this->createMock(AgentVersionService::class);
		$versions->method('currentVersionId')->willReturn('version-3');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$service = new AnswerCacheService($this->objects, $versions, $policy, $this->createMock(IUserSession::class), $time, new NullLogger());
		$run = $this->turn();
		$this->objects->method('findAll')->willReturn([$this->entry($run)]);
		$this->objects->expects($this->once())->method('deleteObject')->with(
			$this->callback(static fn (ObjectDeletion $deletion): bool => $deletion->uuid === 'entry-1'
				&& $deletion->target->register === 'hermiq' && $deletion->target->schema === 'agentanswercache'),
			false
		)->willReturn(true);

		$this->assertNull($service->lookup($run));
	}

	public function testAFailingReadIsAMissAndAnIneligibleTurnReadsNothing(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->assertNull($this->service->lookup($this->turn()));

		$tools = $this->turn();
		$tools->progress->functions = [['name' => 'x']];
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('findAll');
		$this->assertNull($this->service->lookup($tools));
	}

	public function testTheAnswerIsStoredWithTheAgentsLifetimeAndNoPrompt(): void {
		$run = $this->turn();
		$this->objects->expects($this->once())->method('saveObject')->with(
			[
				'agentId' => 'agent-1',
				'agentVersionId' => 'version-3',
				'keyHash' => $this->service->key($run, 'version-3'),
				'answer' => 'Elke tweede dinsdag.',
				'provider' => 'ollama',
				'model' => 'qwen2.5',
				'userId' => 'alice',
				'createdAt' => '2026-11-14T12:00:00+00:00',
				'expiresAt' => '2026-11-21T12:00:00+00:00',
				'hitCount' => 0,
			],
			$this->callback(static fn (ObjectSaveOptions $options): bool => $options->target->register === 'hermiq'
				&& $options->target->schema === 'agentanswercache' && $options->uuid === null),
			false
		);

		$this->service->store($run, 'Elke tweede dinsdag.');
	}

	public function testNothingIsStoredForAnEmptyAnswerOrAnIneligibleTurn(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$this->service->store($this->turn(), '  ');
		$dry = $this->turn();
		$dry->dryRun = true;
		$this->service->store($dry, 'An answer');
	}

	public function testThePurgeDeletesOnlyExpiredEntriesOfEveryPage(): void {
		$entry = static function (string $uuid, string $expires): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setObject(['expiresAt' => $expires]);
			return $object;
		};
		$firstPage = [];
		for ($i = 0; $i < 500; $i++) {
			$firstPage[] = $entry('live-' . $i, '2026-11-15T00:00:00+00:00');
		}
		$firstPage[0] = $entry('old-1', '2026-11-01T00:00:00+00:00');
		$this->objects->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			static function (array $config, bool $rbac, bool $multitenancy) use ($firstPage, $entry): array {
				self::assertFalse($rbac);
				self::assertFalse($multitenancy);
				return $config['offset'] === 0 ? $firstPage : [$entry('old-2', '2026-11-14T11:00:00+00:00'), $entry('live-x', '2026-12-01T00:00:00+00:00')];
			}
		);
		$deleted = [];
		$this->objects->method('deleteObject')->willReturnCallback(
			static function (ObjectDeletion $deletion) use (&$deleted): bool {
				$uuid      = $deletion->uuid;
				$deleted[] = $uuid;
				return $uuid !== 'old-2';
			}
		);

		$this->assertSame(1, $this->service->purgeExpired());
		$this->assertSame(['old-1', 'old-2'], $deleted);
	}

	public function testALifetimeOutOfBoundsIsClamped(): void {
		$this->objects->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			function (array $data) {
				static $expected = ['2026-12-14T12:00:00+00:00', '2026-11-15T12:00:00+00:00'];
				$this->assertSame(array_shift($expected), $data['expiresAt']);
				return new ObjectEntity();
			}
		);

		$this->service->store($this->turn(['answerCache' => ['enabled' => true, 'ttlMinutes' => 999999]]), 'A');
		$this->service->store($this->turn(['answerCache' => ['enabled' => true, 'ttlMinutes' => 0]]), 'B');
	}

	public function testClearingAnAgentRemovesOnlyItsOwnEntries(): void {
		$entry = static function (string $uuid, string $agentId): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setObject(['agentId' => $agentId]);
			return $object;
		};
		$this->objects->expects($this->once())->method('findAll')->willReturnCallback(
			static function (array $config, bool $rbac, bool $multitenancy) use ($entry): array {
				self::assertSame(['agentId' => 'agent-1'], $config['filters']);
				self::assertFalse($rbac);
				self::assertFalse($multitenancy);
				return [$entry('e-1', 'agent-1'), $entry('e-2', 'agent-2'), $entry('e-3', 'agent-1')];
			}
		);
		$deleted = [];
		$this->objects->method('deleteObject')->willReturnCallback(
			static function (ObjectDeletion $deletion) use (&$deleted): bool {
				$uuid      = $deletion->uuid;
				$deleted[] = $uuid;
				return true;
			}
		);

		$this->assertSame(2, $this->service->clearForAgent('agent-1'));
		$this->assertSame(['e-1', 'e-3'], $deleted);
	}

	public function testClearingWithoutAnAgentTouchesNothing(): void {
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame(0, $this->service->clearForAgent(' '));
	}
}
