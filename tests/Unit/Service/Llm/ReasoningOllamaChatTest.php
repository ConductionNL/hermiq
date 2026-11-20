<?php

/**
 * ReasoningOllamaChat asks for `think: true` only when told to, keeps the
 * `thinking` of a blocking answer apart from its text, and leaves the vendor
 * patch's `think: false` in place for every other call.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
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
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LLPhant\Chat\Message;
use LLPhant\OllamaConfig;
use OCA\Hermiq\Service\Llm\ReasoningOllamaChat;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReasoningOllamaChat.
 */
class ReasoningOllamaChatTest extends TestCase {

	/**
	 * The requests sent, as Guzzle history entries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * A chat whose HTTP client answers with the given bodies.
	 *
	 * @param array<int, array<string, mixed>|string> $bodies The JSON bodies, in order.
	 *
	 * @return ReasoningOllamaChat
	 */
	private function chat(array $bodies): ReasoningOllamaChat {
		$config = new OllamaConfig();
		$config->model = 'qwen3:8b';
		$config->url = 'http://ollama.test/api/';
		$chat = new ReasoningOllamaChat($config);

		$responses = array_map(
			static fn (array|string $body): Response => new Response(200, ['Content-Type' => 'application/json'], is_string($body) === true ? $body : (string)json_encode($body)),
			$bodies
		);
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));
		$chat->client = new Client(['handler' => $stack, 'base_uri' => $config->url]);

		return $chat;
	}//end chat()

	/**
	 * The JSON body of the nth request.
	 *
	 * @param int $index The request index.
	 *
	 * @return array<string, mixed>
	 */
	private function sent(int $index): array {
		return json_decode((string)$this->history[$index]['request']->getBody(), true);
	}//end sent()

	/**
	 * Without being asked, the call keeps the patch's `think: false` and the answer is unchanged.
	 *
	 * @return void
	 */
	public function testACallThatDoesNotAskSendsThinkFalse(): void {
		$chat = $this->chat([['message' => ['role' => 'assistant', 'content' => 'Hello.'], 'prompt_eval_count' => 12]]);

		$this->assertSame('Hello.', $chat->generateChat([Message::user('Hi')]));
		$this->assertFalse($this->sent(0)['think']);
		$this->assertSame(-1, $this->sent(0)['keep_alive']);
		$this->assertFalse($chat->asksForReasoning);
		$this->assertSame('', $chat->lastThinking);
		// The usage patch still reads the body ReasoningOllamaChat handed on.
		$this->assertSame(12, $chat->lastUsage['promptTokens']);
	}//end testACallThatDoesNotAskSendsThinkFalse()

	/**
	 * Asked, the call sends `think: true` and keeps the thinking apart from the answer.
	 *
	 * @return void
	 */
	public function testAskingSendsThinkTrueAndKeepsTheThinking(): void {
		$chat = $this->chat([['message' => ['role' => 'assistant', 'content' => 'Scheme A.', 'thinking' => ' A pays more. ']]]);
		$chat->askForReasoning();

		$this->assertSame('Scheme A.', $chat->generateChat([Message::user('Which scheme?')]));
		$this->assertTrue($this->sent(0)['think']);
		$this->assertSame(-1, $this->sent(0)['keep_alive']);
		$this->assertSame('A pays more.', $chat->lastThinking);
	}//end testAskingSendsThinkTrueAndKeepsTheThinking()

	/**
	 * The thinking of each request of a tool recursion is joined; asking again forgets it.
	 *
	 * @return void
	 */
	public function testTheThinkingOfEveryRequestIsJoined(): void {
		$chat = $this->chat(
			[
				['message' => ['role' => 'assistant', 'content' => '', 'thinking' => 'First.']],
				['message' => ['role' => 'assistant', 'content' => 'x', 'thinking' => '']],
				'not json',
			]
		);
		$chat->askForReasoning();
		$chat->generateChatOrReturnFunctionToCall([Message::user('a')]);
		$chat->generateChatOrReturnFunctionToCall([Message::user('b')]);
		$this->assertSame('First.', $chat->lastThinking);

		$chat->askForReasoning();
		$this->assertSame('', $chat->lastThinking);
	}//end testTheThinkingOfEveryRequestIsJoined()

	/**
	 * A second request's thinking is added after a blank line.
	 *
	 * @return void
	 */
	public function testASecondThinkingFollowsABlankLine(): void {
		$chat = $this->chat(
			[
				['message' => ['role' => 'assistant', 'content' => 'a', 'thinking' => 'One.']],
				['message' => ['role' => 'assistant', 'content' => 'b', 'thinking' => 'Two.']],
			]
		);
		$chat->askForReasoning();
		$chat->generateChat([Message::user('a')]);
		$chat->generateChat([Message::user('b')]);

		$this->assertSame("One.\n\nTwo.", $chat->lastThinking);
	}//end testASecondThinkingFollowsABlankLine()
}//end class
