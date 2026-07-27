<?php

/**
 * Hermiq Seed Hydra Triage Flow Repair Step.
 *
 * Seeds the automated triage loop as DATA: one `agentflow` object in the `hermiq`
 * register, resolved by the existing `HermiqFlowResolver` and walked by
 * OpenRegister's flow engine (ADR-065). It is deliberately NOT a Hermiq service, a
 * controller path or a background job — the whole point of the flows-first pivot is
 * that porting an orchestrator's loop creates no code, only flows, so an operator
 * can retune the loop without a Hermiq release.
 *
 * Shape of the seeded graph:
 *
 *   [triage]  hermiq.agent-step   run the Hydra Triage agent over the finding
 *      │                          (expectJson, so a later edge can read one field)
 *      ▼
 *   [gate]    openregister.route  per-item branch on the triage result
 *      ├── a proposed label ─────▶ [command]  the command step
 *      └── otherwise ────────────▶ [no-result] stop, nothing proposed
 *
 * The `gate` is not decoration. `HermiqAgentNode::execute()` catches every
 * `Throwable` and sets the answer to an EMPTY STRING, so at the node boundary a
 * failed LLM turn is indistinguishable from a silent one. Without an explicit
 * branch on "no result", a failed turn would fall straight through to a step that
 * commands a build pipeline. The branch is what makes that impossible.
 *
 * The `command` node is `openregister.stop` TODAY and that is not a placeholder
 * standing in for missing work — it is the specified behaviour while the
 * OpenConnector-backed command node does not exist. OpenConnector registers no MCP
 * tool provider and contributes no flow node or resolver, so there is presently
 * nothing for a terminal command step to call. The flow therefore terminates with
 * the proposed label already recorded on the run's items (the agent step put it
 * there) and writes nothing, rather than degrading into a Hermiq-authored HTTP
 * call — which is exactly what this change forbids (`nc-native-tools` delta: a
 * remote WRITE cannot use the read exception). When the OpenConnector half ships,
 * an operator swaps that one node's `type` on the object; no release is needed.
 *
 * Idempotent by the seeded `name`, written through OpenRegister's `ObjectService`
 * in system context, following the `SeedAgentTemplates` / `SeedSkillCreator`
 * precedent exactly (lazy container resolution because OpenRegister may not be
 * installed yet; a re-run neither duplicates the flow nor overwrites an operator's
 * edits).
 *
 * The seeded flow is created DISABLED. It carries no owner — a trigger fires with
 * no acting user, so the owner must be the NC UID of the person who authored and
 * activated it, and a repair step running as no one cannot honestly claim to be
 * that person. An ownerless flow must not dispatch, so shipping it enabled and
 * unattributed would either fail loudly on every finding or, worse, run
 * unattributed. Enabling it is the deliberate human act that supplies the owner.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the "Hydra Triage" agentflow via ObjectService (idempotent, by name).
 *
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
 */
