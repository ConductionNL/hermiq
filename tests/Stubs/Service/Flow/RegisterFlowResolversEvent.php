<?php
/**
 * Minimal OpenRegister RegisterFlowResolversEvent stub for standalone unit runs
 * and static analysis.
 *
 * Signatures mirrored verbatim from openregister
 * lib/Service/Flow/RegisterFlowResolversEvent.php. Registered at TEST TIME only
 * by tests/bootstrap.php and scanned (never executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Minimal RegisterFlowResolversEvent stub.
 */
class RegisterFlowResolversEvent extends Event
{

    /**
     * Contributed resolvers.
     *
     * @var array<int, IFlowResolver>
     */
    private array $resolvers = [];

    /**
     * Contribute a resolver.
     *
     * @param IFlowResolver $resolver The resolver.
     *
     * @return void
     */
    public function registerResolver(IFlowResolver $resolver): void
    {
        $this->resolvers[] = $resolver;

    }//end registerResolver()

    /**
     * Every contributed resolver.
     *
     * @return array<int, IFlowResolver> The resolvers.
     */
    public function getResolvers(): array
    {
        return $this->resolvers;

    }//end getResolvers()
}//end class
