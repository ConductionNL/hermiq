<?php

/**
 * Hermiq Trace Export Settings.
 *
 * The instance's trace export configuration, stored as one `IAppConfig` JSON value
 * `trace_export`: on or off, the OTLP/HTTP endpoint, the broker credential BY REFERENCE,
 * which sources export and how much of a run leaves the server. No secret is ever stored
 * here; the credential lives in OpenRegister's credential broker.
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
 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-an-administrator-configures-trace-export-with-a-brokered-credential-req-trex-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\TraceExport;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Read, validate and store the trace export configuration.
 */
class TraceExportSettings {

	/**
	 * The app config key holding the JSON value.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'trace_export';

	/**
	 * The sources a trace can come from.
	 *
	 * @var list<string>
	 */
	public const SOURCES = ['runs', 'chat'];

	/**
	 * How much of a run is exported: metadata only, or redacted text as well.
	 *
	 * @var list<string>
	 */
	public const CONTENTS = ['metadata', 'redacted-text'];

	/**
	 * How the brokered secret is put on the request, after OpenRegister's inject-only
	 * generic providers: `basic` (generic-basic, the secret is the password), `bearer`
	 * (generic-bearer) and `apikey` (generic-apikey, the bare key).
	 *
	 * @var list<string>
	 */
	public const AUTH_SCHEMES = ['basic', 'bearer', 'apikey'];

	/**
	 * The settings when nothing is saved: export is off.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = [
		'enabled' => false,
		'endpoint' => '',
		'credentialId' => '',
		'authScheme' => 'bearer',
		'username' => '',
		'organisation' => '',
		'sources' => self::SOURCES,
		'content' => 'metadata',
		'allowPrivateEndpoint' => false,
	];

	/**
	 * The notice that says the export is a copy.
	 *
	 * @var string
	 */
	public const COPY_NOTICE = 'The audit trail stays the record. Exported traces are a copy for your own tools.';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config holding `trace_export`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The stored settings, each key filled from the defaults when absent or malformed.
	 *
	 * @return array<string, mixed> The settings.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-1-admin-settings-and-the-connection-row
	 */
	public function get(): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '');
		$stored = json_decode($raw, true);
		if (is_array($stored) === false) {
			return self::DEFAULTS;
		}

		try {
			return $this->normalise(input: array_merge(self::DEFAULTS, $stored));
		} catch (InvalidArgumentException) {
			// A stored value that no longer validates is treated as unset: export stays off.
			return self::DEFAULTS;
		}

	}//end get()

	/**
	 * Validate and store new settings. Keys left out keep their stored value.
	 *
	 * @param array<string, mixed> $input The new values.
	 *
	 * @return array<string, mixed> The stored settings.
	 *
	 * @throws InvalidArgumentException When a value is invalid.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-1-admin-settings-and-the-connection-row
	 */
	public function save(array $input): array {
		$known = array_intersect_key($input, self::DEFAULTS);
		$settings = $this->normalise(input: array_merge($this->get(), $known));
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($settings));

		return $settings;

	}//end save()

	/**
	 * Whether a finished run from this source is to be exported.
	 *
	 * @param string $source `runs` or `chat`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-4-every-run-writer-and-the-chat-turn-enqueue
	 */
	public function exports(string $source): bool {
		$settings = $this->get();

		return ($settings['enabled'] === true && in_array($source, $settings['sources'], true) === true);

	}//end exports()

	/**
	 * The sentence shown next to the switch: where traces go and what leaves the server.
	 *
	 * @param array<string, mixed> $settings The settings.
	 *
	 * @return string The notice, or '' when no endpoint is set.
	 *
	 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#scenario-an-administrator-connects-langfuse
	 */
	public function destinationNotice(array $settings): string {
		$host = (string)(parse_url((string)$settings['endpoint'], PHP_URL_HOST) ?? '');
		if ($host === '') {
			return '';
		}

		if ($settings['content'] === 'metadata') {
			return 'Traces go to ' . $host . '. With metadata only, no prompt or answer text leaves this server.';
		}

		return 'Traces go to ' . $host . '. Prompts and answers are sent after the output filter and redaction.';

	}//end destinationNotice()

	/**
	 * Validate and normalise a full settings array.
	 *
	 * @param array<string, mixed> $input The merged settings.
	 *
	 * @return array<string, mixed> The normalised settings.
	 *
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	private function normalise(array $input): array {
		$settings = [
			'enabled' => ($input['enabled'] === true || $input['enabled'] === 'true' || $input['enabled'] === 1 || $input['enabled'] === '1'),
			'endpoint' => trim((string)$input['endpoint']),
			'credentialId' => trim((string)$input['credentialId']),
			'authScheme' => (string)$input['authScheme'],
			'username' => trim((string)$input['username']),
			'organisation' => trim((string)$input['organisation']),
			'sources' => $this->sources(value: $input['sources']),
			'content' => (string)$input['content'],
			'allowPrivateEndpoint' => ($input['allowPrivateEndpoint'] === true || $input['allowPrivateEndpoint'] === 'true'),
		];

		$this->assertValid(settings: $settings);

		return $settings;

	}//end normalise()

	/**
	 * Refuse a settings array that cannot be used.
	 *
	 * @param array<string, mixed> $settings The normalised settings.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	private function assertValid(array $settings): void {
		if (in_array($settings['content'], self::CONTENTS, true) === false) {
			throw new InvalidArgumentException('The content is metadata or redacted-text.');
		}

		if (in_array($settings['authScheme'], self::AUTH_SCHEMES, true) === false) {
			throw new InvalidArgumentException('The credential type is basic, bearer or apikey.');
		}

		$scheme = strtolower((string)(parse_url($settings['endpoint'], PHP_URL_SCHEME) ?? ''));
		if ($settings['endpoint'] !== '' && in_array($scheme, ['http', 'https'], true) === false) {
			throw new InvalidArgumentException('The endpoint is an http or https URL.');
		}

		if ($settings['enabled'] === false) {
			return;
		}

		if ($settings['endpoint'] === '' || $settings['credentialId'] === '') {
			throw new InvalidArgumentException('Trace export needs an endpoint and a credential before it can be switched on.');
		}

		if ($settings['sources'] === []) {
			throw new InvalidArgumentException('Choose at least one source: runs or chat.');
		}

		if ($settings['authScheme'] === 'basic' && $settings['username'] === '') {
			throw new InvalidArgumentException('A basic credential needs a username, such as the Langfuse public key.');
		}

	}//end assertValid()

	/**
	 * The known sources in a value, in their canonical order.
	 *
	 * @param mixed $value A list of sources, or a comma-separated string.
	 *
	 * @return list<string> The sources.
	 */
	private function sources(mixed $value): array {
		if (is_string($value) === true) {
			$value = explode(',', $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		$value = array_map(static fn ($item): string => trim((string)$item), $value);

		return array_values(array_intersect(self::SOURCES, $value));

	}//end sources()
}//end class
