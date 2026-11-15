<?php

/**
 * Hermiq ProviderRegistrar
 *
 * Register the TaskProcessing providers Hermiq offers to the Nextcloud AI API.
 *
 * @category TaskProcessing
 * @package  OCA\Hermiq\TaskProcessing
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

namespace OCA\Hermiq\TaskProcessing;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The TaskProcessing component registers its own providers, so the bootstrap does not name them.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */
final class ProviderRegistrar {

	/**
	 * Register the TaskProcessing providers Hermiq offers to the Nextcloud AI API.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		// TaskProcessing PROVIDERS (SPECTR-NEXTCLOUD-PLAN.md §8 moves 2 + 3). Hermiq
		// backs Nextcloud's own AI API with its configured LLM, so the whole instance
		// (Assistant, Mail, decidesk — which 503s without any provider) gets AI from
		// one Hermiq config. The text2text family shares the identical input→output
		// shape; the contextagent provider is the governed alternative to NC's stock
		// `context_agent` ExApp (admin picks the preferred provider per task type).
		$context->registerTaskProcessingProvider(AudioToTextProvider::class);
		$context->registerTaskProcessingProvider(TextToSpeechProvider::class);
		$context->registerTaskProcessingProvider(Text2TextProvider::class);
		$context->registerTaskProcessingProvider(Text2TextSummaryProvider::class);
		$context->registerTaskProcessingProvider(Text2TextHeadlineProvider::class);
		// REQ-T2T-003: reformulate and translate, the other two text actions the Assistant offers.
		$context->registerTaskProcessingProvider(Text2TextReformulationProvider::class);
		$context->registerTaskProcessingProvider(Text2TextTranslateProvider::class);
		$context->registerTaskProcessingProvider(ContextAgentProvider::class);
	}//end register()
}//end class
