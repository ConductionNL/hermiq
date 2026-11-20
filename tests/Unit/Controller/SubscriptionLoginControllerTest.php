<?php

/**
 * Hermiq SubscriptionLoginController unit tests.
 *
 * Every route acts for the session user only, and a refusal keeps the status
 * the service named (models-provider-connections Task 5).
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
 * @spec openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SubscriptionLoginController;
use OCA\Hermiq\Service\Credential\SubscriptionLoginException;
use OCA\Hermiq\Service\Credential\SubscriptionLoginService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The sign-in routes.
 */
#[CoversClass(SubscriptionLoginController::class)]
#[UsesClass(SubscriptionLoginException::class)]
final class SubscriptionLoginControllerTest extends TestCase {

	/**
	 * A controller over the given service, for the given session user.
	 *
	 * @param SubscriptionLoginService $service The service.
	 * @param string|null              $uid     The session user, or null.
	 *
	 * @return SubscriptionLoginController
	 */
	private function controller(SubscriptionLoginService $service, ?string $uid): SubscriptionLoginController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return new SubscriptionLoginController($this->createMock(IRequest::class), $service, $session);
	}//end controller()

	/**
	 * Without a session every route is 401 and the service is never asked.
	 *
	 * @return void
	 */
	public function testWithoutASessionNothingIsAsked(): void {
		$service = $this->createMock(SubscriptionLoginService::class);
		$service->expects($this->never())->method($this->anything());
		$controller = $this->controller($service, null);

		$this->assertSame(401, $controller->index()->getStatus());
		$this->assertSame(401, $controller->start('anthropic')->getStatus());
		$this->assertSame(401, $controller->code('id', 'c')->getStatus());
	}//end testWithoutASessionNothingIsAsked()

	/**
	 * The session user, never a parameter, is who the sign-in is for; the
	 * finish answers what the service returned.
	 *
	 * @return void
	 */
	public function testTheSessionUserIsWhoTheSignInIsFor(): void {
		$service = $this->createMock(SubscriptionLoginService::class);
		$service->expects($this->once())->method('start')->with('alice', 'anthropic')
			->willReturn(['loginId' => 'l', 'url' => 'https://claude.ai/x', 'mode' => 'code', 'userCode' => '']);
		$service->expects($this->once())->method('finish')->with('alice', 'l', 'the-code')
			->willReturn(['id' => 'cred-1', 'label' => 'Claude subscription']);
		$controller = $this->controller($service, 'alice');

		$this->assertSame('https://claude.ai/x', $controller->start('anthropic')->getData()['url']);
		$finished = $controller->code('l', 'the-code');
		$this->assertSame(200, $finished->getStatus());
		$this->assertSame(['id' => 'cred-1', 'label' => 'Claude subscription'], $finished->getData());
	}//end testTheSessionUserIsWhoTheSignInIsFor()

	/**
	 * A refusal answers its own status and message; a code outside 4xx/5xx is a 500.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$service = $this->createMock(SubscriptionLoginService::class);
		$service->method('start')->willThrowException(new SubscriptionLoginException('Subscription sign-in needs the Hermiq LLM runner app.', 503));
		$service->method('finish')->willThrowException(new SubscriptionLoginException('odd', 0));
		$controller = $this->controller($service, 'alice');

		$started = $controller->start('anthropic');
		$this->assertSame(503, $started->getStatus());
		$this->assertSame(['message' => 'Subscription sign-in needs the Hermiq LLM runner app.'], $started->getData());
		$this->assertSame(500, $controller->code('l', '')->getStatus());
	}//end testARefusalKeepsItsStatus()
}//end class
