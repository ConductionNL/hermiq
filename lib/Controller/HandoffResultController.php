<?php

/**
 * Hermiq HandoffResultController.
 *
 * The one-time address a webhook workflow posts its result to
 * (flows-hand-off-and-wait, REQ-HANDOFF-004). `#[PublicPage]` is deliberate:
 * the caller is a workflow on another host, not a Nextcloud session. The
 * method body is the access check: the handoff's single-use token, compared
 * against its stored hash. Every refusal (unknown handoff, wrong token, a
 * repeat, a closed handoff, another kind) answers the same 404, so the
 * address leaks nothing. The body is capped at 1 MB before it is read.
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
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Handoff\WebhookHandoffs;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Accepts a webhook workflow's late result once, with its token.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
 */
class HandoffResultController extends Controller {

	/**
	 * The header the workflow sends its token in.
	 */
	public const TOKEN_HEADER = 'X-Hermiq-Result-Token';

	/**
	 * The body field a workflow may send its token in instead of the header.
	 */
	public const TOKEN_FIELD = 'hermiqResultToken';

	/**
	 * The largest result body accepted, in bytes.
	 */
	public const MAX_BODY_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request  The request.
	 * @param WebhookHandoffs $webhooks Checks the token and closes the handoff.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function __construct(
		IRequest $request,
		private readonly WebhookHandoffs $webhooks,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/handoffs/{id}/result
	 *
	 * Access control: the handoff's single-use result token (header
	 * X-Hermiq-Result-Token, or the body field hermiqResultToken), checked
	 * against its stored hash; no session.
	 *
	 * @param string $id The handoff the address names.
	 *
	 * @return JSONResponse 202 when the result closed the handoff; 404 for every refusal; 413 over 1 MB; 429 via AnonRateLimit.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function post(string $id): JSONResponse {
		if ((int) $this->request->getHeader('Content-Length') > self::MAX_BODY_BYTES) {
			return new JSONResponse(['error' => 'payload_too_large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		$raw = $this->readRawBody();
		if (strlen($raw) > self::MAX_BODY_BYTES) {
			return new JSONResponse(['error' => 'payload_too_large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		$result = $this->decoded(raw: $raw);
		$token  = trim((string) $this->request->getHeader(self::TOKEN_HEADER));
		if ($token === '' && is_array($result) === true && is_string($result[self::TOKEN_FIELD] ?? null) === true) {
			$token = trim($result[self::TOKEN_FIELD]);
		}

		if (is_array($result) === true) {
			unset($result[self::TOKEN_FIELD]);
		}

		if ($this->webhooks->acceptResult(handoffId: $id, token: $token, result: $result) === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['accepted' => true], Http::STATUS_ACCEPTED);
	}//end post()

	/**
	 * The body as JSON when it is JSON, else the text as it arrived; null when empty.
	 *
	 * @param string $raw The raw body.
	 *
	 * @return mixed
	 */
	private function decoded(string $raw): mixed {
		if (trim($raw) === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $raw;
		}

		return $decoded;
	}//end decoded()

	/**
	 * The raw request body.
	 *
	 * @return string
	 */
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;
	}//end readRawBody()
}//end class
