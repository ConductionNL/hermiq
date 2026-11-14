<?php

/**
 * Hermiq ChatImageController: the chat action "Create an image".
 *
 * Drives the real controller with the real ConversationParticipation; the image
 * service, the history handler and OpenRegister are doubles
 * (chat-attachments-and-images task 8).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatImageController;
use OCA\Hermiq\Service\Chat\ImageGenerationException;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the chat image action.
 */
class ChatImageControllerTest extends TestCase {

	/**
	 * The storeMessage calls, positional in its parameter order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The (uid, prompt) pairs the service was asked to create.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $asked = [];

	/**
	 * The controller for a signed-in person over Anne's session shared with Bram.
	 *
	 * @param string|null                  $uid       The signed-in uid, null for nobody.
	 * @param ImageGenerationException|null $failure  What the service throws.
	 * @param bool                         $available Whether the action can be offered.
	 *
	 * @return ChatImageController The controller.
	 */
	private function controller(?string $uid, ?ImageGenerationException $failure = null, bool $available = true): ChatImageController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
		}

		$session->method('getUser')->willReturn($user);

		// Partial: the real asAttachment() shapes the stored reference.
		$images = $this->getMockBuilder(ImageGenerationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['canCreate', 'create'])
			->getMock();
		$images->method('canCreate')->willReturn($available);
		$images->method('create')->willReturnCallback(
			function (string $uid, string $prompt) use ($failure): array {
				$this->asked[] = [$uid, $prompt];
				if ($failure !== null) {
					throw $failure;
				}

				return [
					'fileId' => 902,
					'name' => 'image-1.png',
					'path' => 'Hermiq/Generated images/image-1.png',
					'mimeType' => 'image/png',
					'size' => 2048,
				];
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (string $id): ?ObjectEntity {
				if ($id !== 'sess-1') {
					return null;
				}

				$conversation = new ObjectEntity();
				$conversation->setUuid('sess-1');
				$conversation->setObject(['userId' => 'anne', 'participants' => ['bram']]);
				return $conversation;
			}
		);

		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('storeMessage')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->stored[] = $args;
				$turn = new ObjectEntity();
				$turn->setUuid('turn-' . count($this->stored));
				return $turn;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new ChatImageController(
			appName: 'hermiq',
			request: $this->createMock(IRequest::class),
			userSession: $session,
			images: $images,
			objectService: $objects,
			history: $history,
			participation: new ConversationParticipation(),
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * The owner's description becomes their turn, and the answer carries the image as a generated attachment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-a-communications-advisor-asks-for-an-illustration
	 */
	public function testTheImageIsTheAnswerOnTheSession(): void {
		$response = $this->controller('anne')->create(
			sessionId: 'sess-1',
			prompt: 'Een fietsenstalling bij station Zwolle in de ochtendzon'
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['anne', 'Een fietsenstalling bij station Zwolle in de ochtendzon']], $this->asked);
		$this->assertCount(2, $this->stored);
		$this->assertSame('user', $this->stored[0][1]);
		$this->assertSame('Een fietsenstalling bij station Zwolle in de ochtendzon', $this->stored[0][2]);
		$this->assertSame('anne', $this->stored[0][5]);
		$this->assertSame('assistant', $this->stored[1][1]);
		$image = ['fileId' => 902, 'name' => 'image-1.png', 'mimeType' => 'image/png', 'size' => 2048, 'origin' => 'generated'];
		$this->assertSame([$image], $this->stored[1][7]);

		$data = $response->getData();
		$this->assertSame('turn-2', $data['assistantTurn']['id']);
		$this->assertSame([$image], $data['assistantTurn']['attachments']);
		$this->assertSame('turn-1', $data['userTurn']['id']);
	}//end testTheImageIsTheAnswerOnTheSession()

	/**
	 * A listed participant may use the action in a shared session.
	 *
	 * @return void
	 */
	public function testAParticipantMayCreateAnImageInASharedSession(): void {
		$response = $this->controller('bram')->create(sessionId: 'sess-1', prompt: 'Een kaart van de wijk');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('bram', $this->stored[0][5]);
	}//end testAParticipantMayCreateAnImageInASharedSession()

	/**
	 * Someone outside the session, or a session that does not exist, is refused and nothing is created.
	 *
	 * @return void
	 */
	public function testSomeoneOutsideTheSessionIsRefused(): void {
		$this->assertSame(403, $this->controller('carla')->create(sessionId: 'sess-1', prompt: 'Een kaart')->getStatus());
		$this->assertSame(404, $this->controller('anne')->create(sessionId: 'sess-404', prompt: 'Een kaart')->getStatus());
		$this->assertSame(401, $this->controller(null)->create(sessionId: 'sess-1', prompt: 'Een kaart')->getStatus());
		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->stored);
	}//end testSomeoneOutsideTheSessionIsRefused()

	/**
	 * An empty description is refused before anything runs.
	 *
	 * @return void
	 */
	public function testAnEmptyDescriptionIsRefused(): void {
		$response = $this->controller('anne')->create(sessionId: 'sess-1', prompt: '   ');

		$this->assertSame(400, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
		$this->assertSame([], $this->asked);
	}//end testAnEmptyDescriptionIsRefused()

	/**
	 * A refusal from the service is answered with its code and stores no turn.
	 *
	 * @return void
	 */
	public function testARefusalStoresNoTurn(): void {
		$cases = [
			409 => new ImageGenerationException('feature_not_enabled', 'Image creation must be enabled.'),
			503 => new ImageGenerationException('provider_unavailable', 'No text-to-image provider is available.'),
			502 => new ImageGenerationException('marking_failed', 'The image could not be marked.'),
		];
		foreach ($cases as $status => $failure) {
			$response = $this->controller('anne', $failure)->create(sessionId: 'sess-1', prompt: 'Een kaart');

			$this->assertSame($status, $response->getStatus(), $failure->errorCode);
			$this->assertSame($failure->errorCode, $response->getData()['code']);
			$this->assertSame($failure->getMessage(), $response->getData()['error']);
		}

		$this->assertSame([], $this->stored);
	}//end testARefusalStoresNoTurn()

	/**
	 * The page asks whether to show the action; nobody signed in gets no.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-no-provider-no-button
	 */
	public function testThePageIsToldWhetherToShowTheAction(): void {
		$this->assertSame(['available' => true], $this->controller('anne')->availability()->getData());
		$this->assertSame(['available' => false], $this->controller('anne', null, false)->availability()->getData());
		$this->assertSame(['available' => false], $this->controller(null)->availability()->getData());
	}//end testThePageIsToldWhetherToShowTheAction()
}//end class
