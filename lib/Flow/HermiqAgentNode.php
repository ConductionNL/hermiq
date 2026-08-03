<?php

/**
 * The agent step, contributed to OpenRegister's flow engine.
 *
 * This is what makes hermiq a CONSUMER of the fleet's one flow engine rather
 * than the owner of a sixth one (ADR-022, ADR-065). hermiq keeps the one thing
 * only it can do — run an agent turn — and contributes it as a node type;
 * OpenRegister's engine walks the graph, handles branching, joins, waits, run
 * persistence and the trace. hermiq's own graph executor is gone.
 *
 * The turn itself is unchanged: the proven `ScheduleService::runAgentAsOwner()`,
 * the same call the old executor made. Only the surface around it moves — from
 * a bespoke walker to OpenRegister's `IFlowNode`.
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

use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Throwable;
use UnexpectedValueException;

/**
 * Runs an agent turn as one step of an OpenRegister flow.
 *
 * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
class HermiqAgentNode implements IFlowNode
{

    /**
     * Appended to the prompt when the node declares `expectJson`.
     *
     * @var string
     */
    private const JSON_INSTRUCTION = 'Reply with a single valid JSON object and nothing else. '
        .'No prose before or after it, and no markdown code fence.';

    /**
     * Constructor.
     *
     * @param ScheduleService $scheduleService The proven agent-turn runner.
     * @param IL10N           $l10n            Translations.
     * @param IURLGenerator   $urls            For the palette icon.
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls
    ) {

    }//end __construct()

    /**
     * The step type.
     *
     * @return string The id.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function getId(): string
    {
        return 'hermiq.agent-step';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Agent step');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Run an agent turn and put its answer on the item.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('hermiq', 'app-dark.svg');

    }//end getIcon()

    /**
     * Available in both scopes; the agent turn enforces its own identity.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * Reject an agent step that names no agent.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When no agent is named.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function validateConfig(array $config): void
    {
        if (trim((string) ($config['agentId'] ?? ($config['agent'] ?? ''))) === '') {
            throw new UnexpectedValueException($this->l10n->t('An agent step needs an agent.'));
        }

    }//end validateConfig()

    /**
     * Run the agent once per item, putting its answer on each.
     *
     * The agent is run once for each item so a fanned-out collection is each
     * handled — the same as every other node in the item model. The answer is
     * stored under the configured `output` key (default `result`); with
     * `expectJson` the answer is parsed so a later node can read one field.
     *
     * A turn that fails, and an `expectJson` answer that is not JSON, both
     * FAIL THE STEP rather than putting a placeholder on the item. See the
     * comments at each site: a downstream router cannot tell "the agent
     * answered nothing" from "the agent never answered", so the node must not
     * present the second as the first.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata (carries the triggering user).
     *
     * @return array The items, each with the agent's answer added.
     *
     * @throws Throwable When the agent turn fails; the step's `onError` policy
     *                   decides what the run then does.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    public function execute(array $items, array $config, array $context): array
    {
        // Third shape of the same defect: a step that did not run must not
        // report success. `validateConfig()` already rejects a nameless agent,
        // but it only runs when a flow is SAVED — a flow imported or seeded
        // through another path reaches `execute()` unvalidated, and returning
        // the items unchanged makes the step a silent pass-through. The output
        // key is then absent, so a downstream router takes its default branch
        // exactly as though the agent had answered.
        $agentId = trim((string) ($config['agentId'] ?? ($config['agent'] ?? '')));
        if ($agentId === '') {
            throw new UnexpectedValueException($this->l10n->t('An agent step needs an agent.'));
        }

        $outKey = (string) ($config['output'] ?? 'result');
        $owner  = (string) ($config['owner'] ?? ($context['triggeredBy'] ?? ''));
        $org    = (string) ($config['organisation'] ?? '');

        $out = [];
        foreach ($items as $index => $item) {
            $json   = (array) ($item['json'] ?? []);
            $prompt = $this->render(template: (string) ($config['prompt'] ?? ''), json: $json);
            if (($config['expectJson'] ?? false) === true) {
                $prompt .= "\n\n".self::JSON_INSTRUCTION;
            }

            // A failed turn is NOT caught here. The engine's per-step `onError`
            // policy is what decides — and it only ever sees failures that
            // propagate out of `execute()`.
            //
            // This used to swallow every Throwable into `$answer = ''` while
            // its own comment claimed the run's onError handling would decide.
            // It could not: `FlowEngine::outcomeForFailedStep()` is reached from
            // the `catch (Throwable)` around the step dispatch, so a swallowed
            // failure never reached it. The step reported success, the item
            // carried an empty answer, and a downstream router read '' as
            // "the agent said nothing" rather than "the agent never ran".
            //
            // That is the wrong default for anything the answer gates. hydra's
            // pipeline is the case in point: a failed applier turn would leave
            // `json.stage.verdict` empty, the flow would release its slot and
            // finish clean, and the run would look like a completed tick that
            // simply had nothing to do.
            //
            // Authors who genuinely want the walk to continue past a failed
            // turn already have the supported way to say so — `onError:
            // continue` on the step — and saying it there records the choice in
            // the flow rather than hard-coding it for everyone.
            $answer = $this->scheduleService->runAgentAsOwner(
                owner: $owner,
                agentId: $agentId,
                prompt: $prompt,
                organisation: $org,
                dryRun: false,
                forceOwner: false,
                anchor: null
            );

            $json[$outKey] = $this->decode(config: $config, answer: $answer);

            $out[] = [
                'json'       => $json,
                'binary'     => (array) ($item['binary'] ?? []),
                'pairedItem' => ['item' => $index],
            ];
        }//end foreach

        return $out;

    }//end execute()

    /**
     * Substitute `{{dotted.path}}` placeholders from the item's json.
     *
     * @param string $template The prompt template.
     * @param array  $json     The item's record.
     *
     * @return string The rendered prompt.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    private function render(string $template, array $json): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/',
            static function (array $matches) use ($json): string {
                $value = $json;
                foreach (explode('.', $matches[1]) as $segment) {
                    if (is_array($value) === false || array_key_exists($segment, $value) === false) {
                        return '';
                    }

                    $value = $value[$segment];
                }

                if (is_array($value) === true) {
                    return (string) json_encode($value);
                }

                return (string) $value;
            },
            $template
        );

    }//end render()

    /**
     * Parse the answer when JSON was asked for; keep the raw text otherwise.
     *
     * `expectJson` is a declaration by the flow author that a later node will
     * read FIELDS off this answer. When the answer is not JSON that contract is
     * broken, so this throws rather than handing back the prose.
     *
     * Returning the raw string — what this did before — is the quiet version of
     * the same failure the swallowed Throwable caused in `execute()`: the step
     * succeeds, `json.<output>` is a string, every `{{output.field}}` read
     * resolves to empty, and the router takes its default branch as though the
     * agent had genuinely decided that. An agent that replies with an apology
     * instead of a verdict is not a "no" and must not be routed as one.
     *
     * @param array  $config The step configuration.
     * @param string $answer The agent's answer.
     *
     * @return mixed The decoded value, or the raw string when no JSON was asked for.
     *
     * @throws UnexpectedValueException When `expectJson` is set and the answer is not JSON.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    private function decode(array $config, string $answer)
    {
        if (($config['expectJson'] ?? false) !== true) {
            return $answer;
        }

        $text = trim($answer);
        if (str_starts_with($text, '```') === true) {
            $text = trim((string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text));
        }

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            // The excerpt is bounded because an agent answer can be long and
            // this message travels into the run log and onto the failed run.
            throw new UnexpectedValueException(
                $this->l10n->t(
                    'The agent step asked for JSON and the agent answered with something else: %s',
                    [mb_substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, 200)]
                )
            );
        }

        return $decoded;

    }//end decode()
}//end class
