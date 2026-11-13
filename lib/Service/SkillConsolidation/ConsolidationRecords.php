<?php

/**
 * Hermiq ConsolidationRecords
 *
 * The agents, audit trail, delivery and model provider a consolidation uses.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\SkillConsolidation
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
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\SkillConsolidation;

use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Mcp\Db\AgentMapper;

/**
 * The agents, audit trail, delivery and model provider a consolidation uses.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ConsolidationRecords {

	/**
	 * Constructor.
	 *
	 * @param ProviderFactory $providerFactory The governed LLM chokepoint (ONE pass per proposal).
	 * @param AgentMapper $agentMapper Resolves the eval agent for the paired comparison.
	 * @param AuditTrailMapper $auditTrailMapper OR audit write-path (one entry per transition).
	 * @param DeliveryService $deliveryService Behind-badge + rollback-suggestion notifications.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ProviderFactory $providerFactory,
		public readonly AgentMapper $agentMapper,
		public readonly AuditTrailMapper $auditTrailMapper,
		public readonly DeliveryService $deliveryService,
	) {
	}//end __construct()
}//end class
