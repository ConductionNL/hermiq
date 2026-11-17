<?php

/**
 * An agent in validated mode abstains without asking the model when nothing
 * validated matches, and every answer stores the scope it was given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\Engine\Engine
 * @uses \OCA\Hermiq\Service\RetrievalScope\ValidatedMode
 */
class EngineValidatedAbstentionTest extends TestCase {

	private const ABSTENTION = 'Dit kan ik niet beantwoorden op basis van de gevalideerde documenten. Neem contact op met het Klantcontactcentrum.';

	/**
	 * Every stored turn: [role, content, retrievalScope].
	 *
	 * @var list<array{0: string, 1: string, 2: mixed}>
	 */
	private array $stored = [];

	/**
	 * Run one turn whose context retrieval answers $scope.
	 *
	 * @param array<string, mixed>      $scope    The retrieval scope the context reports.
	 * @param ResponseGenerationHandler $response The model's side.
	 *
	 * @return array<string, mixed> The turn's envelope.
	 */
	private function turn(array $scope, ResponseGenerationHandler $response): array {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid('sess-1');
				$object->setObject(['userId' => 'anne', 'agentId' => 'agent-1', 'answerOnlyFromValidated' => true, 'abstentionMessage' => self::ABSTENTION]);
				return $object;
			}
		);

		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('buildTurnHistory')->willReturn(['history' => [], 'turnText' => 'Mag ik vuurwerk afsteken op Koningsdag?']);
		$history->method('storeMessage')->willReturnCallback(
			function (...$args): ObjectEntity {
				// By position, in the order of MessageHistoryHandler::storeMessage(): role 2nd, content 3rd, retrievalScope 10th.
				$this->stored[] = [$args[1], $args[2], ($args[9] ?? null)];
				$message = new ObjectEntity();
				$message->setUuid('msg-' . count($this->stored));
				return $message;
			}
		);

		$context = $this->createMock(ContextRetrievalHandler::class);
		$context->method('retrieveContext')->willReturn(['text' => '', 'sources' => [], 'retrievalScope' => $scope]);

		$engine = new Engine(
			$objectService,
			$context,
			$response,
			$this->createMock(ConversationManagementHandler::class),
			$history,
			$this->createMock(ContextAssembler::class),
			new NullLogger()
		);

		return $engine->processMessage(conversationId: 'sess-1', userId: 'anne', userMessage: 'Mag ik vuurwerk afsteken op Koningsdag?');
	}//end turn()

	public function testNothingValidatedStoresTheAbstentionAndNeverAsksTheModel(): void {
		$scope = ['fileFilters' => null, 'validated' => true, 'floor' => 0.55, 'passed' => 0, 'bestSimilarity' => 0.41, 'abstained' => true];
		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->expects($this->never())->method('generateResponse');

		$envelope = $this->turn($scope, $response);

		$this->assertSame(self::ABSTENTION, $envelope['message']);
		$this->assertSame($scope, $envelope['retrievalScope']);
		$this->assertSame(['assistant', self::ABSTENTION, $scope], $this->stored[1]);
		$this->assertSame('user', $this->stored[0][0]);
	}//end testNothingValidatedStoresTheAbstentionAndNeverAsksTheModel()

	public function testAValidatedPassageIsAnsweredByTheModelAndTheScopeIsStored(): void {
		$scope = ['fileFilters' => null, 'validated' => true, 'floor' => 0.55, 'passed' => 1, 'bestSimilarity' => 0.82, 'abstained' => false];
		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->expects($this->once())->method('generateResponse')->willReturn('Het KCC is open van 8 tot 17 uur.');

		$envelope = $this->turn($scope, $response);

		$this->assertSame('Het KCC is open van 8 tot 17 uur.', $envelope['message']);
		$this->assertSame(['assistant', 'Het KCC is open van 8 tot 17 uur.', $scope], $this->stored[1]);
	}//end testAValidatedPassageIsAnsweredByTheModelAndTheScopeIsStored()
}//end class
