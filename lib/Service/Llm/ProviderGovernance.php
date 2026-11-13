<?php

/**
 * Hermiq ProviderGovernance
 *
 * What governs a model provider call: model policy, credentials, run tokens, features and data use.
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

use OCA\Hermiq\Service\AiFeature\DataUseGate;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\OrganisationModelPolicyService;

/**
 * What governs a model provider call: model policy, credentials, run tokens, features and data use.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ProviderGovernance {

	/**
	 * Constructor.
	 *
	 * @param OrganisationModelPolicyService|null $modelPolicyService Resolves the calling
	 *                                                                organisation's effective ModelPolicy
	 *                                                                (tenant-model-policy). Nullable —
	 *                                                                defaulted so every existing call site
	 *                                                                (all constructed before this change)
	 *                                                                keeps working unchanged; NC's DI
	 *                                                                container autowires a real instance in
	 *                                                                production regardless of the default.
	 *                                                                When `$organisation` is not passed to
	 *                                                                `createChatDriver()`, no enforcement
	 *                                                                call is made at all (opt-in threading,
	 *                                                                see design.md).
	 * @param CredentialScopeResolver|null $credentialResolver Resolves a personal →
	 *                                                         organisation override for the
	 *                                                         broker credential id the
	 *                                                         `openai`/`fireworks` branches use
	 *                                                         (agent-credentials). Nullable —
	 *                                                         defaulted so every existing call
	 *                                                         site (all constructed before this
	 *                                                         change) keeps working unchanged;
	 *                                                         NC's DI container autowires a real
	 *                                                         instance in production regardless
	 *                                                         of the default. Consulted ONLY
	 *                                                         when `$organisation` is passed to
	 *                                                         `createChatDriver()` — the exact
	 *                                                         same opt-in guard
	 *                                                         `enforceModelPolicy()` uses.
	 * @param RunTokenService|null $runTokenService Mints/consumes the per-run bearer
	 *                                              token that authenticates the governed
	 *                                              `cli` MCP + egress endpoints
	 *                                              (cli-runner-governed-mcp-and-egress).
	 *                                              Nullable/defaulted so existing test
	 *                                              call sites keep working; DI autowires
	 *                                              a real instance in production. A null
	 *                                              service makes a tool-requiring `cli`
	 *                                              turn fail loud (503) — never a
	 *                                              text-only downgrade.
	 * @param FeatureProviderResolver|null $featureResolver Joins the AiFeature register to
	 *                                                      the ModelPolicy: resolves a
	 *                                                      feature's own provider binding
	 *                                                      and refuses a run whose provider
	 *                                                      runs outside the residency the
	 *                                                      feature requires. Nullable and
	 *                                                      trailing for the same
	 *                                                      backward-compat reason; a null
	 *                                                      resolver leaves every existing
	 *                                                      call site unchanged.
	 * @param DataUseGate|null $dataUseGate The data-use step on the path without an AI
	 *                                      feature (models-no-training-guarantee).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?OrganisationModelPolicyService $modelPolicyService = null,
		public readonly ?CredentialScopeResolver $credentialResolver = null,
		public readonly ?RunTokenService $runTokenService = null,
		public readonly ?FeatureProviderResolver $featureResolver = null,
		public readonly ?DataUseGate $dataUseGate = null,
	) {
	}//end __construct()
}//end class
