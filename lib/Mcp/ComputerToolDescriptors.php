<?php

/**
 * Hermiq ComputerToolDescriptors.
 *
 * The four governed tools that operate a desktop on a registered machine
 * (tools-browser-and-computer-use D4): a screenshot, and click, type and key on the
 * screen. Each names a ComputerTarget. All reach external, because the screen's
 * content goes to the model provider; the three actions are destructive. They are
 * listed only on a provider path that carries images in a tool result, and every
 * call first needs the computer-use AI feature (ComputerUseService).
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * The computer tool descriptors.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
 */
final class ComputerToolDescriptors {

	/**
	 * The screenshot tool id.
	 *
	 * @var string
	 */
	public const SCREENSHOT = AppIdentity::APP_ID . '.computerScreenshot';

	/**
	 * The click tool id.
	 *
	 * @var string
	 */
	public const CLICK = AppIdentity::APP_ID . '.computerClick';

	/**
	 * The type tool id.
	 *
	 * @var string
	 */
	public const TYPE = AppIdentity::APP_ID . '.computerType';

	/**
	 * The key tool id.
	 *
	 * @var string
	 */
	public const KEY = AppIdentity::APP_ID . '.computerKey';

	/**
	 * Every computer tool id.
	 *
	 * @var list<string>
	 */
	public const IDS = [self::SCREENSHOT, self::CLICK, self::TYPE, self::KEY];

	/**
	 * The tools that change the machine: each always needs a human approval.
	 *
	 * @var list<string>
	 */
	public const ACTION_IDS = [self::CLICK, self::TYPE, self::KEY];

	/**
	 * The click kinds the remote-control service accepts.
	 *
	 * @var list<string>
	 */
	public const CLICK_KINDS = ['click', 'double_click', 'right_click'];

	/**
	 * The `target` argument every tool takes.
	 *
	 * @var array<string, string>
	 */
	private const TARGET = ['type' => 'string', 'description' => 'The name or id of the registered machine (ComputerTarget).'];

	/**
	 * The four descriptors.
	 *
	 * @var list<array<string, mixed>>
	 */
	public const ALL = [
		[
			'id' => self::SCREENSHOT,
			'subject' => 'computer',
			'action' => 'get',
			'reach' => ToolReachResolver::REACH_EXTERNAL,
			'name' => 'Computer screenshot',
			'description' => 'Take a screenshot of a registered machine and return it as an image with the screen size. '
				. 'The screen is shown to the model; it is never stored.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => ['target' => self::TARGET],
				'required' => ['target'],
			],
			...ToolHints::READ,
		],
		[
			'id' => self::CLICK,
			'subject' => 'computer',
			'action' => 'click',
			'reach' => ToolReachResolver::REACH_EXTERNAL,
			'name' => 'Computer click',
			'description' => 'Click on the screen of a registered machine at x, y (pixels from the top left of the last '
				. 'screenshot). kind is click, double_click or right_click. Needs a human approval every time. '
				. 'Returns a new screenshot.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'target' => self::TARGET,
					'x' => ['type' => 'integer', 'description' => 'Pixels from the left.'],
					'y' => ['type' => 'integer', 'description' => 'Pixels from the top.'],
					'kind' => ['type' => 'string', 'enum' => self::CLICK_KINDS, 'description' => 'The kind of click; click by default.'],
				],
				'required' => ['target', 'x', 'y'],
			],
			...ToolHints::DESTRUCTIVE_UPDATE,
		],
		[
			'id' => self::TYPE,
			'subject' => 'computer',
			'action' => 'type',
			'reach' => ToolReachResolver::REACH_EXTERNAL,
			'name' => 'Computer type',
			'description' => 'Type text into the focused field on a registered machine. Needs a human approval every time. '
				. 'Returns a new screenshot.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'target' => self::TARGET,
					'text' => ['type' => 'string', 'description' => 'The text to type.'],
				],
				'required' => ['target', 'text'],
			],
			...ToolHints::DESTRUCTIVE_UPDATE,
		],
		[
			'id' => self::KEY,
			'subject' => 'computer',
			'action' => 'key',
			'reach' => ToolReachResolver::REACH_EXTERNAL,
			'name' => 'Computer key',
			'description' => 'Press a key or a key combination on a registered machine, for example Enter, Tab or ctrl+s. '
				. 'Needs a human approval every time. Returns a new screenshot.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'target' => self::TARGET,
					'keys' => ['type' => 'string', 'description' => 'The key or combination, joined with +.'],
				],
				'required' => ['target', 'keys'],
			],
			...ToolHints::DESTRUCTIVE_UPDATE,
		],
	];

	/**
	 * Whether a chat driver carries images in a tool result, so the computer tools may be offered.
	 *
	 * Only the Anthropic Messages API over HTTP does today; the CLI runner and every
	 * other provider would hand the model a screenshot it cannot see.
	 *
	 * @param string $provider      The driver's provider.
	 * @param string $executionMode The driver's transport (`http` or `cli`).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
	 */
	public static function carriesImages(string $provider, string $executionMode): bool {
		return $provider === 'anthropic' && $executionMode === 'http';
	}//end carriesImages()

	/**
	 * A turn's function list without the computer tools when its driver cannot carry images.
	 *
	 * @param array<int|string, mixed> $functions     Flattened LLPhant function descriptors.
	 * @param string                   $provider      The driver's provider.
	 * @param string                   $executionMode The driver's transport.
	 *
	 * @return array<int|string, mixed> The functions the turn may offer.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#scenario-a-text-only-model-does-not-see-the-tools
	 */
	public static function forDriver(array $functions, string $provider, string $executionMode): array {
		if (self::carriesImages(provider: $provider, executionMode: $executionMode) === true) {
			return $functions;
		}

		return array_values(
			array_filter(
				$functions,
				static fn (mixed $function): bool => is_array($function) === false
					|| in_array((string)($function['mcpId'] ?? $function['name'] ?? ''), self::IDS, true) === false
			)
		);
	}//end forDriver()
}//end class
