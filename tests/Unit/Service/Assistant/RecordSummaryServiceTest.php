<?php

/**
 * Unit tests for RecordSummaryService (agents-bound-to-their-app, task 5).
 *
 * Real sibling classes where they carry the behaviour under test: OpenRegister's
 * ObjectEntity, hermiq's AgentAvailability (inside the service) and the real
 * RecordSummary register fragment, validated with Opis. The model call is a
 * double, so a call that must not happen is counted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Assistant;

use Exception;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\AppAssistantResolver;
use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCA\Hermiq\Service\Assistant\RecordSummaryService;
use OCA\Hermiq\Service\Engine\AppRegisterScope;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the record summary: authorization, the feature switch, caching per
 * object version, the tool-free call and the stored payload.
 */
final class RecordSummaryServiceTest extends TestCase {

	private const OBJECT = '0b8e0c2a-6f1d-4c3e-9a55-2d7f1b9e8c41';

	private const AGENT = '7d2c9b1e-3f4a-4e6b-8c0d-1a2b3c4d5e6f';

	/** @var array<int, array<string, mixed>> Every saveObject call. */
	private array $saved = [];

	/** @var array<int, array<string, mixed>> Every generateResponse call. */
	private array $modelCalls = [];

	/** @var array<int, mixed> Stored RecordSummary objects findAll returns. */
	private array $stored = [];

