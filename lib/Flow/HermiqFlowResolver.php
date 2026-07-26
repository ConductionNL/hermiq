<?php

/**
 * Lets OpenRegister's flow engine load and trigger hermiq's agentflows.
 *
 * Hermiq stores flows as `agentflow` objects in the `hermiq` register. The
 * engine does not know that — it asks a resolver. This is that resolver: it
 * turns an agentflow id into a flow document the engine can walk, loads the
 * subject object a run is about, and lists which agentflows are wired to a
 * fired event so the trigger side can queue them.
 *
 * With this in place, OpenRegister's worker runs hermiq's flows, and hermiq's
 * own GraphExecutor is redundant.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\IFlowResolver;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves hermiq agentflows for OpenRegister's flow engine.
 */
class HermiqFlowResolver implements IFlowResolver
{

    /**
     * The register and schema hermiq stores flows under.
     */
    private const REGISTER = 'hermiq';

    private const SCHEMA = 'agentflow';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService Loads and lists agentflow objects.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Load an agentflow as a flow document.
     *
     * @param string $flowId The agentflow uuid.
     *
     * @return array|null The flow document, or null when it is not a hermiq flow.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function resolveFlow(string $flowId): ?array
    {
        try {
            $object = $this->objectService->find(
                id: $flowId,
                register: self::REGISTER,
                schema: self::SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            // Not a hermiq flow (or not found) — another resolver may own it.
            return null;
        }

        if (($object instanceof ObjectEntity) === false) {
            return null;
        }

        $data = $object->getObject();

        return [
            'id'     => $flowId,
            'nodes'  => (array) ($data['nodes'] ?? []),
            'edges'  => (array) ($data['edges'] ?? []),
            'limits' => (array) ($data['limits'] ?? []),
        ];

    }//end resolveFlow()

    /**
     * Load the object a run is about.
     *
     * @param string $uuid     The subject uuid.
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     *
     * @return object|null The subject object, or null when it cannot be found.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function resolveSubject(string $uuid, string $register, string $schema): ?object
    {
        if ($uuid === '' || $register === '' || $schema === '') {
            return null;
        }

        try {
            $object = $this->objectService->find(
                id: $uuid,
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            return null;
        }

        if (($object instanceof ObjectEntity) === true) {
            return $object;
        }

        return null;

    }//end resolveSubject()

    /**
     * Which agentflows are wired to a fired event.
     *
     * An agentflow declares its trigger with `trigger`, `triggerRegister` and
     * `triggerSchema` fields. A flow matches when its trigger equals the event
     * and its register/schema match (an empty flow register/schema is a
     * wildcard — "any").
     *
     * @param string $event    The event id.
     * @param string $register The register the event fired on.
     * @param string $schema   The schema the event fired on.
     *
     * @return array<int, string> The ids of the matching agentflows.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function flowsForTrigger(string $event, string $register, string $schema): array
    {
        try {
            $flows = $this->objectService->findAll(
                config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA]],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[HermiqFlowResolver] Could not list agentflows for a trigger: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $event]
            );
            return [];
        }

        $ids = [];
        foreach ($flows as $flow) {
            if (($flow instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $flow->getObject();
            if (($data['enabled'] ?? false) !== true) {
                continue;
            }

            if ((string) ($data['trigger'] ?? '') !== $event) {
                continue;
            }

            $flowRegister = (string) ($data['triggerRegister'] ?? '');
            $flowSchema   = (string) ($data['triggerSchema'] ?? '');
            if ($flowRegister !== '' && $flowRegister !== $register) {
                continue;
            }

            if ($flowSchema !== '' && $flowSchema !== $schema) {
                continue;
            }

            $ids[] = (string) $flow->getUuid();
        }//end foreach

        return $ids;

    }//end flowsForTrigger()
}//end class
