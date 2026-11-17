<?php

/**
 * Golden tests for AssistantController's request reading and error mapping.
 *
 * Pins, for both endpoints, the exact arguments handed to the service (session id,
 * message or text, context, with their defaults) and the exact error body, status and
 * log level for every status the two error maps know, an unknown one, a code outside
 * 400-599, a guardrail block and an anonymous caller.
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
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use Exception;
use OCA\Hermiq\Controller\AssistantController;
use OCA\Hermiq\Service\Assistant\AssistantService;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the assistant endpoints.
 *
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-2-1
 */
class AssistantControllerErrorsGoldenTest extends TestCase {

	/**
	 * Every service call and log level, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * A controller over the given request params and service behaviour.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param Exception|null $throw What the service throws, or null to answer.
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return AssistantController
	 */
	private function controller(array $params, ?Exception $throw = null, bool $signedIn = true): AssistantController {
		$this->log = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$service = $this->createMock(AssistantService::class);
		foreach (['converse', 'detectPii'] as $method) {
			$service->method($method)->willReturnCallback(
				function (...$args) use ($method, $throw): array {
					$this->log[] = [$method => $args];
					if ($throw !== null) {
						throw $throw;
					}

					return ['ok' => $method];
				}
			);
		}

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (string $message) => $this->log[] = 'warning ' . $message);
		$logger->method('error')->willReturnCallback(fn (string $message) => $this->log[] = 'error ' . $message);

		return new AssistantController($request, $service, $session, $l10n, $logger);
	}//end controller()

	/**
	 * The arguments each endpoint hands the service, with and without params.
	 *
	 * @return void
	 */
	public function testTheServiceArguments(): void {
		$this->controller(['sessionId' => 's-1', 'message' => 'hi', 'context' => ['case' => 'c']])->converse();
		$this->assertSame([['converse' => ['alice', 's-1', 'hi', ['case' => 'c']]]], $this->log);

		$this->controller(['sessionId' => '', 'context' => 'x'])->converse();
		$this->assertSame([['converse' => ['alice', null, '', []]]], $this->log);

		$this->controller(['sessionId' => 5])->converse();
		$this->assertSame(null, $this->log[0]['converse'][1]);

		$this->controller(['text' => 'secret', 'context' => ['a' => 1]])->detectPii();
		$this->assertSame([['detectPii' => ['alice', 'secret', ['a' => 1]]]], $this->log);

		$response = $this->controller([])->detectPii();
		$this->assertSame([['detectPii' => ['alice', '', []]]], $this->log);
		$this->assertSame([200, ['ok' => 'detectPii']], [$response->getStatus(), $response->getData()]);
	}//end testTheServiceArguments()

	/**
	 * Every mapped status of both endpoints, with its body and log level.
	 *
	 * @return void
	 */
	public function testEveryErrorMapping(): void {
		$converse = [
			400 => 'T:Invalid request',
			401 => 'T:Authentication required',
			403 => 'T:Access denied',
			404 => 'T:Session not found',
			422 => 'T:Message blocked by the organisation\'s guardrail policy',
			503 => 'T:AI service not configured',
			409 => 'T:Failed to process message',
			502 => 'T:Failed to process message',
			200 => 'T:Failed to process message',
			700 => 'T:Failed to process message',
		];
		foreach ($converse as $code => $error) {
			$response = $this->controller([], new Exception('why', $code))->converse();
			$status = ($code < 400 || $code >= 600) ? 500 : $code;
			$this->assertSame([$status, ['error' => $error, 'message' => 'why']], [$response->getStatus(), $response->getData()], 'converse ' . $code);
			$level = $status < 500 ? 'warning [AssistantController] Rejected message: why' : 'error [AssistantController] Failed to process message';
			$this->assertSame($level, end($this->log));
		}

		$detect = [
			400 => 'T:Invalid request',
			401 => 'T:Authentication required',
			422 => 'T:Text blocked by the organisation\'s guardrail policy',
			502 => 'T:AI response could not be parsed',
			503 => 'T:AI service not configured',
			403 => 'T:Failed to process text',
			0 => 'T:Failed to process text',
		];
		foreach ($detect as $code => $error) {
			$response = $this->controller([], new Exception('why', $code))->detectPii();
			$status = ($code < 400 || $code >= 600) ? 500 : $code;
			$this->assertSame([$status, ['error' => $error, 'message' => 'why']], [$response->getStatus(), $response->getData()], 'detect ' . $code);
		}
	}//end testEveryErrorMapping()

	/**
	 * A guardrail block carries its code on both endpoints; an anonymous caller is 401.
	 *
	 * @return void
	 */
	public function testGuardrailAndAnonymous(): void {
		$response = $this->controller([], new GuardrailBlockedException('pii'))->converse();
		$this->assertSame(
			[422, ['error' => 'T:Message blocked by the organisation\'s guardrail policy', 'message' => 'Message blocked by guardrail policy (pii)', 'errorCode' => 'guardrail_blocked']],
			[$response->getStatus(), $response->getData()]
		);

		$response = $this->controller([], new GuardrailBlockedException('pii'))->detectPii();
		$this->assertSame('guardrail_blocked', $response->getData()['errorCode']);

		$response = $this->controller([], null, false)->converse();
		$this->assertSame([401, ['error' => 'T:Authentication required', 'message' => 'Authentication required']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['warning [AssistantController] Rejected message: Authentication required'], $this->log);

		$response = $this->controller([], null, false)->detectPii();
		$this->assertSame(401, $response->getStatus());
	}//end testGuardrailAndAnonymous()
}//end class