	/**
	 * The record the user opened.
	 *
	 * @param array<string, mixed> $data The record's data.
	 *
	 * @return ObjectEntity
	 */
	private function record(array $data = ['title' => 'Subsidy for a playground', 'status' => 'waiting']): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::OBJECT);
		$entity->setRegister('12');
		$entity->setSchema('31');
		$entity->setVersion('1.0.3');
		$entity->setObject($data);
		return $entity;
	}//end record()

	/**
	 * The app's assistant agent.
	 *
	 * @param array<string, mixed> $data Extra agent data.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::AGENT);
		$entity->setObject(array_merge(['name' => 'Subsidy desk helper', 'active' => true, 'tools' => ['openregister.objects.search']], $data));
		return $entity;
	}//end agent()

	/**
	 * A stored summary.
	 *
	 * @param string $hash The content hash it was written for.
	 *
	 * @return ObjectEntity
	 */
	private function storedSummary(string $hash): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('summary-1');
		$entity->setObject(
			[
				'objectUuid' => self::OBJECT,
				'contentHash' => $hash,
				'objectVersion' => '1.0.3',
				'summary' => 'The stored summary.',
				'generatedAt' => '2026-10-01T08:00:00+00:00',
				'agentId' => self::AGENT,
				'agentName' => 'Subsidy desk helper',
			]
		);
		return $entity;
	}//end storedSummary()

	/**
	 * Build the service with doubles for the I/O edges.
	 *
	 * @param ObjectEntity|null|Exception $record What reading the record gives.
	 * @param string|null $lifecycle The feature's lifecycle, null for no feature row.
	 * @param string $resolvedAgent What the resolver answers.
	 * @param ObjectEntity|null $agent The agent read.
	 * @param array<int, array<string, mixed>> $prompts The prompts forScope returns.
	 *
	 * @return RecordSummaryService
	 */
	private function service(
		ObjectEntity|null|Exception $record,
		?string $lifecycle = 'enabled',
		string $resolvedAgent = self::AGENT,
		?ObjectEntity $agent = null,
		array $prompts = [],
	): RecordSummaryService {
		$agent ??= $this->agent();

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($record, $agent) {
				if ((string)$id === self::AGENT) {
					return $agent;
				}

				if ($record instanceof Exception) {
					throw $record;
				}

				return $record;
			}
		);
		$objects->method('findAll')->willReturnCallback(fn (): array => $this->stored);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac];
				$entity = new ObjectEntity();
				$entity->setUuid('summary-new');
				$entity->setObject(is_array($object) === true ? $object : $object->getObject());
				return $entity;
			}
		);

		$features = $this->createMock(AiFeatureService::class);
		$feature = null;
		if ($lifecycle !== null) {
			$feature = new ObjectEntity();
			$feature->setObject(['slug' => 'record-summary', 'lifecycle' => $lifecycle]);
		}

		$features->method('findBySlugForGate')->with('record-summary')->willReturn($feature);

		$resolver = $this->createMock(AppAssistantResolver::class);
		$resolver->method('resolve')->willReturn($resolvedAgent);

		$scope = $this->createMock(AppRegisterScope::class);
		$scope->method('appOf')->willReturn('subsidies');

		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->method('forScope')->with('record-summary')->willReturn($prompts);

		$model = $this->createMock(ResponseGenerationHandler::class);
		$model->method('generateResponse')->willReturnCallback(
			function (string $userMessage, array $context, array $messageHistory, ?ObjectEntity $agent, array $selectedTools = []): string {
				$this->modelCalls[] = compact('userMessage', 'context', 'messageHistory', 'agent', 'selectedTools');
				return 'The application asks for a playground subsidy. It is waiting for a decision.';
			}
		);

		return new RecordSummaryService(
			objectService: $objects,
			resolver: $resolver,
			registerScope: $scope,
			features: $features,
			prompts: $library,
			responseHandler: $model,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Run summarise() for the fixed record.
	 *
	 * @param RecordSummaryService $service The service.
	 *
	 * @return array<string, mixed>
	 */
	private function summarise(RecordSummaryService $service): array {
		return $service->summarise(userId: 'alice', register: 'subsidies', schema: 'application', objectId: self::OBJECT);
	}//end summarise()

	public function testARecordTheUserCannotReadIs404AndNoModelIsCalled(): void {
		$service = $this->service(record: null);

		try {
			$this->summarise(service: $service);
			self::fail('expected a 404');
		} catch (RuntimeException $e) {
			self::assertSame(404, $e->getCode());
		}

		self::assertSame([], $this->modelCalls);
		self::assertSame([], $this->saved);
	}//end testARecordTheUserCannotReadIs404AndNoModelIsCalled()

	public function testARefusedReadIsAlso404(): void {
		$service = $this->service(record: new Exception('Not authorized', 403));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);
		$this->summarise(service: $service);
	}//end testARefusedReadIsAlso404()

	public function testTheStatusOfAnUnreadableRecordIs404(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);
		$this->service(record: null)->status(userId: 'alice', register: 'subsidies', schema: 'application', objectId: self::OBJECT);
	}//end testTheStatusOfAnUnreadableRecordIs404()

	public function testASwitchedOffFeatureIs403AndNoModelIsCalled(): void {
		foreach (['disabled', null] as $lifecycle) {
			$service = $this->service(record: $this->record(), lifecycle: $lifecycle);
			try {
				$this->summarise(service: $service);
				self::fail('expected a 403');
			} catch (RuntimeException $e) {
				self::assertSame(403, $e->getCode());
			}
		}

		self::assertSame([], $this->modelCalls);
	}//end testASwitchedOffFeatureIs403AndNoModelIsCalled()

	public function testNoAgentForTheAppIs409(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(409);
		$this->summarise(service: $this->service(record: $this->record(), resolvedAgent: ''));
	}//end testNoAgentForTheAppIs409()

	public function testASwitchedOffAgentWritesNothing(): void {
		$service = $this->service(record: $this->record(), agent: $this->agent(['active' => false]));

		try {
			$this->summarise(service: $service);
			self::fail('expected the switched-off agent to refuse');
		} catch (\Throwable $e) {
			self::assertNotSame(200, $e->getCode());
		}

		self::assertSame([], $this->modelCalls);
		self::assertSame([], $this->saved);
	}//end testASwitchedOffAgentWritesNothing()

	public function testASummaryIsWrittenToolFreeWithTheAdministeredPromptAndStored(): void {
		$service = $this->service(
			record: $this->record(),
			prompts: [
				['id' => 'p-any', 'label' => 'Anything', 'prompt' => 'An unscoped prompt.', 'usageScope' => ''],
				['id' => 'p-sum', 'label' => 'Record summary', 'prompt' => 'Summarise in three sentences.', 'usageScope' => 'record-summary'],
			]
		);

		$result = $this->summarise(service: $service);

		self::assertCount(1, $this->modelCalls);
		$call = $this->modelCalls[0];
		self::assertSame('Summarise in three sentences.', $call['userMessage'], 'the scoped prompt wins over an unscoped one');
		self::assertSame([RecordSummaryService::NO_TOOLS_SENTINEL], $call['selectedTools'], 'tool-free by construction');
		self::assertStringContainsString('Subsidy for a playground', $call['context']['text']);
		self::assertSame([], $call['messageHistory']);

		self::assertFalse($result['cached']);
		self::assertSame('The application asks for a playground subsidy. It is waiting for a decision.', $result['summary']);
		self::assertSame('Subsidy desk helper', $result['agentName']);
		self::assertNotSame('', $result['generatedAt']);

		self::assertCount(1, $this->saved);
		self::assertSame('hermiq', $this->saved[0]['register']);
		self::assertSame('agentrecordsummary', $this->saved[0]['schema']);
		self::assertFalse($this->saved[0]['_rbac'], 'stored by the service; the object API never shows it');
		self::assertSame('p-sum', $this->saved[0]['object']['promptId']);
		self::assertTrue($this->validSummary(payload: $this->saved[0]['object']), 'the stored payload must pass the real RecordSummary fragment');
	}//end testASummaryIsWrittenToolFreeWithTheAdministeredPromptAndStored()

	public function testWithoutAScopedPromptTheDesignsTextIsUsed(): void {
		$service = $this->service(
			record: $this->record(),
			prompts: [['id' => 'p-any', 'label' => 'Anything', 'prompt' => 'An unscoped prompt.', 'usageScope' => '']]
		);

		$this->summarise(service: $service);

		self::assertSame(RecordSummaryService::DEFAULT_PROMPT, $this->modelCalls[0]['userMessage']);
		self::assertSame('', $this->saved[0]['object']['promptId']);
	}//end testWithoutAScopedPromptTheDesignsTextIsUsed()

	public function testAnUnchangedRecordShowsTheStoredSummaryWithoutAModelCall(): void {
		$record = $this->record();
		$this->stored = [$this->storedSummary(hash: RecordSummaryService::contentHash(object: $record))];

		$result = $this->summarise(service: $this->service(record: $record));

		self::assertSame([], $this->modelCalls);
		self::assertSame([], $this->saved);
		self::assertTrue($result['cached']);
		self::assertSame('The stored summary.', $result['summary']);
		self::assertSame('2026-10-01T08:00:00+00:00', $result['generatedAt']);
	}//end testAnUnchangedRecordShowsTheStoredSummaryWithoutAModelCall()

	public function testAChangedRecordIsSummarisedAgainAndTheStoredSummaryIsReplaced(): void {
		$this->stored = [$this->storedSummary(hash: 'an-older-version')];

		$result = $this->summarise(service: $this->service(record: $this->record()));

		self::assertCount(1, $this->modelCalls);
		self::assertFalse($result['cached']);
		self::assertSame('summary-1', $this->saved[0]['uuid'], 'one summary per record: the stored one is replaced');
	}//end testAChangedRecordIsSummarisedAgainAndTheStoredSummaryIsReplaced()

	public function testTheStatusShowsAStoredSummaryOnlyForTheCurrentVersion(): void {
		$record = $this->record();
		$this->stored = [$this->storedSummary(hash: 'an-older-version')];
		$stale = $this->service(record: $record)->status(userId: 'alice', register: 'subsidies', schema: 'application', objectId: self::OBJECT);

		self::assertTrue($stale['enabled']);
		self::assertSame(['id' => self::AGENT, 'name' => 'Subsidy desk helper'], $stale['agent']);
		self::assertNull($stale['summary']);

		$this->stored = [$this->storedSummary(hash: RecordSummaryService::contentHash(object: $record))];
		$current = $this->service(record: $record)->status(userId: 'alice', register: 'subsidies', schema: 'application', objectId: self::OBJECT);
		self::assertSame('The stored summary.', $current['summary']['summary']);
		self::assertSame([], $this->modelCalls, 'the status never calls a model');
	}//end testTheStatusShowsAStoredSummaryOnlyForTheCurrentVersion()

	public function testTheStatusOfASwitchedOffFeatureOffersNoAgent(): void {
		$status = $this->service(record: $this->record(), lifecycle: 'disabled')->status(userId: 'alice', register: 'subsidies', schema: 'application', objectId: self::OBJECT);

		self::assertFalse($status['enabled']);
		self::assertNull($status['agent']);
	}//end testTheStatusOfASwitchedOffFeatureOffersNoAgent()

	public function testTheContentHashFollowsTheDataNotTheKeyOrder(): void {
		$one = RecordSummaryService::contentHash(object: $this->record(['a' => 1, 'b' => ['x' => 1, 'y' => 2]]));
		$two = RecordSummaryService::contentHash(object: $this->record(['b' => ['y' => 2, 'x' => 1], 'a' => 1]));
		$three = RecordSummaryService::contentHash(object: $this->record(['a' => 2, 'b' => ['x' => 1, 'y' => 2]]));

		self::assertSame($one, $two);
		self::assertNotSame($one, $three);
	}//end testTheContentHashFollowsTheDataNotTheKeyOrder()

	public function testTheFragmentRefusesAPayloadWithoutTheSummary(): void {
		self::assertFalse(
			$this->validSummary(payload: ['objectUuid' => self::OBJECT, 'contentHash' => 'x', 'generatedAt' => '2026-10-01T08:00:00+00:00']),
			'negative control: the validator must be able to refuse'
		);
		self::assertFalse($this->validSummary(payload: ['objectUuid' => self::OBJECT, 'summary' => 42]));
	}//end testTheFragmentRefusesAPayloadWithoutTheSummary()

	public function testTheFragmentKeepsSummariesOutOfTheObjectApi(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'), true);
		$schema = $register['components']['schemas']['RecordSummary'];

		self::assertSame('agentrecordsummary', $schema['slug']);
		self::assertSame(['admin'], $schema['authorization']['read'], 'a summary repeats the record; only the service, which checks the record, reads it');
		self::assertContains('agentrecordsummary', $register['components']['registers']['hermiq']['schemas']);
	}//end testTheFragmentKeepsSummariesOutOfTheObjectApi()

	/**
	 * Validate a payload against the real RecordSummary fragment.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function validSummary(array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->RecordSummary;
		unset($schema->authorization);
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $schema)->isValid();
	}//end validSummary()
}//end class
