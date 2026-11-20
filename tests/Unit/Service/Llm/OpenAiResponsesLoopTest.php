<?php

/**
 * Unit tests for OpenAiResponsesLoop: the Responses API request, its tool loop and its answer.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Llm\AttachmentMessage;
use OCA\Hermiq\Service\Llm\OpenAiResponsesLoop;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * The Responses API mapping both ways and the governed tool loop.
 */
class OpenAiResponsesLoopTest extends TestCase {

	/**
	 * The payloads posted, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $posted = [];

	/**
	 * The tool calls the executor ran, as name and input.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $ran = [];

	/**
	 * A loop whose endpoint answers with these bodies in turn.
	 *
	 * @param array<int,array<string,mixed>> $answers   The decoded answers.
	 * @param bool                           $withTools Whether the turn has an executor.
	 * @param int                            $cap       The tool call cap.
	 *
	 * @return OpenAiResponsesLoop
	 */
	private function loop(array $answers, bool $withTools = true, int $cap = 10): OpenAiResponsesLoop {
		$this->posted = [];
		$this->ran = [];
		$post = function (array $payload) use (&$answers): array {
			$this->posted[] = $payload;
			return array_shift($answers) ?? [];
		};
		$executor = null;
		if ($withTools === true) {
			// The governed executor: a granted tool answers, anything else is refused.
			$executor = function (string $name, array $input): string {
				$this->ran[] = [$name, $input];
				if ($name !== 'hermiq_listCalendarEvents') {
					return (string)json_encode(['error' => "Unknown tool: {$name}"]);
				}

				return (string)json_encode(['events' => ['Overleg 10:00']]);
			};
		}

		return new OpenAiResponsesLoop(post: $post, toolExecutor: $executor, maxToolCalls: $cap);
	}//end loop()

