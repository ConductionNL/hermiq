<?php

/**
 * Unit tests for IntakeController.
 *
 * The intake endpoints are what an owning app's channel adapter talks to, so they
 * are open to an authenticated caller and closed to everyone else. The two things
 * asserted here are the refusals a careless integration would otherwise discover in
 * production: a message with no person or subject has no conversation to belong to,
 * and a conclusion without a catalogue has nothing to check a proposal against.
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

use OCA\Hermiq\Controller\IntakeController;
use OCA\Hermiq\Service\Intake\IntakeRefusedException;
use OCA\Hermiq\Service\Intake\IntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * IntakeController unit tests.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md
 */
class IntakeControllerTest extends TestCase {

	/**
	 * A session for one user, or for nobody.
	 *
	 * @param string|null $uid The user id, or null for an unauthenticated session.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A request answering the given parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest The double.
	 */
	private function request(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

		return $request;
	}//end request()

	/**
	 * Build the controller.
	 *
	 * @param IntakeService $intake The intake service.
	 * @param array<string, mixed> $params The request parameters.
	 * @param string|null $uid The calling user, or null.
	 *
	 * @return IntakeController The controller.
	 */
	private function controller(IntakeService $intake, array $params = [], ?string $uid = 'dossiq-service'): IntakeController {
		return new IntakeController(
			$this->request($params),
			$intake,
			$this->session($uid),
			new NullLogger()
		);
	}//end controller()

	/**
	 * A message with no person or no subject is refused: together they are the
	 * conversation, and without them there is nothing for a later message on
	 * another channel to attach to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-one-conversation-must-span-the-channels-a-person-uses
	 */
	public function testAMessageWithoutAPersonAndSubjectIsRefused(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->never())->method('receive');

		$controller = $this->controller($intake, ['text' => 'De lantaarnpaal is kapot']);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->receive()->getStatus());
	}//end testAMessageWithoutAPersonAndSubjectIsRefused()

	/**
	 * An unauthenticated caller reaches nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-an-intake-conversation-must-be-able-to-file-on-its-own-surface
	 */
	public function testAnUnauthenticatedCallerReachesNothing(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->never())->method('receive');

		$controller = $this->controller(
			$intake,
			['person' => 'burger-1', 'subject' => 'lantaarnpaal', 'text' => 'Kapot'],
			null
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->receive()->getStatus());
	}//end testAnUnauthenticatedCallerReachesNothing()

	/**
	 * Concluding without a catalogue or an intake tool is refused rather than
	 * guessed at. A proposal with nothing to check it against is not a
	 * classification, it is a sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-catalogue-is-the-municipalitys
	 */
	public function testConcludingWithoutACatalogueIsRefused(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->expects($this->never())->method('conclude');

		$controller = $this->controller(
			$intake,
			['classification' => ['type' => 'vraag', 'confidence' => 0.9], 'catalogue' => ['vraag']]
		);

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$controller->conclude('conversation-1')->getStatus()
		);

	}//end testConcludingWithoutACatalogueIsRefused()

	/**
	 * A tool the intake grant does not cover comes back as a 403 naming what was
	 * asked for, rather than as a server error that reads like a bug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
	 */
	public function testAnUngrantedToolIsA403(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->method('conclude')->willThrowException(
			new IntakeRefusedException(
				toolId: 'dossiq.case.update',
				reason: 'the intake surface may only call the create-only intake tools and the reviews an owning app declared'
			)
		);

		$controller = $this->controller(
			$intake,
			[
				'classification' => ['type' => 'vraag', 'confidence' => 0.9],
				'catalogue' => ['vraag'],
				'intakeTool' => 'dossiq.case.update',
			]
		);

		$response = $controller->conclude('conversation-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('dossiq.case.update', $response->getData()['error']);
	}//end testAnUngrantedToolIsA403()

	/**
	 * A conversation that does not exist is a 404, not an empty one that reads like
	 * a conversation nobody has said anything in yet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-no-conversation-must-dead-end
	 */
	public function testAnAbsentConversationIsA404(): void {
		$intake = $this->createMock(IntakeService::class);
		$intake->method('conversation')->willReturn(null);

		$controller = $this->controller($intake);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('conversation-404')->getStatus());
	}//end testAnAbsentConversationIsA404()

	/**
	 * A taken-in message is handed back as the service shaped it, so a channel
	 * adapter sees the conversation it just added to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
	 */
	public function testATakenInMessageIsHandedBack(): void {
		$conversation = [
			'id' => 'conversation-1',
			'person' => 'burger-1',
			'subject' => 'lantaarnpaal',
			'state' => IntakeService::STATE_OPEN,
			'terminal' => false,
			'messages' => [['channel' => 'email', 'text' => 'Kapot', 'at' => '2026-03-01T10:00:00+00:00']],
		];

		$intake = $this->createMock(IntakeService::class);
		$intake->method('receive')->willReturn($conversation);

		$controller = $this->controller(
			$intake,
			['person' => 'burger-1', 'subject' => 'lantaarnpaal', 'channel' => 'email', 'text' => 'Kapot']
		);

		$response = $controller->receive();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($conversation, $response->getData());
		$this->assertFalse($response->getData()['terminal']);
	}//end testATakenInMessageIsHandedBack()
}//end class
