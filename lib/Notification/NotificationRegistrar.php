<?php

/**
 * Hermiq NotificationRegistrar
 *
 * Register the notifier that renders Hermiq notifications.
 *
 * @category Notification
 * @package  OCA\Hermiq\Notification
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

namespace OCA\Hermiq\Notification;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The Notification component registers its own notifier, so the bootstrap does not name it.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */
final class NotificationRegistrar {

	/**
	 * Register the notifier that renders Hermiq notifications.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		// Renders Hermiq's Nextcloud notifications (talk-delivery): the notification
		// delivery channel and the Talk fallback both raise notifications that this
		// INotifier turns into localised bell-menu entries. See lib/Notification/Notifier.php.
		$context->registerNotifierService(Notifier::class);
	}//end register()
}//end class
