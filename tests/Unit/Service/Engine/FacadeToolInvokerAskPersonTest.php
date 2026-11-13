<?php

/**
 * An agent asks a person a question through `hermiq.askPerson`: checked, recorded on the run,
 * the turn ended, and the question carried on the chat answer (oversight-ask-and-hold).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use InvalidArgumentException;
use OCA\Hermiq\Controller\ChatStreamController;
use OCA\Hermiq\Kernel\Contracts\ToolAccessRequestServiceInterface;
use OCA\Hermiq\Service\Engine\AskPersonCall;
use OCA\Hermiq\Service\Engine\AskPersonQuestion;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\TurnStoppedException;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The tool registry is the boundary; the invoker, the question, the turn guard and the trace are real.
 *
 * @covers \OCA\Hermiq\Service\Engine\FacadeToolInvoker
 * @covers \OCA\Hermiq\Service\Engine\AskPersonQuestion
 * @covers \OCA\Hermiq\Service\Engine\AskPersonCall
 * @covers \OCA\Hermiq\Service\Engine\TurnGuard
 * @covers \OCA\Hermiq\Service\Engine\RunTraceCollector
 */
class FacadeToolInvokerAskPersonTest extends TestCase {

	/**
	 * An invoker whose registry must never be reached.
	 *
	 * @param RunTraceCollector $trace The run trace.
	 *
	 * @return FacadeToolInvoker
	 */
	private function invoker(RunTraceCollector $trace): FacadeToolInvoker {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->never())->method('invokeTool');
		return new FacadeToolInvoker(facade: $facade, trace: $trace);
	}//end invoker()

	/**
	 * The scenario: which municipality, three options and a text box; the turn ends.
	 *
	 * @return void
	 */
	public function testAQuestionIsRecordedAndEndsTheTurn(): void {
		$trace = new RunTraceCollector();
		$invoker = $this->invoker(trace: $trace);

		$result = json_decode(
			$invoker->hermiq_askPerson(question: 'Which municipality is this for?', options: ['Utrecht', 'Nieuwegein', 'Houten'], allowOther: true),
			true
		);

		$this->assertSame(['result' => AskPersonCall::QUESTION_SENT, 'isError' => false], $result);
		$this->assertSame(
			['text' => 'Which municipality is this for?', 'options' => ['Utrecht', 'Nieuwegein', 'Houten'], 'allowOther' => true],
			$trace->question()
		);
		$this->assertTrue($invoker->isStopped());
		$this->assertContains('Which municipality is this for?', array_column($trace->toArray(), 'name'));

		$this->expectException(TurnStoppedException::class);
		$this->expectExceptionMessage(AskPersonCall::WAITING_FOR_ANSWER);
		$invoker->search_objects(query: 'gemeente');
	}//end testAQuestionIsRecordedAndEndsTheTurn()

	/**
	 * Five suggested answers are refused as invalid, and the turn goes on without a question.
	 *
	 * @return void
	 */
	public function testFiveOptionsAreRefusedAndTheTurnGoesOn(): void {
		$trace = new RunTraceCollector();
		$invoker = $this->invoker(trace: $trace);

		$result = json_decode($invoker->{'hermiq.askPerson'}(question: 'Which one?', options: ['a', 'b', 'c', 'd', 'e']), true);

		$this->assertSame(['result' => ['error' => 'Offer at most 4 suggested answers.'], 'isError' => true], $result);
		$this->assertNull($trace->question());
		$this->assertFalse($invoker->isStopped());
	}//end testFiveOptionsAreRefusedAndTheTurnGoesOn()

	/**
	 * A second question in the same run is refused: one open question per run.
	 *
	 * @return void
	 */
	public function testASecondQuestionInTheRunIsRefused(): void {
		$trace = new RunTraceCollector();
		$trace->recordQuestion(question: ['text' => 'First?', 'options' => [], 'allowOther' => true]);
		$invoker = $this->invoker(trace: $trace);

		$result = json_decode($invoker->hermiq_askPerson(question: 'Second?'), true);

		$this->assertTrue($result['isError']);
		$this->assertSame('One question per run: wait for the answer to the first.', $result['result']['error']);
		$this->assertSame('First?', $trace->question()['text']);
	}//end testASecondQuestionInTheRunIsRefused()

	/**
	 * The rules of a question: required, at most 500 characters, answers of 1 to 80 characters in a list.
	 *
	 * @return void
	 */
	public function testTheQuestionRules(): void {
		$cases = [
			[[], 'askPerson needs a question.'],
			[['question' => '   '], 'askPerson needs a question.'],
			[['question' => str_repeat('x', 501)], 'The question is longer than 500 characters.'],
			[['question' => 'Q?', 'options' => 'Utrecht'], 'options must be a list of suggested answers.'],
			[['question' => 'Q?', 'options' => ['']], 'Each suggested answer is text of 1 to 80 characters.'],
			[['question' => 'Q?', 'options' => [str_repeat('y', 81)]], 'Each suggested answer is text of 1 to 80 characters.'],
		];
		foreach ($cases as [$arguments, $message]) {
			try {
				new AskPersonQuestion(arguments: $arguments);
				$this->fail($message);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame(
			['text' => 'Q?', 'options' => [], 'allowOther' => true],
			(new AskPersonQuestion(arguments: ['question' => ' Q? ', 'allowOther' => false]))->toArray(),
			'without suggested answers the person can only answer in their own words'
		);
		$this->assertSame(
			['text' => 'Q?', 'options' => ['Ja', 'Nee'], 'allowOther' => false],
			(new AskPersonQuestion(arguments: ['question' => 'Q?', 'options' => [' Ja ', 'Nee']]))->toArray()
		);
	}//end testTheQuestionRules()

	/**
	 * The chat stream's final event carries the question, and null when none was asked.
	 *
	 * @return void
	 */
	public function testTheFinalEventCarriesTheQuestion(): void {
		$class = new ReflectionClass(ChatStreamController::class);
		$controller = $class->newInstanceWithoutConstructor();
		$requests = $this->createMock(ToolAccessRequestServiceInterface::class);
		$requests->method('pendingApprovals')->willReturn([]);
		$class->getProperty('accessRequests')->setValue($controller, $requests);
		$finalPayload = $class->getMethod('finalPayload');
		$turn = ['conversationId' => 'conv-1', 'context' => [], 'agentUuid' => 'agent-1'];
		$question = ['text' => 'Which municipality is this for?', 'options' => ['Houten'], 'allowOther' => true];

		$asked = $finalPayload->invoke($controller, ['response' => 'I need to know the municipality.', 'question' => $question], $turn);
		$none = $finalPayload->invoke($controller, ['response' => 'Done.'], $turn);

		$this->assertSame($question, $asked['question']);
		$this->assertSame('I need to know the municipality.', $asked['fullText']);
		$this->assertNull($none['question']);
	}//end testTheFinalEventCarriesTheQuestion()
}//end class
