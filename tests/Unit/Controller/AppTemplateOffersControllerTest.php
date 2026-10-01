<?php

/**
 * Unit tests for AppTemplateOffersController (agents-bound-to-their-app, task 6).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AppTemplateOffersController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AppTemplateOffers;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * "Check apps for templates" on the Store: the reviewers of quarantined templates may run it.
 */
final class AppTemplateOffersControllerTest extends TestCase {

	public function testUnauthenticatedIs401AndCollectsNothing(): void {
		$offers = $this->createMock(AppTemplateOffers::class);
		$offers->expects(self::never())->method('collect');

		$response = $this->controller(offers: $offers, user: false)->collect();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testUnauthenticatedIs401AndCollectsNothing()

	public function testACallerWhoMayNotReviewTemplatesIs403AndCollectsNothing(): void {
		$offers = $this->createMock(AppTemplateOffers::class);
		$offers->expects(self::never())->method('collect');
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willThrowException(new OCSForbiddenException('Not allowed'));

		$response = $this->controller(offers: $offers, auth: $auth)->collect();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACallerWhoMayNotReviewTemplatesIs403AndCollectsNothing()

	public function testAReviewerGetsTheCounts(): void {
		$counts = ['imported' => 1, 'updated' => 0, 'unchanged' => 3, 'refused' => 0];
		$offers = $this->createMock(AppTemplateOffers::class);
		$offers->expects(self::once())->method('collect')->willReturn($counts);
		$auth = $this->createMock(ActionAuthService::class);
		$auth->expects(self::once())->method('requireAction')->with(self::anything(), 'agenttemplate.approve-quarantined');

		$response = $this->controller(offers: $offers, auth: $auth)->collect();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($counts, $response->getData());
	}//end testAReviewerGetsTheCounts()

	public function testAFailingCollectIs500WithoutDetail(): void {
		$offers = $this->createMock(AppTemplateOffers::class);
		$offers->method('collect')->willThrowException(new RuntimeException('secret path /var/www'));

		$response = $this->controller(offers: $offers)->collect();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('/var/www', (string)json_encode($response->getData()));
	}//end testAFailingCollectIs500WithoutDetail()

	/**
	 * The controller under test.
	 *
	 * @param AppTemplateOffers $offers The offers service.
	 * @param ActionAuthService|null $auth The action-auth service, or an allowing mock.
	 * @param bool $user Whether a user is signed in.
	 *
	 * @return AppTemplateOffersController
	 */
	private function controller(AppTemplateOffers $offers, ?ActionAuthService $auth = null, bool $user = true): AppTemplateOffersController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);

		return new AppTemplateOffersController(
			request: $this->createMock(IRequest::class),
			offers: $offers,
			actionAuth: ($auth ?? $this->createMock(ActionAuthService::class)),
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end controller()
}//end class
