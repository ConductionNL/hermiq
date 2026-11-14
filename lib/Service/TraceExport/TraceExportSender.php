<?php

/**
 * Hermiq Trace Export Sender.
 *
 * Sends one OTLP/HTTP batch to the configured endpoint. The endpoint passes the egress
 * guard first, the secret is resolved from OpenRegister's credential broker at send time
 * (an inject-only generic provider, since a tracing host cannot be pinned in the catalog)
 * and goes only into the `Authorization` header of this one request. The secret is never
 * logged, stored or returned.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\TraceExport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-export-never-slows-or-changes-a-run-req-trex-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\TraceExport;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\WebResearchEgressGuardInterface;
use OCP\Http\Client\IClientService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * POST a batch to the OTLP endpoint with the brokered credential.
 */
class TraceExportSender {

	/**
	 * OpenRegister's credential broker, resolved by name so hermiq boots without it.
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * Seconds a send may take.
	 *
	 * @var integer
	 */
	public const TIMEOUT_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService                  $clientService HTTP client factory.
	 * @param WebResearchEgressGuardInterface $egressGuard   Refuses private and metadata destinations.
	 * @param ContainerInterface|null         $container     Resolves the optional credential broker.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly WebResearchEgressGuardInterface $egressGuard,
		private readonly ?ContainerInterface $container = null,
	) {

	}//end __construct()

	/**
	 * Send one batch.
	 *
	 * @param array<string, mixed> $payload  The OTLP body.
	 * @param array<string, mixed> $settings The trace export settings.
	 *
	 * @return array{ok: bool, retry: bool, status: int, message: string} The outcome; `retry`
	 *         is false when trying again cannot help (refused endpoint, missing credential).
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-3-the-queue-the-job-and-the-broker-call
	 */
	public function send(array $payload, array $settings): array {
		$endpoint = (string)($settings['endpoint'] ?? '');
		$allowPrivate = (($settings['allowPrivateEndpoint'] ?? false) === true);

		$verdict = $this->egressGuard->assertSafe(
			url: $endpoint,
			isAdminConfiguredEndpoint: $allowPrivate,
			allowInsecureHttp: $allowPrivate
		);
		if (($verdict['allowed'] ?? false) !== true) {
			$reason = (string)($verdict['message'] ?? ($verdict['code'] ?? 'not allowed'));
			return $this->outcome(ok: false, retry: false, status: 0, message: 'The endpoint was refused: ' . $reason);
		}

		$authorization = $this->authorization(settings: $settings);
		if ($authorization === null) {
			$message = 'The trace export credential could not be resolved from the credential broker.';
			return $this->outcome(ok: false, retry: false, status: 0, message: $message);
		}

		return $this->post(endpoint: $endpoint, payload: $payload, authorization: $authorization, allowPrivate: $allowPrivate);

	}//end send()

	/**
	 * POST the body and read the answer.
	 *
	 * @param string               $endpoint      The OTLP traces URL.
	 * @param array<string, mixed> $payload       The body.
	 * @param string               $authorization The Authorization header value.
	 * @param bool                 $allowPrivate  Whether the admin allowed a local collector.
	 *
	 * @return array{ok: bool, retry: bool, status: int, message: string}
	 */
	private function post(string $endpoint, array $payload, string $authorization, bool $allowPrivate): array {
		try {
			$response = $this->clientService->newClient()->post(
				$endpoint,
				[
					'body' => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
					'headers' => [
						'Content-Type' => 'application/json',
						'Authorization' => $authorization,
					],
					'timeout' => self::TIMEOUT_SECONDS,
					'http_errors' => false,
					'nextcloud' => ['allow_local_address' => $allowPrivate],
				]
			);
			$status = $response->getStatusCode();
		} catch (Throwable $e) {
			return $this->outcome(ok: false, retry: true, status: 0, message: 'The collector could not be reached: ' . $e->getMessage());
		}

		if ($status >= 200 && $status < 300) {
			return $this->outcome(ok: true, retry: false, status: $status, message: '');
		}

		return $this->outcome(ok: false, retry: true, status: $status, message: 'The collector answered HTTP ' . $status . '.');

	}//end post()

	/**
	 * The Authorization header for the brokered secret, after its generic provider.
	 *
	 * @param array<string, mixed> $settings The trace export settings.
	 *
	 * @return string|null The header value, or null when no secret can be resolved.
	 */
	private function authorization(array $settings): ?string {
		$secret = $this->secret(credentialId: (string)($settings['credentialId'] ?? ''), organisation: (string)($settings['organisation'] ?? ''));
		if ($secret === null || $secret === '') {
			return null;
		}

		return match ((string)($settings['authScheme'] ?? 'bearer')) {
			'basic' => 'Basic ' . base64_encode((string)($settings['username'] ?? '') . ':' . $secret),
			'apikey' => $secret,
			default => 'Bearer ' . $secret,
		};

	}//end authorization()

	/**
	 * The raw secret of an inject-only credential, resolved through the broker's own guards.
	 *
	 * @param string $credentialId The broker credential uuid.
	 * @param string $organisation The organisation the credential belongs to, for a sessionless send.
	 *
	 * @return string|null The secret, or null when it cannot be resolved.
	 */
	private function secret(string $credentialId, string $organisation): ?string {
		if ($credentialId === '' || $this->container === null || class_exists(self::BROKER_CLASS) === false) {
			return null;
		}

		$actingOrganisation = null;
		if ($organisation !== '') {
			$actingOrganisation = $organisation;
		}

		try {
			$broker = $this->container->get(self::BROKER_CLASS);
			$secret = $broker->resolveInjectable($credentialId, AppIdentity::APP_ID, null, $actingOrganisation);
		} catch (Throwable) {
			return null;
		}

		if (is_string($secret) === false) {
			return null;
		}

		return $secret;

	}//end secret()

	/**
	 * An outcome.
	 *
	 * @param bool   $ok      Whether the batch was accepted.
	 * @param bool   $retry   Whether trying again can help.
	 * @param int    $status  The HTTP status, 0 when there was none.
	 * @param string $message What went wrong, '' on success.
	 *
	 * @return array{ok: bool, retry: bool, status: int, message: string}
	 */
	private function outcome(bool $ok, bool $retry, int $status, string $message): array {
		return [
			'ok' => $ok,
			'retry' => $retry,
			'status' => $status,
			'message' => $message,
		];

	}//end outcome()
}//end class
