<?php

/**
 * Hermiq mail read-tool descriptors (nc-mail-read-tools).
 *
 * Held outside `HermiqToolProvider` so that class stays inside its length budget,
 * exactly as the write-tool descriptors are. The DI alias stays singular per
 * ADR-034/035 — this class registers nothing.
 *
 * All three are honestly `readOnlyHint: true`, which has a consequence worth
 * stating rather than discovering: the write default-deny that protects
 * `nc-native-write-tools` does NOT protect these. The AI-feature gate in
 * `MailReadService` is what carries that weight instead.
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\ToolReachResolver;
use OCA\Hermiq\Service\NcNative\MailReadService;

/**
 * Descriptor source for the mail read tools.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */
final class NcMailToolDescriptors {

	/**
	 * The mail read-tool descriptors, in catalogue order.
	 *
	 * `reach: user` is honest — reading changes nothing and sends nothing out. The
	 * new exposure is the INFERENCE path (the body reaches whatever engine the run
	 * uses), which the reach axis has no way to express and which the AI-feature
	 * gate governs instead.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const ALL = [
		[
			'id' => Application::APP_ID . '.listMailAccounts',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'List mail accounts',
			'description' => 'List the acting user\'s own mail accounts (address and display name only — never '
				. 'credentials or server settings). Requires the Mail app and the mail-reading AI feature.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [],
				'required' => [],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => Application::APP_ID . '.listMailMessages',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'List mail messages',
			'description' => 'Page the envelopes of one of the acting user\'s mailboxes — subject, from, to, date, '
				. 'flags and whether attachments exist. Never message bodies. Requires the Mail app and the '
				. 'mail-reading AI feature.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'mailboxId' => ['type' => 'integer', 'description' => 'The mailbox to page.'],
					'page' => ['type' => 'integer', 'description' => 'Page number, 1-based (default 1).'],
					'pageSize' => [
						'type' => 'integer',
						'description' => 'Envelopes per page (default 20, server maximum '
							. MailReadService::MAX_PAGE_SIZE . ' — the maximum cannot be raised).',
					],
				],
				'required' => ['mailboxId'],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
		[
			'id' => Application::APP_ID . '.readMailMessage',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Read mail message',
			'description' => 'Read one of the acting user\'s messages: headers, plain-text body, and attachment '
				. 'metadata (name, size, type) — never attachment contents. Reading does NOT mark the message as '
				. 'read. Requires the Mail app and the mail-reading AI feature.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'id' => ['type' => 'integer', 'description' => 'The message id.'],
					'includeHtml' => [
						'type' => 'boolean',
						'description' => 'Also return the HTML body, returned UNSANITISED and flagged as such. '
							. 'Only ask for it when layout carries meaning, e.g. an invoice table.',
					],
				],
				'required' => ['id'],
			],
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
			'scope' => 'read',
		],
	];

}//end class
