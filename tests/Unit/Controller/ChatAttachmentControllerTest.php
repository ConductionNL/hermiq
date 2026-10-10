<?php

/**
 * `POST /api/chat/attachments` answers 200 with the stored file, 400 with an
 * error for no file or a refused one, and 401 signed out
 * (chat-attachments-and-images, task 1). The route itself is asserted from
 * appinfo/routes.php, so the companion's call has somewhere to land.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatAttachmentController;
use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Chat\AttachmentStore;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ChatAttachmentController.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */
class ChatAttachmentControllerTest extends TestCase {

	/**
	 * The controller for a person (or nobody) posting an upload.
	 *
	 * @param string|null          $uid    The signed-in user.
	 * @param array<string, mixed> $upload The `file` upload slot.
	 * @param AttachmentStore      $store  The store double.
	 *
	 * @return ChatAttachmentController
	 */
	private function controller(?string $uid, array $upload, AttachmentStore $store): ChatAttachmentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn($upload);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ChatAttachmentController(
			appName: 'hermiq',
			request: $request,
			userSession: $session,
			store: $store,
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A stored upload answers 200 with the store's fields.
	 *
	 * @return void
	 */
	public function testAnUploadAnswersWithTheStoredFile(): void {
		$upload = ['name' => 'offerte.pdf', 'tmp_name' => '/tmp/x', 'size' => 10, 'error' => UPLOAD_ERR_OK];
		$stored = ['path' => '/Hermiq/Attachments/2026-10/offerte.pdf', 'name' => 'offerte.pdf', 'fileId' => 5, 'mimeType' => 'application/pdf', 'size' => 10];
		$store = $this->createMock(AttachmentStore::class);
		$store->expects($this->once())->method('store')->with('anna', $upload)->willReturn($stored);

		$response = $this->controller('anna', $upload, $store)->upload();

		self::assertSame(200, $response->getStatus());
		self::assertSame($stored, $response->getData());
	}//end testAnUploadAnswersWithTheStoredFile()

	/**
	 * A refusal is a 400 with the store's message.
	 *
	 * @return void
	 */
	public function testARefusalIsA400WithTheReason(): void {
		$store = $this->createMock(AttachmentStore::class);
		$store->method('store')->willThrowException(new AttachmentRefusedException('This file is larger than 20 MB.'));

		$response = $this->controller('anna', ['name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'size' => 1, 'error' => UPLOAD_ERR_OK], $store)->upload();

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'This file is larger than 20 MB.'], $response->getData());
	}//end testARefusalIsA400WithTheReason()

	/**
	 * No file, or a failed upload, is a 400 and the store is not called.
	 *
	 * @return void
	 */
	public function testNoFileIsA400(): void {
		$store = $this->createMock(AttachmentStore::class);
		$store->expects($this->never())->method('store');

		$response = $this->controller('anna', ['error' => UPLOAD_ERR_NO_FILE], $store)->upload();

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'No file was uploaded.'], $response->getData());
	}//end testNoFileIsA400()

	/**
	 * Signed out is a 401 and the store is not called.
	 *
	 * @return void
	 */
	public function testSignedOutIsA401(): void {
		$store = $this->createMock(AttachmentStore::class);
		$store->expects($this->never())->method('store');

		$response = $this->controller(null, ['name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'size' => 1, 'error' => UPLOAD_ERR_OK], $store)->upload();

		self::assertSame(401, $response->getStatus());
	}//end testSignedOutIsA401()

	/**
	 * A failure writing to Files is a 500 without the internal message.
	 *
	 * @return void
	 */
	public function testAWriteFailureIsA500WithoutDetails(): void {
		$store = $this->createMock(AttachmentStore::class);
		$store->method('store')->willThrowException(new RuntimeException('disk /var/www full'));

		$response = $this->controller('anna', ['name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'size' => 1, 'error' => UPLOAD_ERR_OK], $store)->upload();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'The file could not be saved.'], $response->getData());
	}//end testAWriteFailureIsA500WithoutDetails()

	/**
	 * The route the companion calls exists, POST, on this controller's upload(), for any signed-in user.
	 *
	 * @return void
	 */
	public function testTheRouteIsRegisteredForSignedInUsers(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = array_values(array_filter(
			$routes['routes'],
			static fn (array $route): bool => ($route['url'] ?? '') === '/api/chat/attachments'
		));

		self::assertCount(1, $found);
		self::assertSame('chatAttachment#upload', $found[0]['name']);
		self::assertSame('POST', $found[0]['verb']);

		$method = new ReflectionMethod(ChatAttachmentController::class, 'upload');
		$names = array_map(static fn ($attribute): string => $attribute->getName(), $method->getAttributes());
		self::assertContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $names);
		self::assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $names);
	}//end testTheRouteIsRegisteredForSignedInUsers()
}//end class
