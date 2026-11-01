<?php

/**
 * Unit tests for the output guardrail on a streamed answer.
 *
 * A streaming provider hands the Engine's channel one token delta per chunk.
 * When the effective policy filters output, no delta reaches the channel's
 * token callbacks until `filterOutput()` has run, and what does reach them is
 * the filtered text. With output filtering off, the deltas pass as they arrive.
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
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the token frames of a streamed answer under an output guardrail.
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
 */
class EngineStreamedOutputGuardrailTest extends TestCase {

	/**
	 * The chunks the streaming provider emits; the middle two split the secret.
	 *
	 * @var array<int, string>
	 */
	private const CHUNKS = ['Your key is hun', 'ter2-SEC', 'RET', ', keep it safe.'];

	/**
	 * The raw secret the redaction mock masks.
	 *
	 * @var string
	 */
	private const SECRET = 'hunter2-SECRET';

	/**
	 * Token deltas the client received, in order.
	 *
	 * @var array<int, string>
	 */
	private array $tokens = [];

	/**
	 * Token deltas the client had received when generation finished.
	 *
	 * @var integer
	 */
	private int $tokensDuringGeneration = -1;

	/**
	 * A redacted answer reaches the client only in its redacted form.
	 *
	 * @return void
	 */
	public function testRedactedAnswerStreamsOnlyTheRedactedText(): void {
		$result = $this->streamTurn(outputAction: 'redact', chunks: self::CHUNKS);

		$this->assertSame('Your key is [REDACTED], keep it safe.', $result['message']);
		$this->assertNoFrameCarriesTheSecret();
		$this->assertSame(0, $this->tokensDuringGeneration);
		$this->assertSame([$result['message']], $this->tokens);

	}//end testRedactedAnswerStreamsOnlyTheRedactedText()

	/**
	 * A withheld answer sends no frame with any of its text, only the placeholder.
	 *
	 * @return void
	 */
	public function testWithheldAnswerStreamsOnlyThePlaceholder(): void {
		$result = $this->streamTurn(outputAction: 'block', chunks: self::CHUNKS);

		$this->assertStringContainsString('withheld', $result['message']);
		$this->assertNoFrameCarriesTheSecret();
		$this->assertSame(0, $this->tokensDuringGeneration);
		$this->assertSame([$result['message']], $this->tokens);
		foreach ($this->tokens as $token) {
			$this->assertStringNotContainsString('Your key', $token);
			$this->assertStringNotContainsString('keep it safe', $token);
		}

	}//end testWithheldAnswerStreamsOnlyThePlaceholder()

	/**
	 * An answer the guardrail leaves alone is sent as the chunks that arrived.
	 *
	 * @return void
	 */
	public function testCleanAnswerUnderAGuardrailReplaysItsChunksAfterReview(): void {
		$chunks = ['Nothing ', 'to hide', '.'];

		$result = $this->streamTurn(outputAction: 'redact', chunks: $chunks);

		$this->assertSame('Nothing to hide.', $result['message']);
		$this->assertSame(0, $this->tokensDuringGeneration);
		$this->assertSame($chunks, $this->tokens);

	}//end testCleanAnswerUnderAGuardrailReplaysItsChunksAfterReview()

	/**
	 * A provider that did not stream sends no token frame under a guardrail either.
	 *
	 * @return void
	 */
	public function testNonStreamedAnswerUnderAGuardrailSendsNoTokenFrame(): void {
		$result = $this->streamTurn(outputAction: 'redact', chunks: [], blockingAnswer: 'Your key is hunter2-SECRET.');

		$this->assertSame('Your key is [REDACTED].', $result['message']);
		$this->assertSame([], $this->tokens);

	}//end testNonStreamedAnswerUnderAGuardrailSendsNoTokenFrame()

	/**
	 * Without output filtering the chunks stream live and unchanged.
	 *
	 * @return void
	 */
	public function testWithoutAGuardrailTheChunksStreamLiveAndUnchanged(): void {
		$result = $this->streamTurn(outputAction: 'off', chunks: self::CHUNKS);

		$this->assertSame(implode('', self::CHUNKS), $result['message']);
		$this->assertSame(count(self::CHUNKS), $this->tokensDuringGeneration);
		$this->assertSame(self::CHUNKS, $this->tokens);

	}//end testWithoutAGuardrailTheChunksStreamLiveAndUnchanged()

