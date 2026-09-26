<?php

/**
 * Unit tests for MessageTranslationController (message-translation-delegate).
 *
 * Asserts the auth-first, validate-before-engine shape (spec.md REQ-005): an
 * unauthenticated caller is refused 401 before any parameter is even read, a
 * missing `sourceText`/`targetLanguage` is refused 400 before the engine is
 * ever invoked, and a valid request returns the engine's result verbatim.
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
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-005-the-rest-endpoint-requires-authentication-and-validates-its-input
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\MessageTranslationController;
use OCA\Hermiq\Service\MessageTranslationEngine;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the message-translation REST endpoint's auth/validation shell.
 *
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-005-the-rest-endpoint-requires-authentication-and-validates-its-input
 */
class MessageTranslationControllerTest extends TestCase {

	/**
	 * A session with the given (or no) user.
	 *
	 * @param string|null $uid The UID, or null for unauthenticated.
	 *
	 * @return IUserSession
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
	 * A request returning the given params for getParam().
	 *
	 * @param array<string, mixed> $params Param name → value.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return array_key_exists($key, $params) ? $params[$key] : $default;
			}
		);

		return $request;
	}//end request()

	/**
	 * Unauthenticated is refused 401 before any parameter is read.
	 *
	 * @return void
	 */
	public function testUnauthenticatedIsRefused(): void {
		$engine = $this->createMock(MessageTranslationEngine::class);
		$engine->expects($this->never())->method('translate');

		$controller = new MessageTranslationController(
			request: $this->request([]),
			userSession: $this->session(uid: null),
			engine: $engine,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $controller->translate();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

	}//end testUnauthenticatedIsRefused()

	/**
	 * Missing sourceText is a 400, engine never invoked.
	 *
	 * @return void
	 */
	public function testMissingSourceTextIsRefused(): void {
		$engine = $this->createMock(MessageTranslationEngine::class);
		$engine->expects($this->never())->method('translate');

		$controller = new MessageTranslationController(
			request: $this->request(['targetLanguage' => 'en']),
			userSession: $this->session(uid: 'alice'),
			engine: $engine,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $controller->translate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

	}//end testMissingSourceTextIsRefused()

	/**
	 * Missing targetLanguage is a 400, engine never invoked.
	 *
	 * @return void
	 */
	public function testMissingTargetLanguageIsRefused(): void {
		$engine = $this->createMock(MessageTranslationEngine::class);
		$engine->expects($this->never())->method('translate');

		$controller = new MessageTranslationController(
			request: $this->request(['sourceText' => 'Hallo']),
			userSession: $this->session(uid: 'alice'),
			engine: $engine,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $controller->translate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

	}//end testMissingTargetLanguageIsRefused()

	/**
	 * A valid request delegates to the engine and returns its payload verbatim.
	 *
	 * @return void
	 */
	public function testValidRequestReturnsEngineResult(): void {
		$engine = $this->createMock(MessageTranslationEngine::class);
		$engine->expects($this->once())
			->method('translate')
			->with('Hallo', 'en', [])
			->willReturn(['available' => true, 'translatedText' => 'Hello']);

		$controller = new MessageTranslationController(
			request: $this->request(['sourceText' => 'Hallo', 'targetLanguage' => 'en']),
			userSession: $this->session(uid: 'alice'),
			engine: $engine,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $controller->translate();

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$this->assertTrue($result->getData()['available']);
		$this->assertSame('Hello', $result->getData()['translatedText']);

	}//end testValidRequestReturnsEngineResult()

	/**
	 * A glossary array passes through to the engine unchanged.
	 *
	 * @return void
	 */
	public function testGlossaryPassesThroughToEngine(): void {
		$glossary = [['term' => 'trakteren', 'translation' => 'bringing treats to share with the class']];

		$engine = $this->createMock(MessageTranslationEngine::class);
		$engine->expects($this->once())
			->method('translate')
			->with('Hallo', 'en', $glossary)
			->willReturn(['available' => true, 'translatedText' => 'Hello']);

		$controller = new MessageTranslationController(
			request: $this->request(['sourceText' => 'Hallo', 'targetLanguage' => 'en', 'glossary' => $glossary]),
			userSession: $this->session(uid: 'alice'),
			engine: $engine,
			logger: $this->createMock(LoggerInterface::class),
		);

		$controller->translate();

	}//end testGlossaryPassesThroughToEngine()

	/**
	 * An engine exception maps to a 500, not an uncaught error.
	 *
	 * @return void
	 */
	public function testEngineFailureMapsTo500(): void {
		$engine = $this->createMock(MessageTranslationEngine::class);
		$engine->method('translate')->willThrowException(new RuntimeException('boom'));

		$controller = new MessageTranslationController(
			request: $this->request(['sourceText' => 'Hallo', 'targetLanguage' => 'en']),
			userSession: $this->session(uid: 'alice'),
			engine: $engine,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $controller->translate();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());

	}//end testEngineFailureMapsTo500()
}//end class
