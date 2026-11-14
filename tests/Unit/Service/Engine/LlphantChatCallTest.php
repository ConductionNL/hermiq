<?php

/**
 * Unit tests for LlphantChatCall: streaming versus blocking, and the usage it reports.
 *
 * The call streams when a channel listens, except for Ollama with tools, whose
 * tool calls only fire from the blocking path; a provider that cannot stream
 * degrades to the blocking call instead of failing the turn; and the usage it
 * reports is this turn's tokens, not the client's running total.
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Exception\MissingFeatureException;
use OCA\Hermiq\Service\Engine\LlphantChatCall;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the LLPhant chat call a turn makes.
 *
 * @covers \OCA\Hermiq\Service\Engine\LlphantChatCall
 * @uses   \OCA\Hermiq\Service\Engine\ResponseTurnRun
 * @uses   \OCA\Hermiq\Service\Engine\StreamYieldChannel
 * @uses   \OCA\Hermiq\Service\Engine\TurnProgress
 * @uses   \OCA\Hermiq\Service\Llm\ChatDriver
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */
class LlphantChatCallTest extends TestCase {

	/**
	 * A turn carrying one history message, optionally with a listening channel.
	 *
	 * @param StreamYieldChannel|null $channel   The channel tokens go to, if any.
	 * @param array<int, mixed>       $functions The tools offered this turn.
	 *
	 * @return ResponseTurnRun
	 */
	private function turn(?StreamYieldChannel $channel, array $functions = []): ResponseTurnRun {
		$run = new ResponseTurnRun(userMessage: 'hi', context: []);
		$run->messageHistory = ['history'];
		$run->channel = $channel;
		$run->progress->functions = $functions;
		$run->progress->driver = new ChatDriver(provider: 'openai', chat: null, model: 'm');

		return $run;
	}//end run()

	/**
	 * A channel that records every token it forwards.
	 *
	 * @param array<int, string> $tokens The list the tokens are appended to.
	 *
	 * @return StreamYieldChannel
	 */
	private function channel(array &$tokens): StreamYieldChannel {
		$channel = new StreamYieldChannel();
		$channel->onToken(
			static function (string $delta) use (&$tokens): void {
				$tokens[] = $delta;
			}
		);

		return $channel;
	}//end channel()

	/**
	 * A stream that yields the given chunks, then ends.
	 *
	 * @param array<int, string> $chunks The reads, in order.
	 *
	 * @return StreamInterface
	 */
	private function stream(array $chunks): StreamInterface {
		$stream = $this->createMock(StreamInterface::class);
		$eof = array_merge(array_fill(0, count($chunks), false), [true]);
		$stream->method('eof')->willReturnOnConsecutiveCalls(...$eof);
		$stream->method('read')->with(1024)->willReturnOnConsecutiveCalls(...$chunks);

		return $stream;
	}//end stream()

	/**
	 * With a channel listening, the reply is streamed: each non-empty chunk is
	 * forwarded as a token and the chunks are assembled into the reply.
	 *
	 * @return void
	 */
	public function testAListenedTurnStreamsEveryChunkAndAssemblesTheReply(): void {
		$tokens = [];
		$chat = $this->createMock(OpenAIChat::class);
		$chat->expects($this->once())->method('generateChatStream')->with(['history'])
			->willReturn($this->stream(['Hel', '', 'lo']));
		$chat->expects($this->never())->method('generateChat');

		$reply = (new LlphantChatCall(chat: $chat, logger: new NullLogger()))
			->generate(run: $this->turn(channel: $this->channel($tokens)));

		$this->assertSame('Hello', $reply);
		$this->assertSame(['Hel', 'lo'], $tokens);
	}//end testAListenedTurnStreamsEveryChunkAndAssemblesTheReply()

