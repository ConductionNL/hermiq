<?php

/**
 * Dispatches one filesystem workload to the `hermiq-llm-runner` ExApp.
 *
 * The transport half of `hermiq.workload-step`. The ExApp's `POST /stage`
 * clones a ref, runs an allowlisted command over it and returns
 * `{exitCode, output, ref}`; this is what gets it there and what decides
 * which of its failure shapes is a step failure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs one stage in the runner ExApp and returns its structured result.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
class StageDispatchService
{

    /**
     * AppAPI's public dispatch surface.
     *
     * @var string
     */
    private const APP_API_PUBLIC_FUNCTIONS = 'OCA\\AppAPI\\PublicFunctions';

    /**
     * App id of the runner ExApp.
     *
     * @var string
     */
    private const RUNNER_EXAPP_ID = 'hermiq-llm-runner';

    /**
     * The runner's stage route.
     *
     * @var string
     */
    private const RUNNER_ROUTE = '/stage';

    /**
     * Default ceiling for one stage, in milliseconds.
     *
     * A gate run over a real tree does a `composer install` and 59 gates, so
     * this is minutes rather than seconds. It matches the runner's own default.
     *
     * @var int
     */
    private const DEFAULT_STAGE_TIMEOUT_MS = (30 * 60 * 1000);

    /**
     * Seconds added to the runner's own ceiling for the AppAPI request.
     *
     * The transport timeout must EXCEED the workload timeout by construction,
     * so that when a stage overruns it is the runner that kills it and reports
     * why — rather than the caller giving up first and turning a real reason
     * into a generic timeout.
     *
     * @var int
     */
    private const TRANSPORT_SLACK_SECONDS = 60;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger For operator-facing diagnostics.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {

    }//end __construct()

    /**
     * Run one stage and return its result.
     *
     * Every failure here means the workload COULD NOT BE RUN, and every one of
     * them throws. A command that ran and exited non-zero is not a failure —
     * it is the result, and it is returned.
     *
     * AppAPI's two defaults are traps and both are handled explicitly, neither
     * being visible in `exAppRequest()`'s signature:
     *
     *   1. `timeout` defaults to 3 SECONDS while a stage runs for minutes.
     *      Omitting it would make the feature 0% functional — every stage would
     *      fail at 3s while the container ran on to completion.
     *   2. AppAPI NEVER THROWS. Failure is the RETURN VALUE in three shapes: a
     *      caught exception returns `['error' => …]`, a missing ExApp returns
     *      `['error' => 'ExApp … not found']`, and `http_errors => false` makes
     *      a 502 an ordinary response. So the checks below run in a
     *      load-bearing order — array, then status, then shape. Any other order
     *      reads an error string as a stage result.
     *
     * @param string      $repo         Clone URL of the tree the command runs OVER.
     * @param string      $ref          The ref to check out.
     * @param array       $command      The command and its arguments.
     * @param string|null $uid          The acting user's UID.
     * @param string      $credentialId Broker credential for the clone, or '' for a public repo.
     * @param int         $timeoutMs    Ceiling for the stage; 0 for the default.
     * @param string      $toolRepo     Clone URL of the tree the COMMAND comes from, when it is
     *                                  not the target. hydra's gate runner is the case this
     *                                  exists for: it takes the tree it gates as an argument and
     *                                  resolves its own helpers out of its own checkout, so
     *                                  gating an app needs both trees at once.
     * @param string      $toolRef      Ref for the tool tree; its default branch when empty.
     *
     * @return array{exitCode: int, output: string, ref: string} The stage result.
     *
     * @throws RuntimeException When the stage could not be run.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) OCP\Server::get is deliberate lazy resolution
     *   of AppAPI and the optional OpenRegister broker, so this class stays constructible
     *   when either is absent.
     *
     * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-command-that-ran-and-failed-is-data-not-a-step-failure
     */
    public function dispatch(
        string $repo,
        string $ref,
        array $command,
        ?string $uid=null,
        string $credentialId='',
        int $timeoutMs=0,
        string $toolRepo='',
        string $toolRef=''
    ): array {
        $ceiling = self::DEFAULT_STAGE_TIMEOUT_MS;
        if ($timeoutMs > 0) {
            $ceiling = $timeoutMs;
        }

        $params = [
            'repo'      => $repo,
            'ref'       => $ref,
            'command'   => array_values($command),
            'timeoutMs' => $ceiling,
        ];

        if ($toolRepo !== '') {
            $params['toolRepo'] = $toolRepo;
            if ($toolRef !== '') {
                $params['toolRef'] = $toolRef;
            }
        }

        if ($credentialId !== '') {
            // The token reaches the runner in the payload and the runner puts it
            // in the child ENVIRONMENT behind `GIT_ASKPASS` — never on argv,
            // never in a file that outlives the run.
            $params['forgeToken'] = $this->resolveForgeToken(credentialId: $credentialId, uid: $uid);
            $params['forgeUser']  = 'x-access-token';
        }

        $result = Server::get(self::APP_API_PUBLIC_FUNCTIONS)->exAppRequest(
            self::RUNNER_EXAPP_ID,
            self::RUNNER_ROUTE,
            $uid,
            'POST',
            $params,
            ['timeout' => (intdiv($ceiling, 1000) + self::TRANSPORT_SLACK_SECONDS)]
        );

        // Check 1 — the never-throws failure channel. MUST precede any body read.
        if (is_array($result) === true) {
            $this->logger->warning(
                '[StageDispatchService] stage dispatch failed at the AppAPI transport',
                ['reason' => (string) ($result['error'] ?? 'unknown')]
            );

            throw new RuntimeException(
                'The workload could not reach the "'.self::RUNNER_EXAPP_ID.'" ExApp. '
                .'Check that the ExApp is running.'
            );
        }

        // Check 2 — `http_errors => false`, so a 4xx/5xx is an ordinary response.
        // The runner answers 502 when it could not carry the stage out, which is
        // exactly the distinction this method exists to preserve: a stage that
        // RAN and failed comes back 200 with a non-zero exit code.
        $status = $result->getStatusCode();
        if ($status < 200 || $status > 299) {
            $reason = $this->reasonFrom(body: (string) $result->getBody());
            $this->logger->warning(
                '[StageDispatchService] the runner refused or could not run the stage',
                [
                    'status' => $status,
                    'reason' => $reason,
                ]
            );

            throw new RuntimeException('The workload could not be run: '.$reason);
        }

        // Check 3 — only now is the body a stage result.
        return $this->mapResult(body: (string) $result->getBody());

    }//end dispatch()

    /**
     * Resolve the forge token for the clone through OpenRegister's broker.
     *
     * ⚠️ This only works for an `inject_only` credential. A HOST-LOCKED PROXY
     * credential — the shape the `github` broker credential uses — is refused
     * by `resolveInjectable()` on purpose: its secret never leaves
     * OpenRegister, which is the property that makes the proxy path worth
     * having. That refusal is surfaced verbatim rather than worked around,
     * because the alternative is a credential posture decision and it belongs
     * to the operator, not to this method.
     *
     * @param string      $credentialId The broker credential UUID.
     * @param string|null $uid          The acting user's UID.
     *
     * @return string The resolved token. Never logged.
     *
     * @throws RuntimeException When the broker is absent or refuses.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) See dispatch().
     */
    private function resolveForgeToken(string $credentialId, ?string $uid): string
    {
        if (class_exists(BrokerHttpClient::BROKER_CLASS) === false) {
            throw new RuntimeException(
                'The workload needs a forge credential and the OpenRegister credential broker is not '
                .'available, so it cannot be resolved.'
            );
        }

        try {
            $token = Server::get(BrokerHttpClient::BROKER_CLASS)
                ->resolveInjectable($credentialId, BrokerHttpClient::APP_ID, $uid);
        } catch (Throwable $e) {
            // The broker's denial reasons name the guard that refused, which is
            // the single most useful thing an operator can be told here — and
            // none of them contain the secret.
            $this->logger->warning(
                '[StageDispatchService] the broker refused the forge credential',
                [
                    'credentialId' => $credentialId,
                    'reason'       => $e->getMessage(),
                ]
            );

            throw new RuntimeException(
                'The credential broker refused the forge credential for this workload: '.$e->getMessage()
            );
        }

        if (is_string($token) === false || $token === '') {
            throw new RuntimeException('The credential broker resolved no forge token for this workload.');
        }

        return $token;

    }//end resolveForgeToken()

    /**
     * Pull the runner's error message out of a non-success body.
     *
     * `protected` rather than private so a test can reach it directly — the
     * same seam `ProviderFactory::cliDispatchOptions()` uses, and for the same
     * reason: a shape check nothing exercises is a shape check that silently
     * stops holding.
     *
     * @param string $body The response body.
     *
     * @return string The reason, or a static fallback.
     */
    protected function reasonFrom(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) === true && trim((string) ($decoded['error'] ?? '')) !== '') {
            return (string) $decoded['error'];
        }

        return 'the runner gave no reason';

    }//end reasonFrom()

    /**
     * Map the runner's body onto the stage result.
     *
     * The shape is checked rather than assumed. A body that is not a stage
     * result means the runner answered something this does not understand,
     * and handing that on as `exitCode: 0` would read downstream as a PASS.
     *
     * @param string $body The response body.
     *
     * @return array{exitCode: int, output: string, ref: string} The stage result.
     *
     * @throws RuntimeException When the body is not a stage result.
     */
    protected function mapResult(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) === false || array_key_exists('exitCode', $decoded) === false) {
            throw new RuntimeException('The runner answered with something that is not a stage result.');
        }

        return [
            'exitCode' => (int) $decoded['exitCode'],
            'output'   => (string) ($decoded['output'] ?? ''),
            'ref'      => (string) ($decoded['ref'] ?? ''),
        ];

    }//end mapResult()
}//end class
