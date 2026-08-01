<?php

/**
 * The workload step, contributed to OpenRegister's flow engine.
 *
 * The agent node's counterpart. `hermiq.agent-step` runs a MODEL TURN;
 * this runs a piece of work that needs a FILESYSTEM — clone a ref, run an
 * allowlisted command over it, put the result on the item.
 *
 * WHY A SECOND NODE RATHER THAN A MODE ON THE FIRST
 * ------------------------------------------------
 * A governed agent turn is invoked with
 * `--disallowedTools Bash,Read,Write,Edit,Glob,Grep` and its sidecar is
 * read_only, mountless and routeless — that is the whole point of the
 * transport. So hydra's builder, reviewer and security stages, which are
 * analysis over a checked-out tree with a `composer install` in it, had no
 * expression in a flow at all.
 *
 * They cannot be expressed as flow nodes either: `run-hydra-gates.sh` is 3,599
 * lines over 59 gates, and re-stating that as a graph would be a rewrite whose
 * two implementations of the same rules are guaranteed to drift.
 *
 * So the shell stays shell, and this node is the seam that lets a flow invoke
 * it: the BUSINESS LOGIC (selection, slots, locks, retry, escalation) is the
 * flow, visible on a canvas; the filesystem work is a command, run in the one
 * place inside Nextcloud that has a filesystem and a toolchain.
 *
 * The operator this is built for has a Kubernetes environment with Nextcloud on
 * it and LLM access, and knows how neither works. There is no host to run a
 * container on and no cluster credential to create a Job with — the ExApp is
 * what they already have.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Service\StageDispatchService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Throwable;
use UnexpectedValueException;

/**
 * Runs one filesystem workload as a step of an OpenRegister flow.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
class HermiqWorkloadNode implements IFlowNode
{
    /**
     * Constructor.
     *
     * @param StageDispatchService $stages The ExApp stage dispatcher.
     * @param IL10N                $l10n   Translations.
     * @param IURLGenerator        $urls   For the palette icon.
     */
    public function __construct(
        private readonly StageDispatchService $stages,
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls
    ) {
    }//end __construct()

    /**
     * The step type.
     *
     * @return string The id.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
     */
    public function getId(): string
    {
        return 'hermiq.workload-step';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Workload step');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Check out a ref and run a command over it, then put the result on the item.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('hermiq', 'app-dark.svg');

    }//end getIcon()

    /**
     * Available in both scopes.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * Reject a workload step that names no repo, ref or command.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When a required field is missing.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-flow-can-dispatch-a-workload-as-a-step
     */
    public function validateConfig(array $config): void
    {
        $this->assertConfigured(config: $config);

    }//end validateConfig()

    /**
     * Run the workload once per item, putting its result on each.
     *
     * WHAT IS AND IS NOT A STEP FAILURE — the distinction this node turns on:
     *
     *   - the command RAN and exited non-zero  ->  DATA. hydra's gate runner
     *     uses its exit code as a failure COUNT, so a router downstream is
     *     meant to read it. Throwing here would make a flow unable to express
     *     "if the gates failed, comment and retry".
     *   - the workload COULD NOT BE RUN       ->  THROWS. An unreachable
     *     ExApp, a refused command, a clone that failed. The step's `onError`
     *     policy then decides, and it only ever sees failures that propagate
     *     out of `execute()`.
     *
     * Collapsing the two is the defect this codebase has now hit in four
     * shapes: a downstream router cannot tell "the gates found nothing" from
     * "the gates never ran", and both look like a clean tick.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata (carries the triggering user).
     *
     * @return array The items, each with the workload's result added.
     *
     * @throws Throwable When the workload could not be run; `onError` decides.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-command-that-ran-and-failed-is-data-not-a-step-failure
     */
    public function execute(array $items, array $config, array $context): array
    {
        // `validateConfig()` only runs when a flow is SAVED — a flow imported
        // or seeded through another path reaches `execute()` unvalidated, and
        // returning the items unchanged would make the step a silent
        // pass-through whose output key is simply absent. A downstream router
        // then takes its default branch exactly as though the command had run.
        $this->assertConfigured(config: $config);

        $outKey = (string) ($config['output'] ?? 'stage');
        $owner  = trim((string) ($config['owner'] ?? ($context['triggeredBy'] ?? '')));

        // ATTRIBUTION IS MANDATORY, and refusing here is the point rather than a
        // side effect. hydra's record answers "who ran this, on whose
        // credential" out of `cycles[].owner` and `stages[].credential_owner`,
        // and a stage that cannot say costs the record that answer FOREVER —
        // the run is durable, the missing attribution is not recoverable later.
        //
        // An unattributed stage is also the shape a credential misuse takes: a
        // Claude subscription serves its owner and never a pool, so "no owner"
        // is precisely the state in which no credential may be selected. hydra's
        // shell said this with `_owner_candidate_indices`; the flow path says it
        // here, once, before anything is dispatched.
        if ($owner === '') {
            throw new UnexpectedValueException(
                $this->l10n->t('A workload step must be attributable: it names no owner and the run records none.')
            );
        }

        $out = [];
        foreach ($items as $index => $item) {
            $json = (array) ($item['json'] ?? []);

            // RENDERED, like every other configured value, and rendered PER ITEM
            // because a fan-out may carry a different credential per repository.
            //
            // This was the one field the first version left un-rendered, and the
            // failure was invisible: the literal string `{{forgeCredential}}`
            // went to the broker as a credential id, the broker could not find
            // it, and its `catch (Throwable) { $credential = null; }` reported
            // `credential not found` — which reads as a missing credential
            // rather than an unrendered placeholder. It took a logging fix in
            // OpenRegister (openregister#2245) to see the id it was actually
            // given.
            $credentialId = trim($this->render(template: (string) ($config['credentialId'] ?? ''), json: $json));

            $result = $this->stages->dispatch(
                repo: $this->render(template: (string) $config['repo'], json: $json),
                ref: $this->render(template: (string) $config['ref'], json: $json),
                command: array_map(
                    fn (string $argument): string => $this->render(template: $argument, json: $json),
                    array_values((array) $config['command'])
                ),
                uid: $owner,
                credentialId: $credentialId,
                timeoutMs: (int) ($config['timeoutMs'] ?? 0),
                // The tool tree, when the command does not live in the tree it
                // runs over. hydra's gate runner is that case: it takes the app
                // to gate as an argument and finds its own helpers beside
                // itself, so gating an app needs hydra's scripts AND the app's
                // tree. The alternative is every app vendoring 3,599 lines of
                // gate runner, which would drift the day after it was copied.
                toolRepo: $this->render(template: (string) ($config['toolRepo'] ?? ''), json: $json),
                toolRef: $this->render(template: (string) ($config['toolRef'] ?? ''), json: $json)
            );

            // The attribution travels WITH the result, not beside it. hydra's
            // record composer reads one object per stage, and an owner it has
            // to correlate from run metadata is an owner that goes missing the
            // first time a flow fans out over several repositories.
            //
            // `credential_owner` is the run owner deliberately: the broker only
            // resolves a credential FOR its owner, so the identity that
            // successfully used it is the identity it belongs to. Recording
            // anything else would be recording an assumption.
            $credentialOwner = null;
            $credentialName  = null;
            if ($credentialId !== '') {
                $credentialOwner = $owner;
                $credentialName  = $credentialId;
            }

            $result['owner']            = $owner;
            $result['credential_owner'] = $credentialOwner;
            $result['credential_name']  = $credentialName;

            $json[$outKey] = $result;

            $out[] = [
                'json'       => $json,
                'binary'     => (array) ($item['binary'] ?? []),
                'pairedItem' => ['item' => $index],
            ];
        }//end foreach

        return $out;

    }//end execute()

    /**
     * Assert the three fields a workload cannot run without.
     *
     * Shared by `validateConfig()` and `execute()` deliberately: an author
     * saving a flow and a flow arriving through an import must be held to the
     * same contract, and one copy of it is the only way that stays true.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When a required field is missing.
     */
    private function assertConfigured(array $config): void
    {
        if (trim((string) ($config['repo'] ?? '')) === '') {
            throw new UnexpectedValueException($this->l10n->t('A workload step needs a repository.'));
        }

        if (trim((string) ($config['ref'] ?? '')) === '') {
            throw new UnexpectedValueException($this->l10n->t('A workload step needs a ref.'));
        }

        $command = ($config['command'] ?? null);
        if (is_array($command) === false || $command === [] || trim((string) ($command[0] ?? '')) === '') {
            // An argv ARRAY rather than a command string, so nothing here has
            // to parse a shell. The runner allowlists the first token; a string
            // would invite splitting it here and getting quoting wrong.
            throw new UnexpectedValueException(
                $this->l10n->t('A workload step needs a command, given as an array of arguments.')
            );
        }

    }//end assertConfigured()

    /**
     * Substitute `{{dotted.path}}` placeholders from the item's json.
     *
     * @param string $template The template.
     * @param array  $json     The item's record.
     *
     * @return string The rendered string.
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
}//end class
