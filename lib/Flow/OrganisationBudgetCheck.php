<?php

/**
 * Hermiq's spend budget as a flow oversight check.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Vetoes hermiq hops for organisations whose budget has reached its hard cap.
 *
 * The engine knows nothing about budgets: hermiq contributes this check the
 * way it contributes its node types, so a run that reaches an agent hop after
 * its organisation's budget is spent stops at that hop with this check's
 * reason, the same as a run stopped by the kill switch.
 *
 * Fail modes follow the budget gate the dispatch paths already use
 * (`BudgetService::isBlocked()`): a budget read failure consents (logged
 * there), so a transient read error never halts every tenant. A run whose
 * organisation cannot be established consents too: no budget can be matched
 * to it, and the kill switch is the control that refuses unattributed hops.
 * A check that throws is a veto, decided by the registry.
 *
 * Scoped to `hermiq.*` node types only: other apps' hops are not hermiq's to
 * veto.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class OrganisationBudgetCheck implements IFlowOversightCheck {

	/**
	 * Constructor.
	 *
	 * @param BudgetService      $budgetService The hard-cap gate.
	 * @param ContainerInterface $container     Lazy FlowRunMapper resolution for the
	 *                                          run's organisation; lazy so the check
	 *                                          stays constructible on an OpenRegister
	 *                                          without the flow store.
	 */
	public function __construct(
		private readonly BudgetService $budgetService,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The check's id, as recorded on a refusal.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function getId(): string {
		return 'hermiq.budget';
	}//end getId()

	/**
	 * Veto a hermiq hop whose organisation's budget has reached its hard cap.
	 *
	 * @param array<string,mixed> $context The hop context (`nodeType`, `runUuid`).
	 *
	 * @return string|null The refusal reason, or null to consent.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function veto(array $context): ?string {
		$nodeType = (string)($context['nodeType'] ?? '');
		if (str_starts_with($nodeType, 'hermiq.') === false) {
			return null;
		}

		$organisation = $this->runOrganisation(runUuid: (string)($context['runUuid'] ?? ''));
		if ($organisation === null) {
			return null;
		}

		if ($this->budgetService->isBlocked(organisation: $organisation) === true) {
			return 'The hermiq budget for this run\'s organisation has reached its hard cap. '
				. 'Raise the cap or wait for the next budget period to let agent work continue.';
		}

		return null;
	}//end veto()

	/**
	 * The organisation the identified run belongs to, or null when unknown.
	 *
	 * Read from the FlowRun row rather than the context: the run's stored
	 * attribution is server-written, where a context value would be
	 * caller-supplied at queue time.
	 *
	 * @param string $runUuid The run uuid from the hop context.
	 *
	 * @return string|null The organisation, or null when it cannot be established.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	private function runOrganisation(string $runUuid): ?string {
		if ($runUuid === '') {
			return null;
		}

		try {
			$mapper = $this->container->get(FlowRunMapper::class);
			$run = $mapper->findByUuid($runUuid);
		} catch (Throwable $e) {
			return null;
		}

		$organisation = trim((string)($run->getOrganisation() ?? ''));
		if ($organisation === '') {
			return null;
		}

		return $organisation;
	}//end runOrganisation()
}//end class
