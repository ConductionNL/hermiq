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
		// TaskProcessing PROVIDERS. Hermiq CONSUMES Nextcloud AI for its own LLM work
		// (core:text2text, core:text2text:chat, core:text2text:chatwithtools; see
		// NextcloudTaskClient), so it must not PROVIDE any of those types: an admin who
		// preferred hermiq for one would route hermiq's own request back to itself
		// (use-nextcloud-ai-settings). The text2text, summary and headline providers
		// are therefore gone. The contextagent provider stays: it is the governed
		// alternative to NC's stock `context_agent` ExApp, and hermiq never consumes
		// core:contextagent:interaction. The audio providers stay too: hermiq consumes
		// no audio task type. Reformulate and translate (REQ-T2T-003) stay: hermiq
		// consumes neither task type.
		$context->registerTaskProcessingProvider(AudioToTextProvider::class);
		$context->registerTaskProcessingProvider(TextToSpeechProvider::class);
		$context->registerTaskProcessingProvider(Text2TextReformulationProvider::class);
		$context->registerTaskProcessingProvider(Text2TextTranslateProvider::class);
		$context->registerTaskProcessingProvider(ContextAgentProvider::class);
	}//end register()
}//end class
