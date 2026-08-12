<?php

/**
 * Hermiq Check OpenRegister Compatibility Repair Step.
 *
 * Hermiq's agent engine (AgentsController, Engine, ToolLoop, ContextRetrievalHandler,
 * FacadeToolInvoker, AgentRunRequestedListener chain) hard-depends on two OpenRegister
 * classes that only exist from a specific OpenRegister commit onward:
 *  - `OCA\OpenRegister\Service\Mcp\ToolRegistryFacade` (OpenRegister #297)
 *  - `OCA\OpenRegister\Event\AgentRunRequestedEvent` (OpenRegister #306)
 *
 * NC's info.xml `<dependencies>` element has no way to pin the version of another
 * Nextcloud app (only php/nextcloud/database/lib/architecture/backend — see the
 * upstream app-info.xsd), so a fleet instance whose OpenRegister is even a few
 * commits behind silently breaks the entire agent engine: NC's DI container throws
 * a bare ReflectionException the moment it tries to construct AgentsController (or
 * any other class type-hinting `ToolRegistryFacade`), with nothing pointing an
 * operator at the real cause. This repair step runs on install/upgrade and turns
 * that into a clear, actionable warning instead — both in the `occ upgrade`/
 * `occ app:install` console output and in the Nextcloud log.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec exclude Live e2e verification finding (2026-07-07): OpenRegister was an
 * undeclared hard dependency, so AgentsController/Engine/ToolLoop failed to
 * construct on a fleet instance running an older OpenRegister. No design change
 * exists for this hotfix; the openspec/architecture ADR set does not cover
 * cross-app dependency declarations.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Verifies OpenRegister provides the classes Hermiq's agent engine requires.
 *
 * @spec exclude See file-level docblock — live e2e verification finding, no design change.
 */
class CheckOpenRegisterCompatibility implements IRepairStep {
	/**
	 * The OpenRegister `appinfo/info.xml` `<version>` at the commit that first
	 * contains BOTH required classes (OpenRegister #297 + #306). Documented here
	 * only — NC has no mechanism to enforce it (see file docblock). Bumped for
	 * agent-credentials: 0.2.17-unstable.14 is also the version that ships
	 * OpenRegister's organisation-scope credential broker
	 * (`credential-broker-organisation-scope`), which the "Agent credentials"
	 * Settings section and `CredentialScopeResolver`'s organisation branch
	 * depend on.
	 *
	 * @var string
	 */
	public const MIN_OPENREGISTER_VERSION = '0.2.17-unstable.14';

	/**
	 * FQCN => human-readable label (with the OpenRegister PR that introduced it),
	 * checked by run(). Exposed via getRequiredClasses() so tests can substitute a
	 * fake, guaranteed-absent class list without needing to actually remove
	 * OpenRegister's classes from the autoloader.
	 *
	 * @var array<string,string>
	 */
	private const REQUIRED_CLASSES = [
		ToolRegistryFacade::class => 'OCA\OpenRegister\Service\Mcp\ToolRegistryFacade (OpenRegister #297)',
		AgentRunRequestedEvent::class => 'OCA\OpenRegister\Event\AgentRunRequestedEvent (OpenRegister #306)',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR-3 logger for the persistent nextcloud.log trail.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Trivial IRepairStep display-name accessor; no behavioural contract.
	 */
	public function getName(): string {
		return "Verify OpenRegister provides Hermiq's agent-engine classes";
	}//end getName()

	/**
	 * The FQCN => label map to verify present. A protected seam so tests can
	 * override it with a deliberately-absent class without touching the
	 * autoloader or the real OpenRegister install.
	 *
	 * @return array<string,string>
	 */
	protected function getRequiredClasses(): array {
		return self::REQUIRED_CLASSES;
	}//end getRequiredClasses()

	/**
	 * Run the repair step: class_exists() every required class and, if any are
	 * missing, surface an actionable warning (console + log) naming the minimum
	 * OpenRegister version and the missing classes. Never throws — a missing
	 * OpenRegister dependency is a deployment misconfiguration to flag loudly,
	 * not a reason to abort the calling `occ upgrade`/`occ app:install` run.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude See file-level docblock — live e2e verification finding, no design change.
	 */
	public function run(IOutput $output): void {
		$missing = [];
		foreach ($this->getRequiredClasses() as $fqcn => $label) {
			if (class_exists($fqcn) === false) {
				$missing[] = $label;
			}
		}

		if ($missing === []) {
			$output->info("OpenRegister provides the classes Hermiq's agent engine requires.");
			return;
		}

		$message = sprintf(
			'Hermiq requires OpenRegister >= %s but the following required classes are missing: %s. '
			. 'The agent engine (chat, scheduled runs, flow-triggered agent actions) will fail to '
			. 'construct until OpenRegister is upgraded on this instance.',
			self::MIN_OPENREGISTER_VERSION,
			implode(', ', $missing)
		);

		$output->warning($message);
		$this->logger->error($message);
	}//end run()
}//end class
