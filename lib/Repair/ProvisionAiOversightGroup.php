<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Repair
 * @package   OCA\Hermiq\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\AiOversightAccess;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Creates the AI-oversight group when it is missing.
 *
 * The Approval schema's read rule names the group, and members read every
 * advisory record. Without it only administrators hold the role. The step
 * creates the group empty, never adds members, and leaves an existing group
 * untouched, so it is safe on every upgrade.
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class ProvisionAiOversightGroup implements IRepairStep {


	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager The group manager.
	 * @param LoggerInterface $logger       The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()


	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
	 */
	public function getName(): string {
		return 'Create the AI oversight group';

	}//end getName()


	/**
	 * Create the group when it does not exist.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
	 */
	public function run(IOutput $output): void {
		$groupId = AiOversightAccess::OVERSIGHT_GROUP;
		if ($this->groupManager->groupExists($groupId) === true) {
			return;
		}

		if ($this->groupManager->createGroup($groupId) === null) {
			// A backend can refuse group creation (LDAP-only setups). Say so:
			// until an admin creates it, only administrators hold the role.
			$output->warning(
				'Hermiq: could not create group "' . $groupId . '"; until an admin creates it, '
				. 'only administrators read every AI oversight record.'
			);
			$this->logger->warning('Hermiq: AI oversight group creation refused by the backend', ['group' => $groupId]);
			return;
		}

		$output->info('Hermiq: created group "' . $groupId . '" for AI oversight.');

	}//end run()


}//end class