class SeedHydraTriageFlow implements IRepairStep
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    public const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for agentflow objects.
     *
     * @var string
     */
    public const FLOW_SCHEMA = 'agentflow';

    /**
     * The seeded flow's name — also the idempotency key.
     *
     * @var string
     */
    public const FLOW_NAME = 'Hydra Triage';

    /**
     * The register whose objects trigger this flow. Owned by the hydra repo
     * (`hydra-register-data-plane`); named here only as the trigger the flow
     * listens for. A flow naming a register that does not exist simply never fires.
     *
     * @var string
     */
    public const TRIGGER_REGISTER = 'hydra';

    /**
     * The schema whose creation starts a triage run.
     *
     * @var string
     */
    public const TRIGGER_SCHEMA = 'finding';

    /**
     * The trigger event, from OpenRegister's event catalog.
     *
     * @var string
     */
    public const TRIGGER_EVENT = 'object.created';

    /**
     * The item key the agent step writes its triage result under, and the key the
     * branch reads.
     *
     * @var string
     */
    public const TRIAGE_OUTPUT_KEY = 'triage';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container Server container for lazy ObjectService resolution
     *                                      (OpenRegister may not be installed yet).
     * @param LoggerInterface    $logger    PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     *
     * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
     */
    public function getName(): string
    {
        return 'Seed the Hydra Triage agentflow (hydra-console-agent-leaves)';

    }//end getName()

    /**
     * Seed the triage agentflow if one with the seeded name does not already exist;
     * an existing flow — including one an operator has since edited, re-owned or
     * enabled — is left completely untouched.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-a-new-finding-triggers-the-seeded-triage-flow
     */
    public function run(IOutput $output): void
    {
        try {
            $objectService = $this->container->get(ObjectService::class);
        } catch (Throwable $e) {
            $output->warning('OpenRegister not available — skipping Hydra Triage flow seed.');
            $this->logger->warning('[hermiq] Hydra Triage flow seed skipped: '.$e->getMessage());
            return;
        }

        try {
            if ($this->flowExists(objectService: $objectService) === true) {
                $output->info('Hydra Triage flow already present — skipped.');
                return;
            }

            $objectService->saveObject(
                object: $this->flowObject(),
                register: self::REGISTER_SLUG,
                schema: self::FLOW_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
            $output->info('Hydra Triage flow seeded (disabled — enable it to supply its owner).');
        } catch (Throwable $e) {
            $output->warning('Could not seed the Hydra Triage flow: '.$e->getMessage());
            $this->logger->error('[hermiq] Hydra Triage flow seed failed: '.$e->getMessage());
        }//end try

    }//end run()

    /**
     * The seeded agentflow object.
     *
     * Public so a test can assert the shape without a live OpenRegister, and so the
     * node-type inventory ("every node is a built-in engine node, `hermiq.agent-step`,
     * or the OpenConnector-backed command node — and none opens an HTTP client from
     * Hermiq code") is verifiable as data rather than by reading prose.
     *
     * @return array<string, mixed> The object to save.
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-flow-contains-no-hermiq-authored-http-step
     */
    public function flowObject(): array
    {
        return [
            'name'            => self::FLOW_NAME,
            'description'     => 'Triage loop for the hydra pipeline: when a finding appears, the Hydra Triage '
                .'agent reads its bounded context and proposes the single state-machine label that would move '
                .'the work forward. The proposal is recorded; writing the label is a separate, '
                .'approval-gated command.',
            'trigger'         => self::TRIGGER_EVENT,
            'triggerRegister' => self::TRIGGER_REGISTER,
            'triggerSchema'   => self::TRIGGER_SCHEMA,
            // Disabled on purpose: enabling it is the human act that supplies the
            // `owner` a trigger-fired run is attributed to. See the class docblock.
            'enabled'         => false,
            'owner'           => '',
            'nodes'           => $this->nodes(),
            'edges'           => $this->edges(),
            'limits'          => ['maxNodes' => 20, 'maxIterations' => 20],
            'notes'           => 'Seeded by hermiq (hydra-console-agent-leaves). Before enabling: set `owner` to '
                .'your own Nextcloud UID — a trigger fires with no acting user, and an ownerless run must not '
                .'dispatch. When the OpenConnector-backed command node exists, change the `command` node\'s '
                .'`type` to it; until then the branch stops with the proposed label recorded and writes nothing.',
        ];

    }//end flowObject()

    /**
     * The flow's nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function nodes(): array
    {
        return [
            [
                'id'       => 'triage',
                'type'     => 'hermiq.agent-step',
                'position' => ['x' => 0, 'y' => 0],
                'config'   => [
                    // Resolved by NAME at enable time by the operator, or by the
                    // agent seed's own uuid once both objects exist. Left as the
                    // seeded agent's name so the two seeds stay independent: a
                    // repair step that hard-depends on another seed's uuid breaks
                    // whenever either is re-seeded.
                    'agentId'    => SeedHydraTriageAgent::AGENT_NAME,
                    'output'     => self::TRIAGE_OUTPUT_KEY,
                    'expectJson' => true,
                    'prompt'     => 'Triage this pipeline finding and reply as JSON with two keys: '
                        .'"summary" (one sentence on what is blocking the work) and "label" (the single '
                        .'state-machine label that would move it forward, or an empty string if you are not '
                        .'confident). Finding: {{json.title}} — {{json.description}}',
                ],
            ],
            [
                'id'       => 'gate',
                'type'     => 'openregister.route',
                'position' => ['x' => 260, 'y' => 0],
                'config'   => [
                    // The router tags each item with the OUTPUT it selected, and the
                    // engine delivers an item only to the place matching its tag —
                    // so an output name IS a target node id.
                    'rules'   => [
                        [
                            'output'    => 'command',
                            // Truthy only when the agent actually proposed a label.
                            // A failed turn yields '' at the node boundary, so
                            // `json.triage.label` is absent and this is false —
                            // the one condition standing between a failed LLM call
                            // and a pipeline command.
                            'condition' => ['!!' => ['var' => 'json.'.self::TRIAGE_OUTPUT_KEY.'.label']],
                        ],
                    ],
                    'default' => 'no-result',
                ],
            ],
            [
                'id'       => 'command',
                // Built-in stop node while the OpenConnector-backed command node
                // does not exist — see the class docblock. Swapping this `type` is
                // the whole change needed when that upstream half ships.
                'type'     => 'openregister.stop',
                'position' => ['x' => 520, 'y' => -80],
                'config'   => [
                    'error'   => false,
                    'message' => 'A label was proposed and recorded. No forge write was attempted: the '
                        .'OpenConnector-backed command node is not installed on this instance.',
                ],
            ],
            [
                'id'       => 'no-result',
                'type'     => 'openregister.stop',
                'position' => ['x' => 520, 'y' => 80],
                'config'   => [
                    'error'   => false,
                    'message' => 'The agent step produced no triage result — the flow stops before the '
                        .'command step.',
                ],
            ],
        ];

    }//end nodes()

    /**
     * The flow's edges.
     *
     * @return array<int, array<string, mixed>>
     */
    private function edges(): array
    {
        return [
            ['id' => 'triage-gate', 'source' => 'triage', 'target' => 'gate'],
            ['id' => 'gate-command', 'source' => 'gate', 'target' => 'command'],
            ['id' => 'gate-no-result', 'source' => 'gate', 'target' => 'no-result'],
        ];

    }//end edges()

    /**
     * Whether an agentflow with the seeded name already exists (system context, no RBAC).
     *
     * @param ObjectService $objectService The OpenRegister object service.
     *
     * @return bool True when a matching object exists.
     */
    private function flowExists(ObjectService $objectService): bool
    {
        $objects = $objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::FLOW_SCHEMA)
            ->findAll(
                config: ['filters' => ['name' => self::FLOW_NAME], 'limit' => 50],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['name'] ?? '') === self::FLOW_NAME) {
                return true;
            }
        }

        return false;

    }//end flowExists()
}//end class
