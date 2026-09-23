<?php

/**
 * Puts Hermiq's tools into OpenRegister's registry.
 *
 * One registration reaches three consumers, which is why it is worth doing here
 * rather than in Hermiq's own catalogue: the in-app chat's tool loop, the
 * `hermiq.agent-step` flow node, and `McpRunController` — the MCP server the
 * Nextcloud Assistant's ExApp connects to. A tool registered once is callable
 * from all three, under the same per-agent grants, with the same audit.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Tool\GitHubTool;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers Hermiq's tools when OpenRegister builds its catalogue.
 *
 * @template-implements IEventListener<Event>
 */
class ToolRegistrationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GitHubTool $gitHubTool The GitHub tool.
	 */
	public function __construct(
		private readonly GitHubTool $gitHubTool,
	) {
	}//end __construct()

	/**
	 * Register on the catalogue event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if (($event instanceof ToolRegistrationEvent) === false) {
			return;
		}

		$event->registerTool(
			'hermiq.github',
			$this->gitHubTool,
			[
				'name' => 'GitHub',
				'description' => 'Issues, branches, files and pull requests on GitHub, through a brokered credential.',
				'icon' => 'icon-external',
				'app' => 'hermiq',
			]
		);
	}//end handle()
}//end class