	/**
	 * Without a channel the call blocks.
	 *
	 * @return void
	 */
	public function testAnUnlistenedTurnBlocks(): void {
		$chat = $this->createMock(OpenAIChat::class);
		$chat->expects($this->never())->method('generateChatStream');
		$chat->expects($this->once())->method('generateChat')->with(['history'])->willReturn('blocking reply');

		$this->assertSame(
			'blocking reply',
			(new LlphantChatCall(chat: $chat, logger: new NullLogger()))->generate(run: $this->turn(channel: null))
		);
	}//end testAnUnlistenedTurnBlocks()

	/**
	 * Ollama with tools blocks even when a channel listens, since its tool
	 * calls fire only from the blocking path; without tools it streams.
	 *
	 * @return void
	 */
	public function testOllamaWithToolsBlocksAndWithoutToolsStreams(): void {
		$tokens = [];
		$withTools = $this->createMock(OllamaChat::class);
		$withTools->expects($this->never())->method('generateChatStream');
		$withTools->expects($this->once())->method('generateChat')->willReturn('tool reply');

		$this->assertSame(
			'tool reply',
			(new LlphantChatCall(chat: $withTools, logger: new NullLogger()))
				->generate(run: $this->turn(channel: $this->channel($tokens), functions: [['name' => 'x']]))
		);
		$this->assertSame([], $tokens);

		$withoutTools = $this->createMock(OllamaChat::class);
		$withoutTools->expects($this->once())->method('generateChatStream')->willReturn($this->stream(['ok']));
		$withoutTools->expects($this->never())->method('generateChat');

		$this->assertSame(
			'ok',
			(new LlphantChatCall(chat: $withoutTools, logger: new NullLogger()))
				->generate(run: $this->turn(channel: $this->channel($tokens)))
		);
		$this->assertSame(['ok'], $tokens);
	}//end testOllamaWithToolsBlocksAndWithoutToolsStreams()

	/**
	 * A provider that cannot stream degrades to the blocking call, and the
	 * fallback is logged with the provider and the reason.
	 *
	 * @return void
	 */
	public function testAProviderThatCannotStreamFallsBackToBlocking(): void {
		$chat = $this->createMock(OpenAIChat::class);
		$chat->method('generateChatStream')->willThrowException(new MissingFeatureException('no streaming'));
		$chat->expects($this->once())->method('generateChat')->willReturn('fallback reply');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'[ResponseGenerationHandler] Streaming unavailable, falling back to blocking call',
			$this->callback(
				static fn (array $context): bool => $context['provider'] === 'openai' && $context['error'] === 'no streaming'
			)
		);
		$tokens = [];

