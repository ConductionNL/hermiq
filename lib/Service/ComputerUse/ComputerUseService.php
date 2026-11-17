<?php

/**
 * Hermiq ComputerUseService
 *
 * Runs the four computer tools (tools-browser-and-computer-use D4): a screenshot of a
 * registered machine, and click, type and key on its screen. Every call first needs
 * the AI feature `computer-use` to be enabled; the feature is seeded high-risk and
 * disabled, so the DPO acknowledgement comes before any screen reaches a model, and a
 * tool grant alone never unlocks it. The machine must be registered and switched on.
 * Each call goes through ComputerTargetService, which reaches the machine only
 * through its integriq source. The approval gate for the actions runs before this
 * service is reached (FacadeToolInvoker).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ComputerUse
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
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ComputerUse;

use OCA\Hermiq\Kernel\Contracts\ComputerUseServiceInterface;
use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The computer tools behind the AI feature gate.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
 */
class ComputerUseService implements ComputerUseServiceInterface {

	/**
	 * The AI feature every computer tool call needs.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'computer-use';

	/**
	 * Constructor.
	 *
	 * @param AiFeatureLookup       $features The AI feature register.
	 * @param ComputerTargetService $targets  The registered machines.
	 * @param LoggerInterface       $logger   The logger.
	 */
	public function __construct(
		private readonly AiFeatureLookup $features,
		private readonly ComputerTargetService $targets,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run one computer tool for the person, after the AI feature gate.
	 *
	 * @param string               $uid       The person the run is for.
	 * @param string               $toolId    One of ComputerToolDescriptors::IDS.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The screenshot the machine answered with, or an error envelope.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		unset($uid);
		if ($this->featureEnabled() === false) {
			return self::error(
				code: 'computer_use_not_enabled',
				message: 'Computer use must be enabled as an AI feature before an agent can use a computer. A tool grant alone does not authorise it.'
			);
		}

		$action = self::action(toolId: $toolId, arguments: $arguments);
		if (is_string($action) === true) {
			return self::error(code: 'invalid_arguments', message: $action);
		}

		try {
			$target = $this->target(name: (string)($arguments['target'] ?? ''));
			if ($action === []) {
				$shot = $this->targets->screenshot(id: $target['id']);
			} else {
				$shot = $this->targets->act(id: $target['id'], action: $action);
			}
		} catch (ComputerTargetRefusal $e) {
			return self::error(code: 'computer_refused', message: $e->getMessage());
		}

		return ['target' => (string)($target['name'] ?? ''), 'targetId' => $target['id']] + $shot;
	}//end invoke()

	/**
	 * The action a tool call asks for: [] for a screenshot, the action body, or why it is refused.
	 *
	 * @param string               $toolId    The tool.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed>|string The action, or the refusal message.
	 */
	private static function action(string $toolId, array $arguments): array|string {
		return match ($toolId) {
			ComputerToolDescriptors::SCREENSHOT => [],
			ComputerToolDescriptors::CLICK => self::click(arguments: $arguments),
			ComputerToolDescriptors::TYPE => self::filled(arguments: $arguments, key: 'text', type: 'type'),
			ComputerToolDescriptors::KEY => self::filled(arguments: $arguments, key: 'keys', type: 'key'),
			default => 'Unknown computer tool "' . $toolId . '".',
		};
	}//end action()

	/**
	 * A click at a point on the screen, or why it is refused.
	 *
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed>|string
	 */
	private static function click(array $arguments): array|string {
		$x = ($arguments['x'] ?? null);
		$y = ($arguments['y'] ?? null);
		if (is_int($x) === false || is_int($y) === false || $x < 0 || $y < 0) {
			return 'A click needs x and y as whole numbers of pixels from the top left.';
		}

		$kind = (string)($arguments['kind'] ?? 'click');
		if (in_array($kind, ComputerToolDescriptors::CLICK_KINDS, true) === false) {
			return 'A click is click, double_click or right_click.';
		}

		return ['type' => $kind, 'x' => $x, 'y' => $y];
	}//end click()

	/**
	 * Typing or a key press, or why it is refused.
	 *
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param string               $key       The argument that carries the text: `text` or `keys`.
	 * @param string               $type      The action type: `type` or `key`.
	 *
	 * @return array<string, mixed>|string
	 */
	private static function filled(array $arguments, string $key, string $type): array|string {
		$value = ($arguments[$key] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return 'This action needs ' . $key . '.';
		}

		return ['type' => $type, $key => $value];
	}//end filled()

	/**
	 * The registered machine a call names, by id or by name, switched on.
	 *
	 * @param string $name The `target` argument.
	 *
	 * @return array<string, mixed> The target, with its `id`.
	 *
	 * @throws ComputerTargetRefusal When no machine has that name or it is switched off.
	 */
	private function target(string $name): array {
		$name  = trim($name);
		$found = null;
		foreach ($this->targets->list() as $target) {
			if (self::isNamed(target: $target, name: $name) === true) {
				$found = $target;
				break;
			}
		}

		if ($found === null) {
			throw new ComputerTargetRefusal(message: 'There is no registered machine "' . $name . '".', status: 404);
		}

		if (($found['enabled'] ?? false) !== true) {
			throw new ComputerTargetRefusal(message: 'The machine "' . (string)($found['name'] ?? $name) . '" is switched off.', status: 409);
		}

		return $found;
	}//end target()

	/**
	 * Whether a registered machine carries the given name, by id or by its
	 * name ignoring case. An empty name names no machine.
	 *
	 * @param array<string, mixed> $target The registered machine.
	 * @param string               $name   The trimmed name asked for.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use
	 */
	private static function isNamed(array $target, string $name): bool {
		return $name !== '' && ($target['id'] === $name || strcasecmp((string)($target['name'] ?? ''), $name) === 0);
	}//end isNamed()

	/**
	 * Whether the AI feature is explicitly enabled; a failed lookup is not.
	 *
	 * @return bool
	 */
	private function featureEnabled(): bool {
		try {
			$feature = $this->features->findBySlugForGate(slug: self::FEATURE_SLUG);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ComputerUseService] The feature lookup failed; treated as not enabled',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}

		return ($feature !== null && (($feature->getObject()['enabled'] ?? false) === true));
	}//end featureEnabled()

	/**
	 * The tool error envelope.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()
}//end class
