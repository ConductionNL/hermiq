<?php

/**
 * Hermiq PinnedCredentialRefusedException.
 *
 * Thrown when an agent carries a credential pinned for the provider of a turn and
 * that credential cannot be used for this run: it is gone, no longer allowed for
 * hermiq, for another provider, someone else's personal key, or another
 * organisation's. The turn stops; no other credential is tried, because falling
 * back would run the agent under a wider identity than its owner chose
 * (operations-a-credential-per-agent, Ruben's decision of 29 Sep 2026).
 *
 * Extends ProviderUnavailableException so every caller that already stops a turn
 * on an unusable provider stops it here too. The message is the sentence the
 * person reads.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Credential;

use OCA\Hermiq\Service\Llm\ProviderUnavailableException;

/**
 * The credential pinned to the agent cannot be used, so the turn stops.
 *
 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
 */
class PinnedCredentialRefusedException extends ProviderUnavailableException
{

    /**
     * The sentence the person reads (kept in l10n/*.json as the same key).
     *
     * @var string
     */
    public const MESSAGE = 'The credential pinned to this agent cannot be used for this run.';

    /**
     * Stable code the chat keys its message on.
     *
     * @var string
     */
    public const ERROR_CODE = 'pinned_credential_refused';

    /**
     * Constructor.
     *
     * @param string $provider The provider of the turn (logged, never shown).
     *
     * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
     */
    public function __construct(
        private readonly string $provider,
    ) {
        parent::__construct(message: self::MESSAGE, code: 403);

    }//end __construct()

    /**
     * The provider the refused pin was for.
     *
     * @return string The provider identifier.
     *
     * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
     */
    public function getProvider(): string
    {
        return $this->provider;

    }//end getProvider()
}//end class
