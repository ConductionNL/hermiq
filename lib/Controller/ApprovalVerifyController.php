<?php

/**
 * Hermiq ApprovalVerifyController.
 *
 * The approval verification contract (hermiq#1045): another app (integriq) asks
 * whether a person approved the exact batch it staged, and trusts only the signed
 * verdict. The caller is a server without a user session, so the route is public;
 * it is brute-force protected, and an answer about an unknown approval or another
 * batch counts as an attempt. A `#[PublicPage]` method lives in its own class,
 * never beside session-authenticated ones.
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
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Approval\ApprovalVerdictService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * POST /api/approvals/verify.
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */
class ApprovalVerifyController extends Controller
{

    /**
     * Constructor.
     *
     * @param IRequest               $request  The request.
     * @param ApprovalVerdictService $verdicts Builds and signs the verdict.
     * @param LoggerInterface        $logger   Logs a malformed request.
     *
     * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
     */
    public function __construct(
        IRequest $request,
        private readonly ApprovalVerdictService $verdicts,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Answer {verdict, signature}, HTTP 200 whenever there is a verdict to give.
     *
     * @return JSONResponse 200 with the signed verdict, 400 when a field is missing.
     *
     * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'hermiq_approval_verify')]
    public function verify(): JSONResponse
    {
        $params  = $this->request->getParams();
        $request = array_intersect_key($params, array_flip(ApprovalVerdictService::REQUEST_FIELDS));

        try {
            $answer = $this->verdicts->verify(request: $request);
        } catch (InvalidArgumentException $e) {
            $this->logger->info('Hermiq refused an approval verify request: '.$e->getMessage());
            $response = new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            $response->throttle(['action' => 'hermiq_approval_verify']);
            return $response;
        }

        $response = new JSONResponse($answer, Http::STATUS_OK);
        if (in_array(($answer['verdict']['reason'] ?? ''), ['unknown', 'binding-mismatch'], true) === true) {
            $response->throttle(['action' => 'hermiq_approval_verify']);
        }

        return $response;

    }//end verify()
}//end class
