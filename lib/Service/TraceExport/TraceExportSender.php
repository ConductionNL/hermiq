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
		$refusal = $this->refusal(endpoint: $endpoint, allowPrivate: $allowPrivate);
		if ($refusal !== null) {
			return $refusal;
		}

		$authorization = $this->authorization(settings: $settings);
		if ($authorization === null) {
			return $this->failed(retry: false, message: 'The trace export credential could not be resolved from the credential broker.');
		}

		$options = $this->requestOptions(payload: $payload, authorization: $authorization);
		$options['nextcloud'] = ['allow_local_address' => $allowPrivate];

		return $this->post(endpoint: $endpoint, options: $options);

	}//end send()

	/**
	 * The outcome when the egress guard refuses the endpoint, or null when it allows it.
	 *
	 * @param string $endpoint     The collector URL.
	 * @param bool   $allowPrivate Whether the administrator allowed a private or plain-http endpoint.
	 *
	 * @return array{ok: bool, retry: bool, status: int, message: string}|null The refusal, or null.
	 */
	private function refusal(string $endpoint, bool $allowPrivate): ?array {
		$verdict = $this->egressGuard->assertSafe(
			url: $endpoint,
			isAdminConfiguredEndpoint: $allowPrivate,
			allowInsecureHttp: $allowPrivate
		);
		if (($verdict['allowed'] ?? false) === true) {
			return null;
		}

		$reason = (string)($verdict['message'] ?? ($verdict['code'] ?? 'not allowed'));

		return $this->failed(retry: false, message: 'The endpoint was refused: ' . $reason);

	}//end refusal()

	/**
	 * The HTTP client options for one batch, without the local-address switch.
	 *
	 * @param array<string, mixed> $payload       The OTLP body.
	 * @param string               $authorization The Authorization header value.
	 *
	 * @return array<string, mixed> The options.
	 */
	private function requestOptions(array $payload, string $authorization): array {
		return [
			'body' => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => $authorization,
			],
			'timeout' => self::TIMEOUT_SECONDS,
			'http_errors' => false,
		];

	}//end requestOptions()

	/**
	 * POST the batch to the collector.
	 *
	 * @param string               $endpoint The collector URL.
	 * @param array<string, mixed> $options  The HTTP client options.
	 *
	 * @return array{ok: bool, retry: bool, status: int, message: string} The outcome.
	 */
	private function post(string $endpoint, array $options): array {
		try {
			$status = $this->clientService->newClient()->post($endpoint, $options)->getStatusCode();
		} catch (Throwable $e) {
			return $this->failed(retry: true, message: 'The collector could not be reached: ' . $e->getMessage());
		}

		if ($status >= 200 && $status < 300) {
			return ['ok' => true, 'retry' => false, 'status' => $status, 'message' => ''];
		}

		return ['ok' => false, 'retry' => true, 'status' => $status, 'message' => 'The collector answered HTTP ' . $status . '.'];

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
	 * A batch that was not delivered and got no HTTP status.
	 *
	 * @param bool   $retry   Whether trying again can help.
	 * @param string $message Why.
	 *
	 * @return array{ok: bool, retry: bool, status: int, message: string} The outcome.
	 */
	private function failed(bool $retry, string $message): array {
		return ['ok' => false, 'retry' => $retry, 'status' => 0, 'message' => $message];

	}//end failed()
}//end class
