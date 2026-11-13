<?php

/**
 * Hermiq ProviderPlatform
 *
 * The Nextcloud services a model provider call reaches.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
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

namespace OCA\Hermiq\Service\Llm;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use Psr\Container\ContainerInterface;

/**
 * The Nextcloud services a model provider call reaches.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ProviderPlatform {

	/**
	 * Constructor.
	 *
	 * @param IManager $taskManager Nextcloud Assistant task manager
	 *                              (the `nextcloud` driver's
	 *                              backend).
	 * @param IUserSession $userSession Current session —
	 *                                  the broker's
	 *                                  ownership guard needs
	 *                                  an identity to check
	 *                                  the credential
	 *                                  against.
	 * @param IAppManager|null $appManager Nextcloud app manager — used ONLY by the
	 *                                     `cli` execution-mode path
	 *                                     (llm-cli-runner-exapp) to detect whether
	 *                                     AppAPI and the `hermiq-llm-runner` ExApp
	 *                                     are installed/enabled before a turn is
	 *                                     dispatched. Nullable/defaulted so every
	 *                                     existing call site (all constructed before
	 *                                     this change) keeps working unchanged; NC's
	 *                                     DI container autowires a real instance in
	 *                                     production. A null manager makes the runner
	 *                                     report unavailable (503), never crash.
	 * @param IURLGenerator|null $urlGenerator Resolves the absolute URL of Hermiq's
	 *                                         governed MCP endpoint written into the
	 *                                         runner's MCP config
	 *                                         (cli-runner-governed-mcp-and-egress).
	 *                                         Nullable/defaulted for the same
	 *                                         backward-compat reason; a null
	 *                                         generator makes a tool-requiring `cli`
	 *                                         turn fail loud (503).
	 * @param IAppConfig|null $appConfig Reads the `mcp_run_base_url` override —
	 *                                   the CONTAINER-reachable origin of the
	 *                                   governed MCP endpoint. Needed because
	 *                                   `IURLGenerator` yields the URL published
	 *                                   to browsers, which the runner container
	 *                                   usually cannot resolve
	 *                                   (cli-runner-governed-mcp-and-egress).
	 *                                   Nullable/defaulted for the same
	 *                                   backward-compat reason; a null config
	 *                                   simply means no override is applied.
	 * @param ContainerInterface|null $container The app container AppAPI and the
	 *                                           OpenRegister credential broker are
	 *                                           resolved from, both being optional
	 *                                           sibling apps named by class-string.
	 *                                           Nullable and trailing for the same
	 *                                           backward-compat reason: the unit tests
	 *                                           build this factory positionally with
	 *                                           its first four collaborators.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly IManager $taskManager,
		public readonly IUserSession $userSession,
		public readonly ?IAppManager $appManager = null,
		public readonly ?IURLGenerator $urlGenerator = null,
		public readonly ?IAppConfig $appConfig = null,
		public readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()
}//end class
