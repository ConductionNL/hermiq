<?php

/**
 * Hermiq Seed Hydra Triage Flow Repair Step.
 *
 * Seeds the automated triage loop as DATA: one row in OpenRegister's native flow
 * store, walked by OpenRegister's flow engine (ADR-065). It is deliberately NOT a Hermiq service, a
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
 * The `gate` is not decoration, and what it guards has narrowed. A FAILED turn no
 * longer reaches it at all: `HermiqAgentNode::execute()` lets the failure
 * propagate, so the step's `onError` policy ends the run (hermiq#89 — it used to
 * catch every `Throwable` and set the answer to an empty string, which made a
 * failed LLM turn indistinguishable from a silent one right here). What the gate
 * still guards is the case that is not a failure: a turn that succeeded and
 * proposed nothing. Without it that would fall through to a step that commands a
 * build pipeline.
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
 * Idempotent by the seeded `name`, written into OpenRegister's native flow store
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

use DateTime;
use OCA\Hermiq\AppInfo\Application;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the "Hydra Triage" flow into the native flow store (idempotent, by name).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The count is the seeded GRAPH's
 *   vocabulary — Flow, FlowMapper, ObjectService, ObjectEntity and the datetime and
 *   container types it needs to build one — plus IAppConfig for the seed-outcome
 *   breadcrumb. Splitting the class would move the same types behind a collaborator
 *   without removing one, and would put the flow's shape somewhere other than the
 *   step that owns it.
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
     * App-config key holding how the last seed attempt ended.
     *
     * `seeded` | `present` | `unavailable` | `failed`. Readable with
     * `occ config:app:get hermiq hydra_triage_flow_seed`.
     *
     * @var string
     */
    public const OUTCOME_KEY = 'hydra_triage_flow_seed';

    /**
     * App-config key holding the exception class + message when the seed failed.
     *
     * @var string
     */
    public const OUTCOME_DETAIL_KEY = 'hydra_triage_flow_seed_detail';

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
     * @param ContainerInterface $container Server container for lazy FlowMapper/ObjectService resolution
     *                                      (OpenRegister may not be installed yet).
     * @param LoggerInterface    $logger    PSR-3 logger.
     * @param IAppConfig|null    $appConfig Records how the seed ended, somewhere a 50-line CI log
     *                                      tail cannot discard. Nullable so every existing
     *                                      construction site — including the unit tests — is
     *                                      unchanged; a null simply writes no breadcrumb.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ?IAppConfig $appConfig=null,
    ) {
    }//end __construct()

    /**
     * Record how this seed ended, where something other than a log tail can read it.
     *
     * 🔴 Why an app-config breadcrumb and not just a log line.
     *
     * This step already logged its failure. It made no difference: the seed has
     * been silently writing nothing on clean installs (hermiq#140), and two
     * separate investigations could not name the exception because CI keeps a
     * 50-line log tail and the install output is thousands of lines earlier.
     * A diagnosis nobody can retrieve is the same as no diagnosis.
     *
     * The outcome is therefore written to app config, which survives the run and
     * is readable through the settings API — so an e2e (or an operator with
     * `occ config:app:get`) can ask "what happened during install?" and get the
     * exception CLASS and message back rather than an absence.
     *
     * Never throws: a breadcrumb that could break the install it is describing
     * would be worse than none.
     *
     * @param string $outcome One of `seeded`, `present`, `unavailable`, `failed`.
     * @param string $detail  Exception class + message when the outcome is a failure.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
     */
    private function recordOutcome(string $outcome, string $detail=''): void
    {
        if ($this->appConfig === null) {
            return;
        }

        try {
            $this->appConfig->setValueString(Application::APP_ID, self::OUTCOME_KEY, $outcome);
            $this->appConfig->setValueString(
                Application::APP_ID,
                self::OUTCOME_DETAIL_KEY,
                mb_substr($detail, 0, 500)
            );
        } catch (Throwable $e) {
            $this->logger->warning('[hermiq] Could not record the flow-seed outcome: '.$e->getMessage());
        }

    }//end recordOutcome()

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
            $mapper = $this->container->get(FlowMapper::class);
        } catch (Throwable $e) {
            $output->warning('OpenRegister not available — skipping Hydra Triage flow seed.');
            $this->logger->warning('[hermiq] Hydra Triage flow seed skipped: '.$e->getMessage());
            $this->recordOutcome(outcome: 'unavailable', detail: $e::class.': '.$e->getMessage());
            return;
        }

        try {
            if ($this->flowExists(mapper: $mapper) === true) {
                $output->info('Hydra Triage flow already present — skipped.');
                $this->recordOutcome(outcome: 'present');
                return;
            }

            $data = $this->flowObject();

            $flow = new Flow();
            $flow->setUuid($this->newUuid());
            $flow->setApp(Application::APP_ID);
            $flow->setName(self::FLOW_NAME);
            $flow->setDescription((string) ($data['description'] ?? ''));
            $flow->setTrigger((string) ($data['trigger'] ?? ''));
            $flow->setTriggerRegister(($data['triggerRegister'] ?? null));
            $flow->setTriggerSchema(($data['triggerSchema'] ?? null));
            $flow->setCron(($data['cron'] ?? null));
            $flow->setNodes((array) ($data['nodes'] ?? []));
            $flow->setEdges((array) ($data['edges'] ?? []));
            $flow->setLimits((array) ($data['limits'] ?? []));
            $flow->setCreated(new DateTime());
            $flow->setUpdated(new DateTime());

            // Seeded DISABLED and OWNERLESS, deliberately. A repair step runs with
            // no user session, so there is no identity to attribute the flow to,
            // and a flow with no owner cannot dispatch. Seeding it enabled would
            // mean picking an owner for a graph that runs agents — a privilege
            // decision nobody made. Enabling it is a human act that supplies one.
            $flow->setEnabled(false);
            $flow->setOwner(null);

            $mapper->insert($flow);
            $output->info('Hydra Triage flow seeded (disabled — enable it to supply its owner).');
            $this->recordOutcome(outcome: 'seeded');
        } catch (Throwable $e) {
            $output->warning('Could not seed the Hydra Triage flow: '.$e->getMessage());
            // The CLASS as well as the message: hermiq#140 has twice been
            // narrowed to "something in here threw" because the message alone
            // did not say whether it was a missing table, a constraint, or a
            // container resolution.
            $this->logger->error(
                '[hermiq] Hydra Triage flow seed failed: '.$e::class.': '.$e->getMessage(),
                ['exception' => $e]
            );
            $this->recordOutcome(outcome: 'failed', detail: $e::class.': '.$e->getMessage());
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
                .'dispatch. When a command step exists, change the `command-stop` EDGE\'s `type` to it; until '
                .'then the branch stops with the proposed label recorded and writes nothing. Note the work '
                .'lives on the edges — a `type` on a node is never read by the engine.',
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
            ['id' => 'finding', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'triaged', 'position' => ['x' => 260, 'y' => 0]],
            ['id' => 'command', 'position' => ['x' => 520, 'y' => -80]],
            ['id' => 'no-result', 'position' => ['x' => 520, 'y' => 80]],
            ['id' => 'done', 'position' => ['x' => 780, 'y' => 0]],
        ];

    }//end nodes()

    /**
     * The flow's edges — and the flow's WORK.
     *
     * 🔑 In OpenRegister's engine the executable unit is the EDGE. `FlowEngine::stepFor()`
     * resolves a transition to the matching entry in `edges[]`, and
     * `RegistryStepDispatcher::dispatch()` reads `type` and `config` off THAT edge. A node
     * is a Petri-net PLACE and carries no behaviour: a `type` on it is never read.
     *
     * This seed used to put every `type`/`config` on `nodes[]`, which made the whole flow
     * inert — a step with no `type` passes its items through untouched, so the graph
     * imported cleanly, walked all three edges and reported `completed` having called no
     * agent and taken no branch. Silently: no error, no warning, no log line. Measured on a
     * live instance 2026-07-31 both ways, node form versus edge form, on the same graph.
     *
     * @return array<int, array<string, mixed>>
     */
    private function edges(): array
    {
        return [
            [
                'id'     => 'triage',
                'from'   => 'finding',
                'to'     => 'triaged',
                'type'   => 'hermiq.agent-step',
                'config' => [
                    // ⚠️ A UUID, not a name. `AgentMapper::findByUuid()` matches the
                    // `uuid` COLUMN, so the seeded agent's NAME — which this carried —
                    // resolves to nothing at all. Since hermiq#89 that fails the step
                    // loudly instead of being swallowed into an empty answer, but a seed
                    // that can never resolve is still a seed that never runs. It is
                    // filled in at seed time from the agent seed, and left EMPTY when
                    // that lookup fails, because an empty agent is refused at both
                    // validate and execute time — which is the honest outcome.
                    'agentId'    => $this->triageAgentUuid(),
                    'output'     => self::TRIAGE_OUTPUT_KEY,
                    'expectJson' => true,
                    'prompt'     => 'Triage this pipeline finding and reply as JSON with two keys: '
                        .'"summary" (one sentence on what is blocking the work) and "label" (the single '
                        .'state-machine label that would move it forward, or an empty string if you are not '
                        .'confident). Finding: {{json.title}} — {{json.description}}',
                ],
            ],
            [
                'id'     => 'gate',
                'from'   => 'triaged',
                // Both branch places must be reachable FROM THIS EDGE:
                // `FlowEngine::advanceItems()` only distributes items to the places on
                // the firing transition's own `to` list, so an output naming anything
                // else drops every item routed to it, silently.
                'to'     => ['command', 'no-result'],
                'type'   => 'openregister.route',
                'config' => [
                    // The router tags each item with the OUTPUT it selected, and the
                    // engine delivers an item only to the place matching its tag —
                    // so an output name IS a target place id.
                    'rules'   => [
                        [
                            'output'    => 'command',
                            // Truthy only when the agent actually proposed a label.
                            // A turn that FAILS no longer arrives here at all (hermiq#89
                            // fails the step, and its onError policy ends the run); what
                            // this still guards is a turn that succeeded and proposed
                            // nothing, which must not reach a pipeline command.
                            'condition' => ['!!' => ['var' => 'json.'.self::TRIAGE_OUTPUT_KEY.'.label']],
                        ],
                    ],
                    'default' => 'no-result',
                ],
            ],
            [
                'id'     => 'command-stop',
                'from'   => 'command',
                'to'     => 'done',
                // Built-in stop while the OpenConnector-backed command step is not
                // wired up — see the class docblock. Swapping this `type` is the whole
                // change needed when that upstream half ships.
                'type'   => 'openregister.stop',
                'config' => [
                    'error'   => false,
                    'message' => 'A label was proposed and recorded. No forge write was attempted: this flow '
                        .'has no command step wired up yet.',
                ],
            ],
            [
                'id'     => 'no-result-stop',
                'from'   => 'no-result',
                'to'     => 'done',
                'type'   => 'openregister.stop',
                'config' => [
                    'error'   => false,
                    'message' => 'The agent proposed no label — the flow stops before the command step.',
                ],
            ],
        ];

    }//end edges()

    /**
     * The seeded triage agent's uuid, or an empty string when it cannot be resolved.
     *
     * The two seeds stay independent by NAME rather than by a hard-coded uuid — a repair
     * step that pins another seed's uuid breaks whenever either is re-seeded. So the name
     * is resolved to a uuid here, at seed time, once.
     *
     * Returning '' when the agent is absent is deliberate. An agent step with no agent is
     * refused at both validate and execute time, so the flow announces the missing half
     * instead of carrying an identifier that silently resolves to nothing.
     *
     * @return string The uuid, or ''.
     */
    private function triageAgentUuid(): string
    {
        try {
            $objectService = $this->container->get(ObjectService::class);
            $agents        = $objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(SeedHydraTriageAgent::AGENT_SCHEMA)
                ->findAll(
                    config: ['filters' => ['name' => SeedHydraTriageAgent::AGENT_NAME], 'limit' => 5],
                    _rbac: false,
                    _multitenancy: false
                );

            foreach ($agents as $agent) {
                if (($agent instanceof ObjectEntity) === false) {
                    continue;
                }

                $uuid = trim((string) $agent->getUuid());
                if ($uuid !== '') {
                    return $uuid;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                '[hermiq] Could not resolve the Hydra Triage agent uuid for the flow seed: '.$e->getMessage()
            );
        }//end try

        return '';

    }//end triageAgentUuid()

    /**
     * Whether an agentflow with the seeded name already exists (system context, no RBAC).
     *
     * @param FlowMapper $mapper The flow store.
     *
     * @return bool True when a matching object exists.
     */
    private function flowExists(FlowMapper $mapper): bool
    {
        foreach ($mapper->findAllFlows(app: Application::APP_ID, limit: 500) as $flow) {
            if ($flow->getName() === self::FLOW_NAME) {
                return true;
            }
        }

        return false;

    }//end flowExists()

    /**
     * Mint a v4 uuid for the seeded flow.
     *
     * @return string The uuid.
     */
    private function newUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    }//end newUuid()
}//end class