	/**
	 * Assert that neither one frame nor the joined frames carry the raw secret.
	 *
	 * @return void
	 */
	private function assertNoFrameCarriesTheSecret(): void {
		$this->assertStringNotContainsString(self::SECRET, implode('', $this->tokens));
		foreach ($this->tokens as $token) {
			$this->assertStringNotContainsString('hun', $token);
			$this->assertStringNotContainsString('SEC', $token);
		}

	}//end assertNoFrameCarriesTheSecret()

	/**
	 * Run one streamed turn under an output PII action.
	 *
	 * @param string             $outputAction   The policy's output `piiAction`.
	 * @param array<int, string> $chunks         The deltas the provider streams.
	 * @param string|null        $blockingAnswer The answer of a provider that does not stream.
	 *
	 * @return array<string, mixed> The Engine's result.
	 */
	private function streamTurn(string $outputAction, array $chunks, ?string $blockingAnswer = null): array {
		$this->tokens = [];
		$this->tokensDuringGeneration = -1;

		$channel = new StreamYieldChannel();
		$channel->onToken(
			function (string $delta): void {
				$this->tokens[] = $delta;
			}
		);

		$conversation = $this->entity(uuid: 'conv-1', payload: ['userId' => 'alice', 'agentId' => null]);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($conversation);

		$contextHandler = $this->createMock(ContextRetrievalHandler::class);
		$contextHandler->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);

		$responseHandler = $this->createMock(ResponseGenerationHandler::class);
		$responseHandler->lastUsage = [];
		$responseHandler->method('generateResponse')->willReturnCallback(
			function (mixed ...$args) use ($chunks, $blockingAnswer): string {
				$streamChannel = null;
				foreach ($args as $arg) {
					if ($arg instanceof StreamYieldChannel) {
						$streamChannel = $arg;
					}
				}

				$this->assertNotNull($streamChannel);
				foreach ($chunks as $chunk) {
					$streamChannel->emitToken(delta: $chunk);
				}

				$this->tokensDuringGeneration = count($this->tokens);

				return ($blockingAnswer ?? implode('', $chunks));
			}
		);

		$historyHandler = $this->createMock(MessageHistoryHandler::class);
		$historyHandler->method('storeMessage')->willReturnCallback(
			fn (string $conversationId, string $role, string $content): ObjectEntity => $this->entity(
				uuid: 'msg-' . $role,
				payload: ['role' => $role, 'content' => $content]
			)
		);
		$historyHandler->method('buildTurnHistory')->willReturnCallback(
			static fn (string $conversationId, ObjectEntity $turn, string $turnText): array => ['history' => [], 'turnText' => $turnText]
		);

		$contextAssembler = $this->createMock(ContextAssembler::class);
		$contextAssembler->method('assembleForAgent')->willReturn('');

		$engine = new Engine(
			$objectService,
			$contextHandler,
			$responseHandler,
			$this->createMock(ConversationManagementHandler::class),
			$historyHandler,
			$contextAssembler,
			new NullLogger(),
			$this->guardrails(outputAction: $outputAction)
		);

		return $engine->processMessage(
			conversationId: 'conv-1',
			userId: 'alice',
			userMessage: 'What is my key?',
			channel: $channel
		);

	}//end streamTurn()

	/**
	 * A GuardrailPolicyService that filters for real under a fixed policy.
	 *
	 * @param string $outputAction The policy's output `piiAction`.
	 *
	 * @return GuardrailPolicyService
	 */
	private function guardrails(string $outputAction): GuardrailPolicyService {
		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnCallback(
			static fn (string $text): string => str_replace(self::SECRET, '[REDACTED]', $text)
		);

		$guardrails = $this->getMockBuilder(GuardrailPolicyService::class)
			->setConstructorArgs([$this->createMock(ObjectService::class), $redaction])
			->onlyMethods(['effectivePolicyFor'])
			->getMock();
		$guardrails->method('effectivePolicyFor')->willReturn(
			[
				'inputFilters' => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
				'outputFilters' => ['piiAction' => $outputAction],
				'toolPolicy' => [],
			]
		);

		return $guardrails;

	}//end guardrails()

	/**
	 * Build an ObjectEntity with a UUID and payload.
	 *
	 * @param string               $uuid    The object UUID.
	 * @param array<string, mixed> $payload The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;

	}//end entity()
}//end class
