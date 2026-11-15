<?php

/**
 * Unit tests for the remaining paths of IntakeController.
 *
 * IntakeControllerTest pins the refusals at the door. What this file pins is
 * what each endpoint hands the intake service once the request is complete,
 * the defaults it fills in, and how a refusal, a missing conversation and an
 * unexpected failure each answer: 403 with the reason, 404, and a logged 500
 * that does not leak the cause.
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\IntakeConclusion;
use OCA\Hermiq\Controller\IntakeController;
use OCA\Hermiq\Service\Intake\IntakeRefusedException;
use OCA\Hermiq\Service\Intake\IntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The intake endpoints past the door.
 */
#[CoversClass(IntakeController::class)]
#[UsesClass(IntakeConclusion::class)]
#[UsesClass(IntakeRefusedException::class)]
class IntakeControllerPathsTest extends TestCase {

	/**
	 * The controller over this service and request.
	 *
	 * @param IntakeService        $intake  The intake service.
	 * @param array<string, mixed> $params  The request parameters.
	 * @param array<string, mixed> $options `anonymous`, `logger`.
	 *
	 * @return IntakeController The controller.
	 */
	private function controller(IntakeService $intake, array $params=[], array $options=[]): IntakeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$session = $this->createMock(IUserSession::class);
		if (($options['anonymous'] ?? false) === true) {
			$session->method('getUser')->willReturn(null);
		} else {
			$session->method('getUser')->willReturn($this->createMock(IUser::class));
		}

		return new IntakeController($request, $intake, $session, $options['logger'] ?? $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A logger that expects exactly this one error message.
	 *
	 * @param string $message The message.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function loggerExpecting(string $message): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($message);
		return $logger;
	}//end loggerExpecting()

	/**
	 * A complete conclusion request.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function conclusion(): array {
		return [
			'classification' => ['product' => 'parkeervergunning'],
			'catalogue' => [['product' => 'parkeervergunning']],
			'intakeTool' => '  zaak.create  ',
		];
	}//end conclusion()

	/**
	 * A message is taken in trimmed, on the portal channel unless another is named.
	 *
	 * @return void
	 */
	public function testAMessageIsTakenInTrimmedOnThePortalByDefault(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->exactly(2))->method('receive')
			->willReturnCallback(
				static fn (string $person, string $subject, string $channel, string $text): array => compact('person', 'subject', 'channel', 'text')
			);

		$portal = $this->controller($intake, ['person' => ' bsn-1 ', 'subject' => ' parkeren ', 'text' => ' Ik wil een vergunning '])->receive();
		$mail = $this->controller($intake, ['person' => 'bsn-1', 'subject' => 'parkeren', 'channel' => ' email '])->receive();

