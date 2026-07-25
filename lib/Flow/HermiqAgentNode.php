<?php

/**
 * The agent step, contributed to OpenRegister's flow engine.
 *
 * This is what makes hermiq a CONSUMER of the fleet's one flow engine rather
 * than the owner of a sixth one (ADR-022, ADR-065). hermiq keeps the one thing
 * only it can do — run an agent turn — and contributes it as a node type;
 * OpenRegister's engine walks the graph, handles branching, joins, waits, run
 * persistence and the trace. hermiq's own GraphExecutor becomes redundant.
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
     */
    public function getId(): string
    {
        return 'hermiq.agent-step';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Agent step');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Run an agent turn and put its answer on the item.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
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
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata (carries the triggering user).
     *
     * @return array The items, each with the agent's answer added.
     */
    public function execute(array $items, array $config, array $context): array
    {
        $agentId = trim((string) ($config['agentId'] ?? ($config['agent'] ?? '')));
        if ($agentId === '') {
            return $items;
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

            $answer = '';
            try {
                $answer = $this->scheduleService->runAgentAsOwner(
                    owner: $owner,
                    agentId: $agentId,
                    prompt: $prompt,
                    organisation: $org,
                    dryRun: false,
                    forceOwner: false,
                    anchor: null
                );
            } catch (Throwable $e) {
                // A failed turn does not lose the item — it carries an empty
                // answer and the run's error handling (onError) decides the rest.
                $answer = '';
            }

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
     * @param array  $config The step configuration.
     * @param string $answer The agent's answer.
     *
     * @return mixed The decoded value, or the raw string.
     *
     * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
     */
    private function decode(array $config, string $answer)
    {
        if (($config['expectJson'] ?? false) !== true || $answer === '') {
            return $answer;
        }

        $text = trim($answer);
        if (str_starts_with($text, '```') === true) {
            $text = trim((string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text));
        }

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $answer;
        }

        return $decoded;

    }//end decode()
}//end class
