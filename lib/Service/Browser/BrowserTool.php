<?php

/**
 * Hermiq BrowserTool.
 *
 * The four browser tools (tools-browser-and-computer-use D2). A page reaches the
 * model only as a text snapshot, cut to 20 KB, between the untrusted-content
 * markers `hermiq.webFetch` uses, after the organisation's guardrail input
 * filter: a page that tries to instruct the agent is blocked or marked as the
 * policy says, and the verdict is part of the result the run records.
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


use OCA\Hermiq\Mcp\BrowserToolDescriptors;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Serves hermiq.browserOpen, browserRead, browserClick and browserFill.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-pages-reach-the-model-as-untrusted-content-req-browse-002
 */
class BrowserTool {

	/**
	 * The longest snapshot the model reads, in bytes.
	 *
	 * @var int
	 */
	public const SNAPSHOT_MAX_BYTES = 20480;

	/**
	 * Constructor.
	 *
	 * @param BrowserClient          $client        The browser service.
	 * @param GuardrailPolicyService $guardrails    The organisation's input filter.
	 * @param ObjectService          $objectService Reads the run's agent for its organisation.
	 * @param LoggerInterface        $logger        Logs failures, never a page.
	 */
	public function __construct(
		private readonly BrowserClient $client,
		private readonly GuardrailPolicyService $guardrails,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run one browser tool.
	 *
	 * @param string               $uid       The acting user.
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The arguments, with `agentId` and `conversationId` set by the engine.
	 *
	 * @return array<string, mixed> The page, or an error envelope.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-pages-reach-the-model-as-untrusted-content-req-browse-002
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		$action = match ($toolId) {
			BrowserToolDescriptors::OPEN => 'open',
			BrowserToolDescriptors::READ => 'read',
			BrowserToolDescriptors::CLICK => 'click',
			BrowserToolDescriptors::FILL => 'fill',
			default => null,
		};
		if ($action === null) {
			return self::err(code: 'unknown_tool', message: "Unknown tool id '" . $toolId . "'.");
		}

		$agentId = trim((string)($arguments['agentId'] ?? ''));
		$conversationId = trim((string)($arguments['conversationId'] ?? ''));
		if ($agentId === '' || $conversationId === '') {
			return self::err(code: 'no_conversation', message: 'The browser is available inside an agent conversation only.');
		}

		$own = self::ownArguments(action: $action, arguments: $arguments);
		if (isset($own['error']) === true) {
			return $own;
		}

		$page = $this->perform(action: $action, uid: $uid, agentId: $agentId, conversationId: $conversationId, arguments: $own);
		if (isset($page['error']) === true) {
			return $page;
		}

		return $this->untrusted(page: $page['page'], organisation: $this->organisationOf(agentId: $agentId));
	}//end invoke()

	/**
	 * Run one browser action, turning each failure into the tool's error shape.
	 *
	 * @param string               $action         The browser action.
	 * @param string               $uid            The acting user.
	 * @param string               $agentId        The agent UUID.
	 * @param string               $conversationId The conversation id.
	 * @param array<string, mixed> $arguments      The action's own arguments.
	 *
	 * @return array{page: array<string, mixed>}|array{error: array{code: string, message: string}}
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use
	 */
	private function perform(string $action, string $uid, string $agentId, string $conversationId, array $arguments): array {
		try {
			return ['page' => $this->client->perform(action: $action, uid: $uid, agentId: $agentId, conversationId: $conversationId, arguments: $arguments)];
		} catch (BrowserRefusedException $e) {
			return self::err(code: $e->refusal, message: $e->getMessage());
		} catch (CodeSandboxUnavailableException $e) {
			return self::err(code: 'browser_unavailable', message: $e->getMessage());
		} catch (RuntimeException $e) {
			$this->logger->warning('[BrowserTool] the browser action failed', ['reason' => $e->getMessage()]);
			return self::err(code: 'browser_failed', message: 'The browser could not complete the action.');
		}
	}//end perform()

	/**
	 * The arguments one action sends, or an error envelope.
	 *
	 * @param string               $action    The action.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function ownArguments(string $action, array $arguments): array {
		$url = trim((string)($arguments['url'] ?? ''));
		$ref = trim((string)($arguments['ref'] ?? ''));
		$text = $arguments['text'] ?? null;

		if ($action === 'open') {
			if (preg_match('#^https?://#i', $url) !== 1) {
				return self::err(code: 'invalid_argument', message: 'An http or https address is required.');
			}

			return ['url' => $url];
		}

		if ($action === 'read') {
			return [];
		}

		if ($ref === '') {
			return self::err(code: 'invalid_argument', message: 'An element reference from the snapshot is required.');
		}

		if ($action === 'click') {
			return ['ref' => $ref];
		}

		if (is_string($text) === false) {
			return self::err(code: 'invalid_argument', message: 'The text to type is required.');
		}

		return ['ref' => $ref, 'text' => $text];
	}//end ownArguments()

	/**
	 * The page as untrusted content, cut and filtered for the model.
	 *
	 * @param array<string, mixed> $page         The browser service's answer.
	 * @param string               $organisation The run's organisation, '' for the default policy.
	 *
	 * @return array<string, mixed> The tool result.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-pages-reach-the-model-as-untrusted-content-req-browse-002
	 */
	private function untrusted(array $page, string $organisation): array {
		$url = (string)($page['url'] ?? '');
		$title = (string)($page['title'] ?? '');
		$snapshot = (string)($page['snapshot'] ?? '');
		$truncated = (($page['truncated'] ?? false) === true);
		if (strlen($snapshot) > self::SNAPSHOT_MAX_BYTES) {
			$snapshot = mb_strcut($snapshot, 0, self::SNAPSHOT_MAX_BYTES, 'UTF-8');
			$truncated = true;
		}

		$filter = $this->guardrails->filterInput(policy: $this->guardrails->effectivePolicyFor(organisation: $organisation), text: $snapshot);
		$verdict = ['blocked' => ($filter['blocked'] === true), 'reason' => ($filter['reason'] ?? null)];
		if ($verdict['blocked'] === true) {
			return self::err(code: 'guardrail_blocked', message: 'The page was withheld by the organisation\'s input filter.') + [
				'url' => $url,
				'filter' => $verdict,
			];
		}

		$named = $title;
		if ($named === '') {
			$named = $url;
		}

		return [
			'summary' => 'Opened ' . $named,
			'url' => $url,
			'title' => $title,
			'truncated' => $truncated,
			'content' => WebFetchService::UNTRUSTED_BEGIN . "\nSource: {$url}\n\n" . (string)$filter['text'] . "\n" . WebFetchService::UNTRUSTED_END,
			'filter' => $verdict,
		];
	}//end untrusted()

	/**
	 * The organisation of the run's agent, '' when it cannot be read.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return string
	 */
	private function organisationOf(string $agentId): string {
		try {
			$agent = $this->objectService->find(id: $agentId, options: new ObjectFindOptions(register: 'hermiq', schema: 'agent'));
		} catch (Throwable $e) {
			$this->logger->warning('[BrowserTool] the run\'s agent could not be read', ['reason' => $e->getMessage()]);
			return '';
		}

		if (($agent instanceof ObjectEntity) === false) {
			return '';
		}

		return (string)($agent->getOrganisation() ?? '');
	}//end organisationOf()

	/**
	 * An error envelope.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function err(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end err()
}//end class
