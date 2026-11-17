<?php

/**
 * The code step, contributed to OpenRegister's flow engine (tools-code-sandbox).
 *
 * Runs a short Python or JavaScript program in the code sandbox over each
 * item: the item's JSON goes in on stdin, and the one JSON object the program
 * prints on stdout replaces the item's JSON. A program that exits non-zero,
 * times out or prints no JSON object leaves the item as it was with an
 * `error` (message, exit code, the tail of stderr), so a router downstream can
 * read it. A run that cannot happen at all (no sandbox, the AI feature off)
 * throws, and the step's `onError` decides.
 *
 * The time limit is at most 30 seconds because the flow worker advances one
 * run at a time; longer work belongs in a workload step. Like every
 * `hermiq.*` hop, the tenant kill switch refuses it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\RunCodeTool;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\ICallFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use UnexpectedValueException;

/**
 * Runs a code step over each item.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003
 */
class HermiqCodeStepNode implements ICallFlowNode {

	/**
	 * The step's time limit, in seconds.
	 */
	public const DEFAULT_TIMEOUT_SECONDS = 10;
	public const MAX_TIMEOUT_SECONDS = 30;

	/**
	 * How much of stderr a failed item keeps, in bytes.
	 *
	 * @var int
	 */
	public const STDERR_TAIL_BYTES = 2000;

	/**
	 * Constructor.
	 *
	 * @param CodeSandboxClient $client  Sends each run.
	 * @param RunCodeTool       $runCode The AI feature gate it shares with the agent tool.
	 * @param IL10N             $l10n    Translations.
	 * @param IURLGenerator     $urls    For the palette icon.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-4-the-code-step-flow-node
	 */
	public function __construct(
		private readonly CodeSandboxClient $client,
		private readonly RunCodeTool $runCode,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-4-the-code-step-flow-node
	 */
	public function getId(): string {
		return 'hermiq.code-step';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-4-the-code-step-flow-node
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Code step');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-4-the-code-step-flow-node
	 */
	public function getDescription(): string {
		return $this->l10n->t('Run a short Python or JavaScript program over each item in the code sandbox; the JSON it prints replaces the item.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-4-the-code-step-flow-node
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('hermiq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * In the palette only while a sandbox can take runs, in both scopes.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003
	 */
	public function isAvailableForScope(int $scope): bool {
		if (in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true) === false) {
			return false;
		}

		return $this->client->isAvailable();
	}//end isAvailableForScope()

	/**
	 * Refuse a step without a known language or code, or with a time limit
	 * that is not a whole number from 1 to 30.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration cannot run.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-4-the-code-step-flow-node
	 */
	public function validateConfig(array $config): void {
		if (in_array(($config['language'] ?? null), CodeSandboxClient::LANGUAGES, true) === false) {
			throw new UnexpectedValueException('A code step runs python or node.');
		}

		if (is_string($config['code'] ?? null) === false || trim($config['code']) === '') {
			throw new UnexpectedValueException('A code step needs code.');
		}

		$timeout = ($config['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
		if (is_int($timeout) === false || $timeout < 1 || $timeout > self::MAX_TIMEOUT_SECONDS) {
			throw new UnexpectedValueException('A code step runs for 1 to ' . self::MAX_TIMEOUT_SECONDS . ' seconds.');
		}
	}//end validateConfig()

	/**
	 * Run the code over each item.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each replaced by the code's JSON or carrying an `error`.
	 *
	 * @throws UnexpectedValueException When the configuration cannot run.
	 * @throws RuntimeException         When the AI feature is off or no sandbox can take the run.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#scenario-a-flow-author-normalises-postcodes
	 */
	public function execute(FlowNodeCall $call): array {
		$config = $call->config;
		// A flow imported or seeded through another path reaches execute() unvalidated.
		$this->validateConfig(config: $config);
		if ($this->runCode->featureEnabled() === false) {
			throw new RuntimeException(RunCodeTool::NOT_ENABLED);
		}

		$owner = $this->ownerOf(context: $call->context);
		$limits = ['wallSeconds' => (int)($config['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS)];

		$out = [];
		foreach ($call->items as $index => $item) {
			$slot = new FlowItemSlot(item: $item, index: $index);
			$json = $slot->json();
			$result = $this->client->run(
				language: (string)$config['language'],
				code: (string)$config['code'],
				stdin: (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				limits: $limits,
				uid: $owner
			);
			$out[] = $slot->withJson(json: self::outcome(json: $json, result: $result));
		}

		return $out;
	}//end execute()

	/**
	 * The item's JSON after a run: the printed object, or the input with an `error`.
	 *
	 * @param array<string, mixed> $json   The input JSON.
	 * @param array<string, mixed> $result The run result.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-4-the-code-step-flow-node
	 */
	public static function outcome(array $json, array $result): array {
		$exitCode = (int)($result['exitCode'] ?? 1);
		$timedOut = (($result['timedOut'] ?? false) === true);
		$message = null;
		if ($timedOut === true) {
			$message = 'The code ran out of time.';
		} else if ($exitCode !== 0) {
			$message = 'The code exited with ' . $exitCode . '.';
		}

		if ($message === null) {
			$printed = json_decode(trim((string)($result['stdout'] ?? '')), true);
			if (is_array($printed) === true) {
				return $printed;
			}

			$message = 'The code did not print a JSON object.';
		}

		$json['error'] = [
			'message' => $message,
			'exitCode' => $exitCode,
			'timedOut' => $timedOut,
			'stderr' => self::tail(text: (string)($result['stderr'] ?? '')),
		];

		return $json;
	}//end outcome()

	/**
	 * The last bytes of a text.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private static function tail(string $text): string {
		if (strlen($text) <= self::STDERR_TAIL_BYTES) {
			return $text;
		}

		return substr($text, -self::STDERR_TAIL_BYTES);
	}//end tail()

	/**
	 * The user the run acts as, from the run context only, or null.
	 *
	 * @param array $context The run context.
	 *
	 * @return string|null
	 */
	private function ownerOf(array $context): ?string {
		foreach (['runAs', 'triggeredBy'] as $key) {
			$owner = trim((string)($context[$key] ?? ''));
			if ($owner !== '') {
				return $owner;
			}
		}

		return null;
	}//end ownerOf()
}//end class
