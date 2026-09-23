<?php

/**
 * How an agent's own `taskType` and `model` are read before an Assistant call.
 *
 * Both selectors answer with a valid value for EVERY input, so neither can fail
 * loudly. That is the whole risk: a typo in `taskType` picks a task surface rather
 * than being refused, and an agent's `model` field is the difference between "the
 * admin's default stands" and "ask for a model with no name". A wrong answer here
 * does not error, it just runs the step somewhere else.
 *
 * Both are private, matching the convention `ProviderFactoryTest::callPrivate()`
 * already established for the provider dispatch internals: reflection rather than
 * widening the API for tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @covers \OCA\Hermiq\Service\Engine\ResponseGenerationHandler
 */
final class ResponseGenerationHandlerAssistantSelectionTest extends TestCase {

	/**
	 * The handler, over collaborators that are never called.
	 *
	 * @return ResponseGenerationHandler The handler under test.
	 */
	private function handler(): ResponseGenerationHandler {
		return new ResponseGenerationHandler(
			$this->createMock(originalClassName: ProviderFactory::class),
			$this->createMock(originalClassName: ToolLoop::class),
			new NullLogger()
		);
	}//end handler()

	/**
	 * Call one of the private selectors.
	 *
	 * @param string $method The method name.
	 * @param array $agentData The agent object's data.
	 *
	 * @return mixed The selector's answer.
	 */
	private function select(string $method, array $agentData) {
		$reflected = new ReflectionMethod(ResponseGenerationHandler::class, $method);
		$reflected->setAccessible(true);

		return $reflected->invoke($this->handler(), $agentData);
	}//end select()

	/**
	 * 🔴 ONLY `contextagent`, SPELLED EXACTLY, SELECTS THE TOOL-RUNNING SURFACE.
	 *
	 * The two task types are not interchangeable: `contextagent` hands the turn to
	 * the Assistant's agent surface, whose provider runs its own tool loop over the
	 * MCP servers the instance is configured with. `text2text` cannot call anything.
	 *
	 * So a typo must land on the INERT one. A near-miss that selected the agent
	 * surface would quietly give a step tool access its author never asked for, and
	 * the only symptom would be a step doing more than it was told to.
	 *
	 * @param mixed $requested What the agent's `taskType` field holds.
	 * @param string $expected The task type that must be selected.
	 *
	 * @return void
	 *
	 * @dataProvider provideTaskTypes
	 */
	public function testTheTaskTypeIsSelectedByExactValue($requested, string $expected): void {
		$agentData = [];
		if ($requested !== null) {
			$agentData['taskType'] = $requested;
		}

		$this->assertSame($expected, $this->select('assistantTaskType', $agentData));

	}//end testTheTaskTypeIsSelectedByExactValue()

	/**
	 * Every way an agent can spell, or misspell, its task type.
	 *
	 * @return array<string,array{0:mixed,1:string}> The cases.
	 */
	public static function provideTaskTypes(): array {
		return [
			'exact' => ['contextagent', ProviderFactory::TASK_TYPE_AGENT],
			'mixed case' => ['ContextAgent', ProviderFactory::TASK_TYPE_AGENT],
			'upper case' => ['CONTEXTAGENT', ProviderFactory::TASK_TYPE_AGENT],
			'padded' => ["  contextagent\n", ProviderFactory::TASK_TYPE_AGENT],
			'absent' => [null, ProviderFactory::TASK_TYPE_TEXT],
			'empty' => ['', ProviderFactory::TASK_TYPE_TEXT],
			'blank' => ['   ', ProviderFactory::TASK_TYPE_TEXT],
			'plain text2text' => ['text2text', ProviderFactory::TASK_TYPE_TEXT],
			'typo, trailing s' => ['contextagents', ProviderFactory::TASK_TYPE_TEXT],
			'typo, hyphenated' => ['context-agent', ProviderFactory::TASK_TYPE_TEXT],
			'typo, spaced' => ['context agent', ProviderFactory::TASK_TYPE_TEXT],
			'prefix only' => ['context', ProviderFactory::TASK_TYPE_TEXT],
			'unknown' => ['chat', ProviderFactory::TASK_TYPE_TEXT],
			'qualified' => ['core:contextagent', ProviderFactory::TASK_TYPE_TEXT],
		];
	}//end provideTaskTypes()

	/**
	 * 🔴 AN UNNAMED MODEL IS `null`, NOT `''`.
	 *
	 * The two are opposite instructions downstream. `null` is "leave the admin's
	 * configured default alone"; `''` is a request for a model with no name, which
	 * `taskInput()` would have to consider sending and the provider's enum would
	 * reject — turning "this agent expressed no preference" into a failed task.
	 *
	 * Whitespace is the case worth pinning, because an agent form submits a field a
	 * person tabbed through as a space, not as absent.
	 *
	 * @param mixed $stored What the agent's `model` field holds.
	 * @param string|null $expected The model that must be asked for.
	 *
	 * @return void
	 *
	 * @dataProvider provideModels
	 */
	public function testAnUnnamedModelIsNullAndANamedOneIsTrimmed($stored, ?string $expected): void {
		$agentData = [];
		if ($stored !== null) {
			$agentData['model'] = $stored;
		}

		$this->assertSame($expected, $this->select('assistantModel', $agentData));

	}//end testAnUnnamedModelIsNullAndANamedOneIsTrimmed()

	/**
	 * Every way an agent can name, or fail to name, a model.
	 *
	 * @return array<string,array{0:mixed,1:string|null}> The cases.
	 */
	public static function provideModels(): array {
		return [
			'absent' => [null, null],
			'empty' => ['', null],
			'spaces' => ['   ', null],
			'tab and newline' => ["\t\n", null],
			'named' => ['accounts/fireworks/models/kimi-k2', 'accounts/fireworks/models/kimi-k2'],
			'padded' => ['  gpt-4o  ', 'gpt-4o'],
		];
	}//end provideModels()
}//end class