		$this->assertSame(
			'fallback reply',
			(new LlphantChatCall(chat: $chat, logger: $logger))->generate(run: $this->turn(channel: $this->channel($tokens)))
		);
	}//end testAProviderThatCannotStreamFallsBackToBlocking()

	/**
	 * OpenAI usage is this turn's share of the running total, split by the
	 * completion tokens the last response reported.
	 *
	 * @return void
	 */
	public function testOpenAiUsageIsThisTurnsShareOfTheRunningTotal(): void {
		$chat = $this->createMock(OpenAIChat::class);
		$chat->method('getTotalTokens')->willReturnOnConsecutiveCalls(100, 160);
		$chat->method('getLastResponse')->willReturn($this->response(completionTokens: 15));
		$chat->method('generateChat')->willReturn('reply');
		$call = new LlphantChatCall(chat: $chat, logger: new NullLogger());

		$call->generate(run: $this->turn(channel: null));

		$this->assertSame(['promptTokens' => 45, 'completionTokens' => 15], $call->usage());
	}//end testOpenAiUsageIsThisTurnsShareOfTheRunningTotal()

	/**
	 * The prompt OpenAI read from its cache is reported apart, and stays inside promptTokens.
	 *
	 * @return void
	 */
	public function testOpenAiCachedPromptTokensAreReported(): void {
		$chat = $this->createMock(OpenAIChat::class);
		$chat->method('getTotalTokens')->willReturnOnConsecutiveCalls(100, 160);
		$chat->method('getLastResponse')->willReturn($this->response(completionTokens: 15, cachedTokens: 32));
		$chat->method('generateChat')->willReturn('reply');
		$call = new LlphantChatCall(chat: $chat, logger: new NullLogger());

		$call->generate(run: $this->turn(channel: null));

		$this->assertSame(['promptTokens' => 45, 'completionTokens' => 15, 'cacheReadTokens' => 32], $call->usage());
	}//end testOpenAiCachedPromptTokensAreReported()

	/**
	 * Completion tokens never exceed the turn's total, and with no response
	 * to read every token counts as prompt.
	 *
	 * @return void
	 */
	public function testOpenAiCompletionIsCappedAndAMissingResponseCountsAsPrompt(): void {
		$capped = $this->createMock(OpenAIChat::class);
		$capped->method('getTotalTokens')->willReturnOnConsecutiveCalls(0, 10);
		$capped->method('getLastResponse')->willReturn($this->response(completionTokens: 50));
		$call = new LlphantChatCall(chat: $capped, logger: new NullLogger());
		$call->generate(run: $this->turn(channel: null));

		$this->assertSame(['promptTokens' => 0, 'completionTokens' => 10], $call->usage());

		$noResponse = $this->createMock(OpenAIChat::class);
		$noResponse->method('getTotalTokens')->willReturnOnConsecutiveCalls(5, 12);
		$noResponse->method('getLastResponse')->willReturn(null);
		$call = new LlphantChatCall(chat: $noResponse, logger: new NullLogger());
		$call->generate(run: $this->turn(channel: null));

		$this->assertSame(['promptTokens' => 7, 'completionTokens' => 0], $call->usage());
	}//end testOpenAiCompletionIsCappedAndAMissingResponseCountsAsPrompt()

	/**
	 * A turn that used no tokens reports no usage at all.
	 *
	 * @return void
	 */
	public function testOpenAiWithNoNewTokensReportsNothing(): void {
		$chat = $this->createMock(OpenAIChat::class);
		$chat->method('getTotalTokens')->willReturn(40);
		$call = new LlphantChatCall(chat: $chat, logger: new NullLogger());
		$call->generate(run: $this->turn(channel: null));

		$this->assertSame([], $call->usage());
	}//end testOpenAiWithNoNewTokensReportsNothing()

	/**
	 * Ollama usage is what the client recorded for the call, as is.
	 *
	 * @return void
	 */
	public function testOllamaUsageIsWhatTheClientRecorded(): void {
		$chat = $this->createMock(OllamaChat::class);
		$chat->lastUsage = ['promptTokens' => 12, 'completionTokens' => 3, 'totalDurationMs' => 250];

		$this->assertSame(
			['promptTokens' => 12, 'completionTokens' => 3, 'totalDurationMs' => 250],
			(new LlphantChatCall(chat: $chat, logger: new NullLogger()))->usage()
		);
	}//end testOllamaUsageIsWhatTheClientRecorded()

	/**
	 * A chat completion response reporting the given completion tokens.
	 *
	 * @param int $completionTokens The completion tokens it reports.
	 *
	 * @return CreateResponse
	 */
	private function response(int $completionTokens, ?int $cachedTokens = null): CreateResponse {
		$usage = ['prompt_tokens' => 1, 'completion_tokens' => $completionTokens, 'total_tokens' => 1 + $completionTokens];
		if ($cachedTokens !== null) {
			$usage['prompt_tokens_details'] = ['cached_tokens' => $cachedTokens];
		}

		return CreateResponse::from(
			[
				'id' => 'r1',
				'object' => 'chat.completion',
				'created' => 0,
				'model' => 'm',
				'choices' => [],
				'usage' => $usage,
			],
			MetaInformation::from([])
		);
	}//end response()
}//end class
