<?php

/**
 * Hermiq GraphRunRequestedListener
 *
 * The "agents on Nextcloud events" ingress for authored agent graphs. Listens to
 * OpenRegister object-lifecycle events; for each, discovers the `agentflow` graphs
 * whose `triggerSchema` matches the object's schema and whose `trigger` matches the
 * event, and runs each via {@see \OCA\Hermiq\Service\Graph\GraphExecutor} with the
 * event's object as the initial state. Mirrors how
 * {@see \OCA\Hermiq\Listener\AgentRunRequestedListener} turns an event into a
 * governed agent run — generalised from one agent to a graph.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://hermiq.app
 *
 * @spec openspec/changes/agent-graph-builder/specs/agent-graph/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Graph\GraphExecutor;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes OpenRegister object events to matching agent graphs.
 *
 * @template-implements IEventListener<Event>
 */
class GraphRunRequestedListener implements IEventListener
{
    /**
     * Register slug holding Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for authored agent graphs.
     *
     * @var string
     */
    private const FLOW_SCHEMA = 'agentflow';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService Discovers matching agentflow graphs.
     * @param GraphExecutor   $graphExecutor Walks a matching graph.
     * @param LoggerInterface $logger        PSR-3 logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly GraphExecutor $graphExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Map an object event to its trigger id + object, then run matching graphs.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        [$object, $trigger] = $this->resolve(event: $event);
        if ($object === null) {
            return;
        }

        $schema = (string) $object->getSchema();
        if ($schema === '') {
            return;
        }

        foreach ($this->matchingGraphs(schema: $schema, trigger: $trigger) as $graph) {
            try {
                $this->graphExecutor->run(graph: $graph, object: $object);
            } catch (Throwable $e) {
                $this->logger->warning('Hermiq graph run failed for schema '.$schema.': '.$e->getMessage(), ['exception' => $e]);
            }
        }
    }//end handle()

    /**
     * Resolve the event to [object, canonical trigger id].
     *
     * @param Event $event The event.
     *
     * @return array{0: ObjectEntity|null, 1: string}
     */
    private function resolve(Event $event): array
    {
        if ($event instanceof ObjectCreatedEvent) {
            return [$event->getObject(), 'object.created'];
        }

        if ($event instanceof ObjectUpdatedEvent) {
            return [$event->getNewObject(), 'object.updated'];
        }

        if ($event instanceof ObjectDeletedEvent) {
            return [$event->getObject(), 'object.deleted'];
        }

        return [null, ''];
    }//end resolve()

    /**
     * Discover enabled agentflow graphs whose `triggerSchema` matches the object's
     * schema and whose `trigger` matches the event (accepting both the canonical
     * id `object.updated` and the legacy alias `updated`).
     *
     * @param string $schema  The triggering object's schema id.
     * @param string $trigger The canonical event trigger id.
     *
     * @return array<int, array> The matching graph definitions.
     */
    private function matchingGraphs(string $schema, string $trigger): array
    {
        $legacy   = str_replace('object.', '', $trigger);
        $accepted = [$trigger, $legacy];

        try {
            $graphs = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::FLOW_SCHEMA)
                ->findAll(
                    config: ['filters' => ['triggerSchema' => $schema], 'limit' => 200],
                    _rbac: false,
                    _multitenancy: false
                );
        } catch (Throwable $e) {
            // No agentflow schema / register yet, or a query error — nothing to run.
            return [];
        }

        $out = [];
        foreach ($graphs as $graph) {
            $data = ($graph instanceof ObjectEntity) ? $graph->getObject() : (is_array($graph) ? $graph : null);
            if (is_array($data) === false) {
                continue;
            }

            if (($data['enabled'] ?? true) === false) {
                continue;
            }

            if (in_array((string) ($data['trigger'] ?? ''), $accepted, true) === true) {
                $out[] = $data;
            }
        }

        return $out;
    }//end matchingGraphs()
}//end class
