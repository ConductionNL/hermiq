<?php

/**
 * Minimal OpenRegister ToolRegistrationEvent stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Event/ToolRegistrationEvent.php,
 * except that the real constructor takes a `ToolRegistry` this stub does not
 * reproduce: nothing in hermiq constructs the event, it only listens for one, so
 * the registry is deliberately typed loosely rather than stubbed as a second
 * fake nobody exercises. Registered at TEST TIME only by tests/bootstrap.php and
 * scanned (never executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Tool\ToolInterface;
use OCP\EventDispatcher\Event;

/**
 * Minimal ToolRegistrationEvent stub: the hook an app contributes tools through.
 */
class ToolRegistrationEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param object|null $registry OpenRegister's tool registry.
	 */
	public function __construct(private readonly ?object $registry = null) {
		parent::__construct();
	}//end __construct()

	/**
	 * Register a tool.
	 *
	 * @param string $id Unique tool identifier, as {app}.{tool}.
	 * @param ToolInterface $tool The tool implementation.
	 * @param array<string,mixed> $metadata Name, description, icon and app.
	 *
	 * @return void
	 */
	public function registerTool(string $id, ToolInterface $tool, array $metadata): void {
	}//end registerTool()
}//end class
