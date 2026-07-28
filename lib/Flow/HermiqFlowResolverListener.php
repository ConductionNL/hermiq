<?php

/**
 * Contributes hermiq's flow resolver to OpenRegister's flow engine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
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
 * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\OpenRegister\Service\Flow\RegisterFlowResolversEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the agentflow resolver when OpenRegister collects resolvers.
 *
 * @template-implements IEventListener<RegisterFlowResolversEvent>
 */
class HermiqFlowResolverListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param HermiqFlowResolver $resolver The agentflow resolver.
     */
    public function __construct(private readonly HermiqFlowResolver $resolver)
    {

    }//end __construct()

    /**
     * Contribute the resolver.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof RegisterFlowResolversEvent) === false) {
            return;
        }

        $event->registerResolver(resolver: $this->resolver);

    }//end handle()
}//end class