	/**
	 * A text answer.
	 *
	 * @param string $text The text.
	 *
	 * @return array<string,mixed>
	 */
	private function textAnswer(string $text): array {
		return [
			'output' => [
				['type' => 'reasoning', 'summary' => []],
				['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]],
			],
			'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
		];
	}//end textAnswer()

	/**
	 * A function call answer.
	 *
	 * @param string $name      The tool.
	 * @param string $arguments The JSON arguments.
	 * @param string $callId    The call id.
	 *
	 * @return array<string,mixed>
	 */
	private function callAnswer(string $name, string $arguments, string $callId = 'call_1'): array {
		return [
			'output' => [['type' => 'function_call', 'id' => 'fc_1', 'call_id' => $callId, 'name' => $name, 'arguments' => $arguments]],
			'usage' => ['input_tokens' => 20, 'output_tokens' => 6],
		];
	}//end callAnswer()

	/**
	 * The system blocks become instructions, the history input, the tools function tools, and store is false.
	 *
	 * @return void
	 */
	public function testThePayloadMapsTheTurn(): void {
		$payload = $this->loop(answers: [])->payload(
			model: 'gpt-5',
			messageHistory: [
				LLPhantMessage::system('Je bent een assistent.'),
				LLPhantMessage::system('Antwoord in het Nederlands.'),
				LLPhantMessage::user('Hallo'),
				LLPhantMessage::assistant('Dag!'),
				LLPhantMessage::user('Wat staat er morgen in mijn agenda?'),
			],
			functions: [
				['name' => 'hermiq_listCalendarEvents', 'description' => 'List events', 'parameters' => ['type' => 'object', 'properties' => ['day' => ['type' => 'string']]]],
				['name' => 'hermiq_ping', 'parameters' => ['type' => 'object', 'properties' => []]],
				'not a tool',
			],
			maxTokens: 300
		);

		$this->assertSame('gpt-5', $payload['model']);
		$this->assertFalse($payload['store']);
		$this->assertSame("Je bent een assistent.\n\nAntwoord in het Nederlands.", $payload['instructions']);
		$this->assertSame(
			[
				['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hallo']]],
				['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Dag!']]],
				['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Wat staat er morgen in mijn agenda?']]],
			],
			$payload['input']
		);
		$this->assertSame(300, $payload['max_output_tokens']);
		$this->assertCount(2, $payload['tools']);
		$this->assertSame(['type' => 'function', 'name' => 'hermiq_listCalendarEvents', 'description' => 'List events'], array_slice($payload['tools'][0], 0, 3));
		$this->assertSame('{"type":"object","properties":{}}', json_encode($payload['tools'][1]['parameters']));
	}//end testThePayloadMapsTheTurn()

	/**
	 * A turn without an executor advertises no tools and no ceiling it was not given.
	 *
	 * @return void
	 */
	public function testATextOnlyTurnSendsNoTools(): void {
		$payload = $this->loop(answers: [], withTools: false)->payload(
			model: 'm',
			messageHistory: [LLPhantMessage::user('Hoi')],
			functions: [['name' => 'hermiq_listCalendarEvents']],
			maxTokens: null
		);

		$this->assertArrayNotHasKey('tools', $payload);
		$this->assertArrayNotHasKey('instructions', $payload);
		$this->assertArrayNotHasKey('max_output_tokens', $payload);
	}//end testATextOnlyTurnSendsNoTools()

	/**
	 * An attachment rides on the user turn as an input image or file.
	 *
	 * @return void
	 */
	public function testAttachmentsAreInputParts(): void {
		$message = AttachmentMessage::withParts(
			text: 'Wat zie je?',
			parts: [
				['kind' => 'image', 'name' => 'a.png', 'mimeType' => 'image/png', 'base64' => 'iVBORw0KGgo='],
				['kind' => 'pdf', 'name' => 'b.pdf', 'mimeType' => 'application/pdf', 'base64' => 'BBB'],
			]
		);

		$payload = $this->loop(answers: [])->payload(model: 'm', messageHistory: [$message], functions: [], maxTokens: null);

		$this->assertSame(
			[
				['type' => 'input_text', 'text' => 'Wat zie je?'],
				['type' => 'input_image', 'image_url' => 'data:image/png;base64,iVBORw0KGgo='],
				['type' => 'input_file', 'filename' => 'b.pdf', 'file_data' => 'data:application/pdf;base64,BBB'],
			],
			$payload['input'][0]['content']
		);
	}//end testAttachmentsAreInputParts()

	/**
	 * A function call runs through the executor and goes back as its output, then the answer is the text.
	 *
	 * @return void
	 */
	public function testAFunctionCallRunsThroughTheExecutor(): void {
		$loop = $this->loop(answers: [
			$this->callAnswer(name: 'hermiq_listCalendarEvents', arguments: '{"day":"morgen"}'),
			$this->textAnswer(text: 'Morgen: overleg om 10:00.'),
		]);

		$answer = $loop->run(payload: $loop->payload(model: 'm', messageHistory: [LLPhantMessage::user('Agenda?')], functions: [['name' => 'hermiq_listCalendarEvents']], maxTokens: null));

		$this->assertSame('Morgen: overleg om 10:00.', $answer);
		$this->assertSame([['hermiq_listCalendarEvents', ['day' => 'morgen']]], $this->ran);
		$this->assertCount(2, $this->posted);
		$this->assertSame(
			[
				['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'hermiq_listCalendarEvents', 'arguments' => '{"day":"morgen"}'],
				['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"events":["Overleg 10:00"]}'],
			],
			array_slice($this->posted[1]['input'], 1)
		);
		$this->assertFalse($this->posted[1]['store']);
		$this->assertSame(['promptTokens' => 30, 'completionTokens' => 10], $loop->usage());
		$this->assertFalse($loop->hitToolCap());
	}//end testAFunctionCallRunsThroughTheExecutor()

	/**
	 * A tool the agent was not granted is refused by the executor, and the refusal is what the model gets back.
	 *
	 * @return void
	 */
	public function testAnUngrantedToolIsRefusedByTheExecutor(): void {
		$loop = $this->loop(answers: [
			$this->callAnswer(name: 'hermiq_sendMail', arguments: '{"to":"x"}', callId: 'call_9'),
			$this->textAnswer(text: 'Dat mag ik niet.'),
		]);

		$this->assertSame('Dat mag ik niet.', $loop->run(payload: ['model' => 'm', 'input' => [], 'store' => false]));
		$this->assertSame(['type' => 'function_call_output', 'call_id' => 'call_9', 'output' => '{"error":"Unknown tool: hermiq_sendMail"}'], $this->posted[1]['input'][1]);
	}//end testAnUngrantedToolIsRefusedByTheExecutor()

	/**
	 * Arguments that are not a JSON object reach the executor as no arguments.
	 *
	 * @return void
	 */
	public function testUnreadableArgumentsAreEmpty(): void {
		$loop = $this->loop(answers: [
			$this->callAnswer(name: 'hermiq_listCalendarEvents', arguments: 'not json'),
			$this->textAnswer(text: 'Klaar.'),
		]);

		$loop->run(payload: ['model' => 'm', 'input' => [], 'store' => false]);

		$this->assertSame([['hermiq_listCalendarEvents', []]], $this->ran);
	}//end testUnreadableArgumentsAreEmpty()

	/**
	 * The turn stops at the tool call cap and says so.
	 *
	 * @return void
	 */
	public function testTheToolCallCapEndsTheTurn(): void {
		$loop = $this->loop(
			answers: [
				$this->callAnswer(name: 'hermiq_listCalendarEvents', arguments: '{}'),
				$this->callAnswer(name: 'hermiq_listCalendarEvents', arguments: '{}', callId: 'call_2'),
			],
			cap: 1
		);

		$this->assertSame('', $loop->run(payload: ['model' => 'm', 'input' => [], 'store' => false]));
		$this->assertCount(1, $this->ran);
		$this->assertCount(2, $this->posted);
		$this->assertTrue($loop->hitToolCap());
	}//end testTheToolCallCapEndsTheTurn()

	/**
	 * A hosted tool or other output Hermiq does not run is refused with a clear error.
	 *
	 * @return void
	 */
	public function testAHostedToolIsRefused(): void {
		$loop = $this->loop(answers: [['output' => [['type' => 'web_search_call', 'id' => 'ws_1']]]]);

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('The model answered with "web_search_call", which Hermiq does not run.');

		$loop->run(payload: ['model' => 'm', 'input' => [], 'store' => false]);
	}//end testAHostedToolIsRefused()

	/**
	 * A function call on a turn without tools is refused, never run.
	 *
	 * @return void
	 */
	public function testAFunctionCallWithoutToolsIsRefused(): void {
		$loop = $this->loop(answers: [$this->callAnswer(name: 'hermiq_sendMail', arguments: '{}')], withTools: false);

		$this->expectException(ProviderUnavailableException::class);

		$loop->run(payload: ['model' => 'm', 'input' => [], 'store' => false]);
	}//end testAFunctionCallWithoutToolsIsRefused()
}//end class
