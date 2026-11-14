<?php

/**
 * The model's reasoning and the context usage travel on the `final` frame only:
 * a companion appends every `token` delta to the answer, so reasoning sent as a
 * token would print as the answer (chat-how-an-answer-came-about D3, hydra ADR-034).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatStreamController;
use OCA\Hermiq\Kernel\Contracts\ToolAccessRequestServiceInterface;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the reasoning on ChatStreamController's `final` frame.
 */
class ChatStreamFinalReasoningTest extends TestCase {

	/**
	 * The `final` frame the controller builds from an engine envelope.
	 *
	 * @param array<string, mixed> $result The engine envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function finalFrame(array $result): array {
		$class = new ReflectionClass(ChatStreamController::class);
		$controller = $class->newInstanceWithoutConstructor();
		$requests = $this->createMock(ToolAccessRequestServiceInterface::class);
		$requests->method('pendingApprovals')->willReturn([]);
		$class->getProperty('accessRequests')->setValue($controller, $requests);

		$method = $class->getMethod('finalPayload');
		return $method->invoke($controller, $result, ['conversationId' => 'conv-1', 'context' => [], 'agentUuid' => 'agent-1']);
	}//end finalFrame()

	/**
	 * The reasoning, its withheld mark and the context usage are on `final`, apart from the answer.
	 *
	 * @return void
	 */
	public function testTheReasoningTravelsOnTheFinalFrame(): void {
		$usage = ['usedTokens' => 850, 'windowTokens' => 1000, 'estimated' => false];
		$frame = $this->finalFrame(
			['message' => 'Scheme A.', 'messageId' => 'm1', 'reasoning' => 'A pays more.', 'reasoningWithheld' => false, 'contextUsage' => $usage]
		);

		$this->assertSame('Scheme A.', $frame['fullText']);
		$this->assertSame('A pays more.', $frame['reasoning']);
		$this->assertFalse($frame['reasoningWithheld']);
		$this->assertSame($usage, $frame['contextUsage']);
		$this->assertStringNotContainsString('A pays more.', $frame['fullText']);
	}//end testTheReasoningTravelsOnTheFinalFrame()

	/**
	 * An envelope without them gives null, false and null, never a guessed value.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutReasoningGivesNone(): void {
		$frame = $this->finalFrame(['message' => 'Hi.', 'reasoningWithheld' => 'yes']);

		$this->assertNull($frame['reasoning']);
		$this->assertFalse($frame['reasoningWithheld']);
		$this->assertNull($frame['contextUsage']);
	}//end testAnEnvelopeWithoutReasoningGivesNone()

	/**
	 * Nothing in the controller sends reasoning as a frame of its own: the only
	 * frames are the six of the envelope, and reasoning is a `final` key.
	 *
	 * @return void
	 */
	public function testNoFrameTypeCarriesReasoning(): void {
		$source = (string)file_get_contents((new ReflectionClass(ChatStreamController::class))->getFileName());
		$this->assertDoesNotMatchRegularExpression('/eventType:\s*\'reasoning\'/', $source);
		$channel = (string)file_get_contents((new ReflectionClass(StreamYieldChannel::class))->getFileName());
		$this->assertStringNotContainsString('reasoning', $channel);
	}//end testNoFrameTypeCarriesReasoning()
}//end class
