<?php

/**
 * Unit tests for the answer cache's similarity layer: similar first questions
 * matched through OpenRegister's vector facade, every row re-checked in hermiq.
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
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AnswerCache;

use LLPhant\Chat\Message;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCA\Hermiq\Service\AnswerCache\SimilarQuestionSearch;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\AnswerCache\AnswerCacheService
 */
class AnswerCacheSimilarityTest extends TestCase {

	/** 2026-11-14T12:00:00Z */
	private const NOW = 1794657600;

	private const QUESTION = 'Wanneer halen jullie oud papier op?';

	private const REDACTED = '[redacted] Wanneer halen jullie oud papier op?';

	private ObjectService&MockObject $objects;

	private OrganisationModelPolicyService&MockObject $policy;

	private SimilarQuestionSearch&MockObject $similar;

	private AnswerCacheService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([]);
		$this->policy = $this->createMock(OrganisationModelPolicyService::class);
		$this->policy->method('isAllowed')->willReturn(true);
		$this->similar = $this->createMock(SimilarQuestionSearch::class);
		$this->similar->method('isAvailable')->willReturn(true);

		$this->service = $this->service(redactor: $this->redactor());
	}

	public function testASimilarFirstQuestionIsServedWithItsScore(): void {
		$this->similar->expects($this->once())->method('nearest')->with(self::REDACTED, 5)->willReturn([
			['entity_id' => 'entry-low', 'entity_type' => 'object', 'similarity' => 0.94],
			['entity_id' => 'entry-2', 'entity_type' => 'object', 'similarity' => 0.97, 'chunk_text' => 'q', 'metadata' => []],
		]);
		$this->objects->expects($this->once())->method('find')->with(
			'entry-2',
			$this->callback(static fn (ObjectFindOptions $options): bool => $options->register === 'hermiq' && $options->schema === 'agentanswercache'),
			false
		)->willReturn($this->entry(uuid: 'entry-2', overrides: ['userId' => 'bob']));
		$this->objects->expects($this->once())->method('saveObject')
			->with($this->callback(static fn (array $data): bool => $data['hitCount'] === 3));

		$this->assertSame(
			['answer' => 'Elke tweede dinsdag.', 'entryId' => 'entry-2', 'layer' => 'similar', 'similarity' => 0.97],
			$this->service->lookup($this->turn(similarity: 'organisation'))
		);
	}

	public function testTheBestRowThatPassesEveryCheckIsServed(): void {
		$this->similar->method('nearest')->willReturn([
			['entity_id' => 'entry-2', 'entity_type' => 'object', 'similarity' => 0.96],
			['entity_id' => 'entry-3', 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 'entry-4', 'entity_type' => 'object', 'similarity' => 0.98],
		]);
		$this->objects->method('find')->willReturnCallback(fn (string $id): ObjectEntity => match ($id) {
			'entry-3' => $this->entry(uuid: 'entry-3', overrides: ['agentVersionId' => 'version-2']),
			default => $this->entry(uuid: $id),
		});

		$hit = $this->service->lookup($this->turn(similarity: 'organisation'));

		$this->assertSame('entry-4', $hit['entryId']);
		$this->assertSame(0.98, $hit['similarity']);
	}

	public function testUserScopeServesOnlyTheSamePersonsQuestions(): void {
		$this->similar->method('nearest')->willReturn([['entity_id' => 'entry-2', 'entity_type' => 'object', 'similarity' => 0.99]]);
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->entry(uuid: 'entry-2', overrides: ['userId' => 'bob']),
			$this->entry(uuid: 'entry-2', overrides: ['userId' => 'alice'])
		);

		$this->assertNull($this->service->lookup($this->turn(similarity: 'user')));
		$this->assertSame('similar', $this->service->lookup($this->turn(similarity: 'user'))['layer']);
	}

	public function testUserScopeWithoutASignedInPersonServesNothing(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$service = $this->service(redactor: $this->redactor(), session: $session);
		$this->similar->method('nearest')->willReturn([['entity_id' => 'entry-2', 'entity_type' => 'object', 'similarity' => 0.99]]);
		$this->objects->method('find')->willReturn($this->entry(uuid: 'entry-2', overrides: ['userId' => '']));

		$this->assertNull($service->lookup($this->turn(similarity: 'user')));
	}

	/**
	 * Rows that fail a re-check are ignored, whatever the facade says.
	 */
	public function testEveryRowIsRecheckedInHermiq(): void {
		$this->similar->method('nearest')->willReturn([
			['entity_id' => 'other-org', 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 'other-version', 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 'expired', 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 'other-agent', 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 'no-question', 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 'missing', 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 7, 'entity_type' => 'file', 'similarity' => 0.99],
			['entity_id' => null, 'entity_type' => 'object', 'similarity' => 0.99],
			['entity_id' => 'too-far', 'entity_type' => 'object', 'similarity' => 0.949],
		]);
		$this->objects->expects($this->exactly(6))->method('find')->willReturnCallback(fn (string $id): ?ObjectEntity => match ($id) {
			'other-org' => $this->entry(uuid: $id, organisation: 'org-b'),
			'other-version' => $this->entry(uuid: $id, overrides: ['agentVersionId' => 'version-2']),
			'expired' => $this->entry(uuid: $id, overrides: ['expiresAt' => '2026-11-14T11:00:00+00:00']),
			'other-agent' => $this->entry(uuid: $id, overrides: ['agentId' => 'agent-2']),
			'no-question' => $this->entry(uuid: $id, overrides: ['question' => null]),
			default => null,
		});
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertNull($this->service->lookup($this->turn(similarity: 'organisation')));
	}

	public function testOrganisationScopeIsRefusedWhileTheAgentSearchesData(): void {
		$this->similar->expects($this->never())->method('nearest');

		$run = $this->turn(similarity: 'organisation', extra: ['enableRag' => true]);

		$this->assertNull($this->service->similarityScope($run));
		$this->assertSame('user', $this->service->similarityScope($this->turn(similarity: 'user', extra: ['enableRag' => true])));
		$this->assertNull($this->service->similarityScope($this->turn(similarity: 'off')));
		$this->assertNull($this->service->similarityScope($this->turn(similarity: 'everyone')));
		$this->assertNull($this->service->lookup($run));
	}

	public function testOnlyTheFirstQuestionOfAConversationIsMatched(): void {
		$this->similar->expects($this->never())->method('nearest');
		$run = $this->turn(similarity: 'organisation');
		$run->messageHistory = [Message::system('P'), Message::user('Hallo'), Message::assistant('Hoi'), Message::user(self::QUESTION)];

		$this->assertNull($this->service->lookup($run));
	}

	public function testWithoutTheFacadeOnlyTheExactLayerRuns(): void {
		$similar = $this->createMock(SimilarQuestionSearch::class);
		$similar->method('isAvailable')->willReturn(false);
		$similar->expects($this->never())->method('nearest');
		$this->objects->expects($this->never())->method('find');

		$this->assertNull($this->service(redactor: $this->redactor(), similar: $similar)->lookup($this->turn(similarity: 'organisation')));
		$this->assertNull($this->service(redactor: $this->redactor(), similar: null)->lookup($this->turn(similarity: 'organisation')));
	}

	public function testWithoutARedactorNothingIsSearchedOrStored(): void {
		$this->similar->expects($this->never())->method('nearest');
		$service = $this->service(redactor: null);
		$this->objects->expects($this->once())->method('saveObject')
			->with($this->callback(static fn (array $data): bool => array_key_exists('question', $data) === false));

		$this->assertNull($service->lookup($this->turn(similarity: 'organisation')));
		$service->store($this->turn(similarity: 'organisation'), 'Elke tweede dinsdag.');
	}

	public function testTheQuestionIsStoredRedactedOnlyWhenTheLayerIsOn(): void {
		$stored = [];
		$this->objects->method('saveObject')->willReturnCallback(static function (array $object) use (&$stored): ObjectEntity {
			$stored[] = ($object['question'] ?? null);
			return new ObjectEntity();
		});

		$this->service->store($this->turn(similarity: 'organisation'), 'Elke tweede dinsdag.');
		$this->service->store($this->turn(similarity: 'off'), 'Elke tweede dinsdag.');
		$later = $this->turn(similarity: 'user');
		$later->messageHistory = [Message::system('P'), Message::user('Hallo'), Message::user(self::QUESTION)];
		$this->service->store($later, 'Elke tweede dinsdag.');

		$this->assertSame([self::REDACTED, null, null], $stored);
	}

	/**
	 * The service under test.
	 */
	private function service(?RedactionService $redactor, ?IUserSession $session = null, SimilarQuestionSearch|null|false $similar = false): AnswerCacheService {
		$versions = $this->createMock(AgentVersionService::class);
		$versions->method('currentVersionId')->willReturn('version-3');
		if ($session === null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
		}

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new AnswerCacheService(
			$this->objects,
			$versions,
			$this->policy,
			$session,
			$time,
			new NullLogger(),
			($similar === false ? $this->similar : $similar),
			$redactor
		);
	}

	/**
	 * A redactor that marks what it redacted.
	 */
	private function redactor(): RedactionService&MockObject {
		$redactor = $this->createMock(RedactionService::class);
		$redactor->method('redact')->willReturnCallback(static fn (string $text): string => '[redacted] ' . $text);

		return $redactor;
	}

	/**
	 * A first-question turn of an agent with the cache and the given similarity on.
	 *
	 * @param array<string, mixed> $extra More agent data.
	 */
	private function turn(string $similarity, array $extra = []): ResponseTurnRun {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject($extra + [
			'prompt' => 'P',
			'temperature' => 0.2,
			'answerCache' => ['enabled' => true, 'ttlMinutes' => 10080, 'similarity' => $similarity],
		]);
		$run = new ResponseTurnRun(self::QUESTION, []);
		$run->agent = $agent;
		$run->progress->organisation = 'org-a';
		$run->progress->driver = new ChatDriver(provider: 'ollama', chat: null, model: 'qwen2.5', credentialId: 'c', baseUrl: 'https://x');
		$run->messageHistory = [Message::system('P'), Message::user(self::QUESTION)];

		return $run;
	}

	/**
	 * A stored entry with a question, in organisation org-a by default.
	 *
	 * @param array<string, mixed> $overrides Overrides of the entry's data.
	 */
	private function entry(string $uuid, array $overrides = [], string $organisation = 'org-a'): ObjectEntity {
		$entry = new ObjectEntity();
		$entry->setUuid($uuid);
		$entry->setOrganisation($organisation);
		$entry->setObject($overrides + [
			'agentId' => 'agent-1',
			'agentVersionId' => 'version-3',
			'keyHash' => str_repeat('a', 64),
			'question' => '[redacted] Wanneer wordt het oud papier opgehaald?',
			'answer' => 'Elke tweede dinsdag.',
			'provider' => 'ollama',
			'model' => 'qwen2.5',
			'userId' => 'alice',
			'expiresAt' => '2026-11-20T12:00:00+00:00',
			'hitCount' => 2,
		]);

		return $entry;
	}
}
