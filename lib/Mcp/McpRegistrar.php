<?php

/**
 * Hermiq McpRegistrar
 *
 * Publish the agent tool provider to OpenRegister.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\AppIdentity;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The Mcp component publishes its own tool provider, so the bootstrap does not name it.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */
final class McpRegistrar {

	/**
	 * Publish the agent tool provider to OpenRegister.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		// NC-native agent tools (nc-native-tools): expose Files/Contacts/Calendar/Deck/email
		// to the agent runtime as an IMcpToolProvider. OpenRegister's McpToolsService
		// discovers per-app providers by the alias OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}
		// AND, as a fallback, by the conventional FQCN OCA\Hermiq\Mcp\HermiqToolProvider —
		// the class is named to match that convention so discovery resolves it even when the
		// alias is not visible from OR's container. See lib/Mcp/HermiqToolProvider.php.
		$context->registerServiceAlias(
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::' . AppIdentity::APP_ID,
			HermiqToolProvider::class
		);
	}//end register()
}//end class
