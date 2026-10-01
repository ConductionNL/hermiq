<?php

/**
 * Hermiq AppTemplateOffersController.
 *
 * The Store's "Check apps for templates": runs the same collect as the repair
 * step, on demand, for someone who may review quarantined templates.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AppTemplateOffers;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects app-offered agent templates on demand.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */
class AppTemplateOffersController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param AppTemplateOffers $offers The collect.
	 * @param ActionAuthService $actionAuth ADR-023 action authorization.
	 * @param IUserSession $userSession The signed-in user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AppTemplateOffers $offers,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Ask the installed apps for their templates now.
	 *
	 * Only a caller who may approve quarantined templates may run it: the people
	 * who review the offers are the ones who fetch them.
	 *
	 * @return JSONResponse The counts (imported, updated, unchanged, refused), or an error status.
	 *
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
	 */
	#[NoAdminRequired]
	public function collect(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'agenttemplate.approve-quarantined');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->offers->collect());
		} catch (Throwable $e) {
			$this->logger->error('[hermiq] Collecting app agent templates failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not check the apps for templates'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end collect()
}//end class
