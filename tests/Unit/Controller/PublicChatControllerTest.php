<?php

/**
 * The public chat endpoint: origin bound, limited, and answering without a sign-in.
 *
 * Pins delivery-public-web-chat task 4 (REQ-PUBCHAT-003).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\PublicChatController;
use OCA\Hermiq\Service\PublicChat\PublicAnswer;
use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatRefusedException;
use OCA\Hermiq\Service\PublicChat\PublicChatService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Controller\PublicChatController
 * @uses   \OCA\Hermiq\Service\PublicChat\PublicAnswer
 * @uses   \OCA\Hermiq\Service\PublicChat\PublicChatRefusedException
 */
class PublicChatControllerTest extends TestCase {

	/**
	 * The channel token in every test URL.
	 */
	private const TOKEN = 'Chan0123456789abcdef';

	/**
	 * What the turn received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $turns = [];

	/**
	 * The controller for a request.
	 *
	 * @param array<string, mixed>        $params  Request parameters.
	 * @param string                      $origin  The Origin header.
	 * @param PublicAnswer|\Throwable|null $outcome What the turn answers or throws.
	 * @param LoggerInterface|null        $logger  The logger.
	 *
	 * @return PublicChatController
	 */
	private function controller(array $params=[], string $origin='https://www.example.nl', PublicAnswer|\Throwable|null $outcome=null, ?LoggerInterface $logger=null): PublicChatController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === 'Origin' ? $origin : ''));
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getServerHost')->willReturn('cloud.example.nl');

		$store = $this->createMock(PublicChannelStore::class);
		$store->method('channelByToken')->willReturnCallback(
			fn (string $token): ?array => ($token === self::TOKEN ? ['id' => 'ch-1', 'kind' => 'website', 'allowedOrigins' => ['https://WWW.example.nl/', 'not an origin', 42]] : null)
		);
		$store->method('isToken')->willReturnCallback(fn (string $t): bool => preg_match('/^[A-Za-z0-9]{16,64}$/', $t) === 1);

		$chat = $this->createMock(PublicChatService::class);
		$chat->method('answer')->willReturnCallback(function (array $channel, string $question, string $locale, ?array $sourceScope, ?string $conversationToken) use ($outcome): PublicAnswer {
			$this->turns[] = ['channel' => $channel['id'], 'question' => $question, 'locale' => $locale, 'scope' => $sourceScope, 'token' => $conversationToken];
			if ($outcome instanceof \Throwable) {
				throw $outcome;
			}

			return ($outcome ?? new PublicAnswer(answer: 'Monday.', sources: [['title' => 'Waste', 'url' => 'https://www.example.nl/waste']], conversationToken: 'Conv0123456789abcdef', disclosure: 'AI.'));
		});

		return new PublicChatController($request, $store, $chat, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end controller()

	/**
	 * The headers a response set itself; getHeaders() adds server defaults a unit run has no server for.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private static function headersOf(\OCP\AppFramework\Http\Response $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * An allowed origin is answered, with CORS for exactly that origin.
	 *
	 * @return void
	 */
	public function testAnAllowedOriginIsAnsweredWithCors(): void {
		$response = $this->controller(params: ['question' => 'When?', 'locale' => 'nl_NL', 'conversationToken' => 'Conv0123456789abcdef'])->message(channelToken: self::TOKEN);

		self::assertSame(200, $response->getStatus());
		self::assertSame('Monday.', $response->getData()['answer']);
		self::assertSame('https://www.example.nl', self::headersOf($response)['Access-Control-Allow-Origin']);
		self::assertSame('Origin', self::headersOf($response)['Vary']);
		self::assertSame([['channel' => 'ch-1', 'question' => 'When?', 'locale' => 'nl_NL', 'scope' => null, 'token' => 'Conv0123456789abcdef']], $this->turns);
	}//end testAnAllowedOriginIsAnsweredWithCors()

	/**
	 * Another website gets no CORS permission and its message is not answered.
	 *
	 * @return void
	 */
	public function testAnotherWebsiteIsRefusedWithoutCors(): void {
		$response = $this->controller(params: ['question' => 'When?'], origin: 'https://other.example.org')->message(channelToken: self::TOKEN);

		self::assertSame(403, $response->getStatus());
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', self::headersOf($response));
		self::assertSame([], $this->turns);

		$preflight = $this->controller(origin: 'https://other.example.org')->preflight(channelToken: self::TOKEN);
		self::assertSame(403, $preflight->getStatus());
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', self::headersOf($preflight));
	}//end testAnotherWebsiteIsRefusedWithoutCors()

	/**
	 * The preflight tells an allowed origin it may post; no origin or an unknown channel gets nothing.
	 *
	 * @return void
	 */
	public function testThePreflight(): void {
		$response = $this->controller()->preflight(channelToken: self::TOKEN);

		self::assertSame(204, $response->getStatus());
		$headers = self::headersOf($response);
		self::assertSame('https://www.example.nl', $headers['Access-Control-Allow-Origin']);
		self::assertSame('POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
		self::assertSame('Content-Type', $headers['Access-Control-Allow-Headers']);

		self::assertSame(403, $this->controller(origin: '')->preflight(channelToken: self::TOKEN)->getStatus());
		self::assertSame(403, $this->controller()->preflight(channelToken: 'Unknown0123456789abc')->getStatus());
	}//end testThePreflight()

	/**
	 * The framed page on this instance and a request without an Origin are answered without CORS headers.
	 *
	 * @return void
	 */
	public function testOwnOriginAndNoOriginAreAnsweredWithoutCors(): void {
		foreach (['https://cloud.example.nl', ''] as $origin) {
			$response = $this->controller(params: ['question' => 'Hi', 'locale' => 'x"; drop', 'conversationToken' => ['not', 'a', 'token']], origin: $origin)->message(channelToken: self::TOKEN);
			self::assertSame(200, $response->getStatus());
			self::assertArrayNotHasKey('Access-Control-Allow-Origin', self::headersOf($response));
		}

		self::assertSame('en', $this->turns[0]['locale']);
		self::assertNull($this->turns[0]['token']);
	}//end testOwnOriginAndNoOriginAreAnsweredWithoutCors()

	/**
	 * An unknown channel is 404; a refused turn answers its status; a failure is logged as 503.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		$unknown = $this->controller()->message(channelToken: 'Unknown0123456789abc');
		self::assertSame(404, $unknown->getStatus());
		self::assertSame(['message' => 'This assistant is not available right now.', 'available' => false], $unknown->getData());

		$limited = $this->controller(params: ['question' => 'Hi'], outcome: new PublicChatRefusedException(message: PublicChatRefusedException::LIMIT_REACHED, status: 429))->message(channelToken: self::TOKEN);
		self::assertSame(429, $limited->getStatus());
		self::assertSame(['message' => PublicChatRefusedException::LIMIT_REACHED, 'available' => false], $limited->getData());
		self::assertSame('https://www.example.nl', self::headersOf($limited)['Access-Control-Allow-Origin']);

		$tooLong = $this->controller(params: ['question' => ''], outcome: new PublicChatRefusedException(message: 'Ask a question of at most 2000 characters.', status: 400))->message(channelToken: self::TOKEN);
		self::assertSame(400, $tooLong->getStatus());
		self::assertTrue($tooLong->getData()['available']);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$failed = $this->controller(params: ['question' => 'Hi'], outcome: new RuntimeException('model down'), logger: $logger)->message(channelToken: self::TOKEN);
		self::assertSame(503, $failed->getStatus());
		self::assertStringNotContainsString('model down', (string)json_encode($failed->getData()));
	}//end testRefusalsAndFailures()

	/**
	 * The endpoint is public, needs no CSRF token, and is rate limited for anonymous and signed-in clients.
	 *
	 * @return void
	 */
	public function testTheEndpointIsPublicAndRateLimited(): void {
		$method = new ReflectionMethod(PublicChatController::class, 'message');
		$names  = array_map(static fn ($a): string => $a->getName(), $method->getAttributes());
		self::assertContains(PublicPage::class, $names);
		self::assertContains(NoCSRFRequired::class, $names);

		$anon = $method->getAttributes(AnonRateLimit::class)[0]->getArguments();
		$user = $method->getAttributes(UserRateLimit::class)[0]->getArguments();
		self::assertSame(['limit' => 20, 'period' => 60], $anon);
		self::assertSame(['limit' => 20, 'period' => 60], $user);
	}//end testTheEndpointIsPublicAndRateLimited()

	/**
	 * Allowed origins are exact origins: scheme and host, no path, case and trailing slash ignored.
	 *
	 * @return void
	 */
	public function testAllowedOrigins(): void {
		self::assertSame(
			['https://www.example.nl', 'http://localhost:8080'],
			PublicChatController::allowedOrigins(channel: ['allowedOrigins' => ['https://WWW.example.nl/', 'http://localhost:8080', 'https://x.nl/path', 'ftp://x.nl', '*', null]])
		);
		self::assertSame([], PublicChatController::allowedOrigins(channel: []));
	}//end testAllowedOrigins()
}//end class