		$this->assertSame(['person' => 'bsn-1', 'subject' => 'parkeren', 'channel' => 'portal', 'text' => ' Ik wil een vergunning '], $portal->getData());
		$this->assertSame(['person' => 'bsn-1', 'subject' => 'parkeren', 'channel' => 'email', 'text' => ''], $mail->getData());
	}//end testAMessageIsTakenInTrimmedOnThePortalByDefault()

	/**
	 * A message that cannot be taken in is a logged 500.
	 *
	 * @return void
	 */
	public function testAMessageThatCannotBeTakenInIsALogged500(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->method('receive')->willThrowException(new RuntimeException('register down'));

		$response = $this->controller($intake, ['person' => 'bsn-1', 'subject' => 'parkeren'], ['logger' => $this->loggerExpecting('Hermiq intake receive failed: register down')])->receive();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The message could not be taken in'], $response->getData());
	}//end testAMessageThatCannotBeTakenInIsALogged500()

	/**
	 * A conversation is shown when it exists, and closed to nobody signed in.
	 *
	 * @return void
	 */
	public function testAConversationIsShownToASignedInCaller(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->once())->method('conversation')->with('c1')->willReturn(['id' => 'c1', 'messages' => []]);

		$this->assertSame(['id' => 'c1', 'messages' => []], $this->controller($intake)->show('c1')->getData());

		$closed = $this->createMock(IntakeService::class);
		$closed->expects($this->never())->method('conversation');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($closed, [], ['anonymous' => true])->show('c1')->getStatus());
	}//end testAConversationIsShownToASignedInCaller()

	/**
	 * A conclusion hands the service the classification, catalogue and trimmed tool.
	 *
	 * @return void
	 */
	public function testAConclusionIsHandedOnWithTheTrimmedTool(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->once())->method('conclude')
			->with('c1', ['product' => 'parkeervergunning'], [['product' => 'parkeervergunning']], 'zaak.create')
			->willReturn(['id' => 'c1', 'status' => 'concluded']);

		$response = $this->controller($intake, $this->conclusion())->conclude('c1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'c1', 'status' => 'concluded'], $response->getData());
	}//end testAConclusionIsHandedOnWithTheTrimmedTool()

	/**
	 * A conclusion is refused with its reason, 404 when the conversation is gone, logged 500 otherwise.
	 *
	 * @return void
	 */
	public function testAConclusionFailsByCause(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->method('conclude')->willReturnOnConsecutiveCalls(
			$this->throwException(new IntakeRefusedException('zaak.create', 'not in the catalogue')),
			null,
			$this->throwException(new RuntimeException('tool timeout'))
		);

		$refused = $this->controller($intake, $this->conclusion())->conclude('c1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['error' => "The intake surface may not call 'zaak.create': not in the catalogue."], $refused->getData());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($intake, $this->conclusion())->conclude('c1')->getStatus());

		$failed = $this->controller($intake, $this->conclusion(), ['logger' => $this->loggerExpecting('Hermiq intake conclude failed: tool timeout')])->conclude('c1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'The conversation could not be concluded'], $failed->getData());
	}//end testAConclusionFailsByCause()

	/**
	 * Nobody signed in concludes or reviews nothing.
	 *
	 * @return void
	 */
	public function testNobodySignedInConcludesOrReviewsNothing(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->never())->method('conclude');
		$intake->expects($this->never())->method('review');
		$controller = $this->controller($intake, $this->conclusion(), ['anonymous' => true]);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->conclude('c1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->review('c1')->getStatus());
	}//end testNobodySignedInConcludesOrReviewsNothing()

	/**
	 * A review hands the service the trimmed tool and its arguments, none when they are no list.
	 *
	 * @return void
	 */
	public function testAReviewIsHandedOn(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->exactly(2))->method('review')
			->willReturnCallback(
				static fn (string $conversationId, string $toolId, array $arguments): array => compact('conversationId', 'toolId', 'arguments')
			);

		$withArguments = $this->controller($intake, ['tool' => ' zaak.check ', 'arguments' => ['zaak' => 'z1']])->review('c1');
		$withoutList = $this->controller($intake, ['tool' => 'zaak.check', 'arguments' => 'z1'])->review('c1');

		$this->assertSame(['conversationId' => 'c1', 'toolId' => 'zaak.check', 'arguments' => ['zaak' => 'z1']], $withArguments->getData());
		$this->assertSame([], $withoutList->getData()['arguments']);
	}//end testAReviewIsHandedOn()

	/**
	 * A review is refused with its reason, 404 when the conversation is gone, logged 500 otherwise.
	 *
	 * @return void
	 */
	public function testAReviewFailsByCause(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->method('review')->willReturnOnConsecutiveCalls(
			$this->throwException(new IntakeRefusedException('zaak.delete', 'not granted')),
			null,
			$this->throwException(new RuntimeException('connection reset'))
		);

		$refused = $this->controller($intake, ['tool' => 'zaak.delete'])->review('c1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['error' => "The intake surface may not call 'zaak.delete': not granted."], $refused->getData());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($intake, ['tool' => 'zaak.check'])->review('c1')->getStatus());

		$failed = $this->controller($intake, ['tool' => 'zaak.check'], ['logger' => $this->loggerExpecting('Hermiq intake review failed: connection reset')])->review('c1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'The review could not be carried out'], $failed->getData());
	}//end testAReviewFailsByCause()
}//end class
