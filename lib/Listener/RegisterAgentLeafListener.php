<?php

/**
 * Hermiq RegisterAgentLeafListener.
 *
 * Registers Hermiq's `hermiq-agent` leaf on OpenRegister through the sibling-app
 * leaf-registration hook (`RegisterLeafProvidersEvent`, openregister
 * app-leaf-provider-registration / ADR-066). This is the server-side declaration
 * that makes an Agent surface discoverable on any OpenRegister object in any
 * OpenBuild app — the render components live in Hermiq's OWN JS bundle under the
 * SAME id (`hermiq-agent`), registered via `registerIntegration()` from the
 * always-loaded `hermiq-agent-leaf` init script (ADR-019 render/link parity).
 *
 * RENDER-AND-READ ONLY (ADR-066). The descriptor carries NO Vue components, no
 * verb, and no run authority. It declares two kinds:
 *   - `render-surface` — Hermiq mounts a tab (agent chat via the tool-free
 *     `converse` endpoint) + widget (per-object run history + a "run agent"
 *     affordance) under the shared id.
 *   - `agent-runner`   — the forward-declared face OpenRegister reserved for
 *     hermiq (LeafDescriptor::KIND_AGENT_RUNNER). Starting a run is still a
 *     cross-app COMMAND: the widget POSTs to `/api/agents/{id}/run-on-object`,
 *     which dispatches the governed `AgentRunRequestedEvent` recipe (ADR-041).
 *     The leaf itself never runs anything.
 *
 * Gated on Hermiq being installed/enabled via `requiredApp: 'hermiq'`, so on an
 * instance without Hermiq the surface is HIDDEN rather than a broken tab.
 *
 * Guarded registration: `Application::register()` only wires this listener when
 * OpenRegister's `RegisterLeafProvidersEvent` class exists, so an instance whose
 * OpenRegister predates the leaf hook still boots.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contributes the `hermiq-agent` render leaf to OpenRegister's leaf catalogue.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
 */
class RegisterAgentLeafListener implements IEventListener
{

    /**
     * The shared leaf id, equal to the JS `registerIntegration()` id.
     *
     * @var string
     */
    public const LEAF_ID = 'hermiq-agent';

    /**
     * Constructor.
     *
     * @param IL10N           $l10n   Localisation for the human-readable label.
     * @param LoggerInterface $logger PSR-3 logger (a throwing listener costs only its own leaf).
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Contribute the `hermiq-agent` leaf descriptor.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-2-1
     */
    public function handle(Event $event): void
    {
        if ($event instanceof RegisterLeafProvidersEvent === false) {
            return;
        }

        try {
            $descriptor = new LeafDescriptor(
                id: self::LEAF_ID,
                label: $this->l10n->t('Agent'),
                icon: 'RobotOutline',
                kinds: [
                    LeafDescriptor::KIND_RENDER_SURFACE,
                    LeafDescriptor::KIND_AGENT_RUNNER,
                ],
                requiredApp: Application::APP_ID,
                group: 'workflow',
                surfaces: [
                    'detail-page',
                    'single-entity',
                ],
                referenceType: self::LEAF_ID,
                // Vue 3 leaf under a Vue 2.7 host: the JS registration renders via a
                // `mount`/`unmount` DOM hand-off (openregister#2127, ADR-066), so the
                // server descriptor MUST declare the SAME render mode under the shared
                // id for cross-layer parity (gate-24 integration-parity).
                renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
            );

            // Render-only leaf: no IntegrationProvider (null). The chat reads via
            // `converse` and run history reads OR's audit trail; the single write is a
            // POST to the governed run-on-object endpoint, never through this leaf.
            $event->registerLeaf($descriptor, null);
        } catch (Throwable $e) {
            // Never take the leaf catalogue down: log and skip our own leaf only.
            $this->logger->warning(
                'Hermiq could not register the hermiq-agent leaf: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end handle()
}//end class
