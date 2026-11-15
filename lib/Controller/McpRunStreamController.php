<?php

/**
 * Hermiq McpRunStreamController: answers the MCP client's GET on the run endpoint with 405.
 *
 * The MCP Streamable HTTP transport lets a client open a server-to-client SSE
 * stream with a GET on the MCP endpoint, and the CLI does so on every governed
 * turn. Hermiq's run endpoint offers no such stream; its only route was POST, so
 * the GET fell through to the SPA catch-all, which asked for a login and
 * answered 401: a wasted round trip that booted the page controller each turn.
 * The transport's own answer for "no stream here" is 405 Method Not Allowed,
 * which this gives without touching the token, the run or any tool.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 *
 * @spec openspec/changes/persistent-llm-process/tasks.md#4-incidental-cheap-separate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;

/**
 * The GET side of the governed MCP run endpoint: no server-to-client stream is offered.
 *
 * Public on purpose, like the POST route: the caller is the CLI's MCP client,
 * which holds no Nextcloud session. Nothing is read or returned, so there is
 * nothing to authorise; the per-run token is not consulted and not consumed.
 *
 * @psalm-api
 *
 * @spec openspec/changes/persistent-llm-process/tasks.md#4-incidental-cheap-separate
 */
class McpRunStreamController extends Controller {

	/**
	 * Refuse the stream: 405 with `Allow: POST`, and no body.
	 *
	 * @return Response The 405.
	 *
	 * @spec openspec/changes/persistent-llm-process/tasks.md#4-incidental-cheap-separate
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function refuse(): Response {
		$response = new Response();
		$response->setStatus(Http::STATUS_METHOD_NOT_ALLOWED);
		$response->addHeader('Allow', 'POST');

		return $response;
	}//end refuse()
}//end class
