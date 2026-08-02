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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\IFlowResolver;
use OCA\OpenRegister\Service\Flow\IScheduledFlowSource;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves hermiq agentflows for OpenRegister's flow engine.
 *
 * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
class HermiqFlowResolver implements IFlowResolver, IScheduledFlowSource
{

    /**
     * The register and schema hermiq stores flows under.
     */
    private const REGISTER = 'hermiq';

    private const SCHEMA = 'agentflow';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService  Loads and lists agentflow objects.
     * @param RegisterMapper  $registerMapper Resolves hermiq's register by slug.
     * @param SchemaMapper    $schemaMapper   Resolves the agentflow schema by id.
     * @param LoggerInterface $logger         The logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
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

        // The `register`/`schema` arguments above do NOT scope the lookup: an id
        // that is a uuid resolves cross-table, so `find()` happily returns an
        // object from somebody else's register. Without this check hermiq claims
        // EVERY flow on the instance — including OpenRegister's own store — and
        // because the registry takes the first non-null answer, the owning app's
        // document is never consulted and any field hermiq does not copy here
        // (executionMode, and anything added later) is silently dropped.
        if ($this->belongsToHermiq(object: $object) === false) {
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One linear match loop whose
     *   branches are the spec'd trigger predicate (event equality + register/schema
     *   match with empty-as-wildcard) plus defensive shape guards.
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

    /**
     * The agentflows that declare a schedule.
     *
     * OpenRegister's scheduler used to enumerate ONE hard-coded store — the
     * `flow_register`/`flow_schema` pair — and never asked the resolvers. Every
     * other trigger already went through them, so hermiq's event-triggered
     * agentflows fired normally while a hermiq agentflow with
     * `trigger: schedule` was simply invisible: no error, no run, nothing. The
     * instance had recorded ZERO runs with trigger `schedule` across 52,478
     * runs. `hydra-sequencer`, `hydra-dispatch` and `hydra-lock-reaper` were all
     * in that state — the sequencer being the pipeline's heartbeat.
     *
     * This is the answer to the enumeration the scheduler now performs. It
     * reports candidates only: `enabled` is reported honestly rather than
     * filtered away, and OpenRegister decides what may run. That keeps "a
     * disabled flow never runs" a property of the scheduler rather than
     * something each contributing app has to get right — which matters here,
     * because every hydra agentflow currently ships `enabled: false` on purpose.
     *
     * @return array<int, array{id: string, enabled: bool, trigger: string, cron: string, owner: string|null}>
     *         The candidate flows.
     *
     * @spec openspec/changes/hermiq-schedule-source/specs/or-flow-consumer/spec.md
     */
    public function scheduledFlows(): array
    {
        try {
            $flows = $this->objectService->findAll(
                config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA]],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[HermiqFlowResolver] Could not list agentflows for the schedule: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $candidates = [];
        foreach ($flows as $flow) {
            if (($flow instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $flow->getObject();
            if ((string) ($data['trigger'] ?? '') !== 'schedule') {
                continue;
            }

            // A scheduled run has no session, so the owner has to come off the
            // flow. The object's own owner is the authoritative one; the
            // `owner` field is the fallback a seeded flow carries.
            $owner = trim((string) ($flow->getOwner() ?? ''));
            if ($owner === '') {
                $owner = trim((string) ($data['owner'] ?? ''));
            }

            $ownerId = null;
            if ($owner !== '') {
                $ownerId = $owner;
            }

            $candidates[] = [
                'id'      => (string) $flow->getUuid(),
                'enabled' => (($data['enabled'] ?? false) === true),
                'trigger' => 'schedule',
                'cron'    => (string) ($data['cron'] ?? ''),
                'owner'   => $ownerId,
            ];
        }//end foreach

        return $candidates;

    }//end scheduledFlows()

    /**
     * Whether an object actually lives in hermiq's flow store.
     *
     * `ObjectService::find()` takes `register` and `schema` but does not scope by
     * them when the id is a uuid — it resolves cross-table and returns whatever
     * carries that uuid. So the arguments read like a filter and behave like a
     * hint, and a resolver that trusts them claims flows it does not own. Since
     * `FlowResolverRegistry` takes the FIRST non-null answer, over-claiming does
     * not merely return the wrong document, it prevents the owning app's
     * resolver from ever being asked.
     *
     * The comparison is slug-to-id: an object carries register/schema IDs, while
     * this class names them by slug. A store that cannot be resolved (hermiq not
     * installed, register removed) yields false — declining is always safe,
     * because another resolver may own the flow, whereas claiming wrongly is not.
     *
     * @param ObjectEntity $object The object to check.
     *
     * @return boolean Whether it belongs to hermiq's flow store.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    private function belongsToHermiq(ObjectEntity $object): bool
    {
        try {
            $register = $this->registerMapper->find(self::REGISTER);
        } catch (Throwable $e) {
            return false;
        }

        if ((string) $object->getRegister() !== (string) $register->getId()) {
            return false;
        }

        foreach ((array) $register->getSchemas() as $schemaId) {
            try {
                $schema = $this->schemaMapper->find($schemaId);
            } catch (Throwable $e) {
                continue;
            }

            if ($schema->getSlug() === self::SCHEMA) {
                return ((string) $object->getSchema() === (string) $schema->getId());
            }
        }

        return false;

    }//end belongsToHermiq()
}//end class
