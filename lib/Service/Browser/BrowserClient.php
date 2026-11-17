<?php

/**
 * Hermiq BrowserClient.
 *
 * Talks to the browser service of the code sandbox app through AppAPI
 * (tools-browser-and-computer-use D1). Every call carries a fresh run token,
 * minted for the agent, the user and the conversation, that the browser hands to
 * the egress policy decision point for every connection it opens; the PDP is the
 * same one `hermiq.webFetch` and the CLI runners use.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Browser
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
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Browser;

use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\Llm\RunTokenService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The browser service's client.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-the-browser-reaches-only-what-the-egress-policy-allows-req-browse-001
 */
class BrowserClient {

	/**
	 * The actions the browser service answers.
	 *
	 * @var list<string>
	 */
	public const ACTIONS = ['open', 'read', 'click', 'fill', 'close'];

	/**
	 * How long a browser call's run token lives: one action and its page loads.
	 *
	 * @var int
	 */
	public const TOKEN_TTL_SECONDS = 120;

	/**
	 * How long Hermiq waits for one action.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 45;

	/**
	 * Constructor.
	 *
	 * @param RunTokenService    $runTokens Mints the run token the egress PDP checks.
	 * @param LoggerInterface    $logger    Logs reachability, never a page or a token.
	 * @param ContainerInterface $container Resolves AppAPI when it is installed.
	 */
	public function __construct(
		private readonly RunTokenService $runTokens,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Perform one browser action for a conversation.
	 *
	 * @param string               $action         One of {@see self::ACTIONS}.
	 * @param string               $uid            The acting user.
	 * @param string               $agentId        The agent the run belongs to.
	 * @param string               $conversationId The conversation whose browser context is used.
	 * @param array<string, mixed> $arguments      The action's own arguments (url, ref, text).
	 *
	 * @return array<string, mixed> The service's answer: url, title, snapshot, truncated.
	 *
	 * @throws BrowserRefusedException When the browser service refuses the action.
	 * @throws CodeSandboxUnavailableException When no sandbox app is installed or reachable.
	 * @throws RuntimeException When the answer is unreadable.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-the-browser-reaches-only-what-the-egress-policy-allows-req-browse-001
	 */
	public function perform(string $action, string $uid, string $agentId, string $conversationId, array $arguments): array {
		if (in_array($action, self::ACTIONS, true) === false) {
			throw new RuntimeException('Unknown browser action.');
		}

		$appApi = $this->appApi();
		if ($appApi === null) {
			throw new CodeSandboxUnavailableException(message: CodeSandboxUnavailableException::NOT_INSTALLED);
		}

		$token = $this->runTokens->mint(
			runId: 'browser:' . $conversationId,
			agentId: $agentId,
			userId: $uid,
			conversationId: $conversationId,
			ttlSeconds: self::TOKEN_TTL_SECONDS
		);

		$result = $appApi->exAppRequest(
			CodeSandboxClient::EXAPP_ID,
			'/browser/' . $action,
			$uid,
			'POST',
			['conversationId' => $conversationId, 'runToken' => $token] + $arguments,
			['timeout' => self::TIMEOUT_SECONDS]
		);

		// AppAPI never throws: an array answer is its failure channel.
		if (is_array($result) === true) {
			$this->logger->warning('[BrowserClient] the sandbox app could not be reached', ['reason' => (string)($result['error'] ?? 'unknown')]);
			throw new CodeSandboxUnavailableException(message: CodeSandboxUnavailableException::NOT_INSTALLED);
		}

		return self::answerOf(status: (int)$result->getStatusCode(), body: (string)$result->getBody());
	}//end perform()

	/**
	 * Map the browser service's answer.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $body   The body.
	 *
	 * @return array<string, mixed> The decoded answer.
	 *
	 * @throws BrowserRefusedException On a refusal (4xx with a code).
	 * @throws CodeSandboxUnavailableException When the browser service is not there.
	 * @throws RuntimeException On anything else that is not a 2xx JSON object.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-the-browser-reaches-only-what-the-egress-policy-allows-req-browse-001
	 */
	public static function answerOf(int $status, string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		if ($status === 503) {
			throw new CodeSandboxUnavailableException(message: 'The browser service of the code sandbox app is not running.');
		}

		if ($status >= 400 && $status < 500 && is_string($decoded['code'] ?? null) === true) {
			throw new BrowserRefusedException(refusal: $decoded['code'], message: (string)($decoded['error'] ?? 'The browser refused the action.'));
		}

		if ($status < 200 || $status > 299 || $decoded === []) {
			throw new RuntimeException('The browser could not complete the action (HTTP ' . $status . ').');
		}

		return $decoded;
	}//end answerOf()

	/**
	 * AppAPI's public functions, or null when AppAPI is not installed.
	 *
	 * @return object|null
	 */
	private function appApi(): ?object {
		try {
			$appApi = $this->container->get(CodeSandboxClient::APP_API_PUBLIC_FUNCTIONS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($appApi) === false || method_exists($appApi, 'exAppRequest') === false) {
			return null;
		}

		return $appApi;
	}//end appApi()
}//end class
