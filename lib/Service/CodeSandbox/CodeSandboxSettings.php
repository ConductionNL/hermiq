<?php

/**
 * Where model-written code runs: the instance's own sandbox ExApp or a hosted sandbox.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use InvalidArgumentException;
use OCP\IAppConfig;

/**
 * The instance-wide choice of code sandbox, kept in app configuration.
 *
 * The question "where does code run" is answered once for the instance, never
 * per agent. A stored value that is missing, unreadable or names an unknown
 * backend reads as the own sandbox, the choice that keeps code and data on
 * this server.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */
final class CodeSandboxSettings {

	/**
	 * The app configuration key holding the choice, as JSON.
	 */
	public const CONFIG_KEY = 'codeSandbox';

	/**
	 * Code runs in the instance's own sandbox ExApp.
	 */
	public const BACKEND_EXAPP = 'exapp';

	/**
	 * Code runs at a hosted sandbox reached through an integriq source.
	 */
	public const BACKEND_HOSTED = 'hosted';

	/**
	 * Every backend an admin may choose.
	 */
	public const BACKENDS = [self::BACKEND_EXAPP, self::BACKEND_HOSTED];

	/**
	 * The app id the configuration lives under.
	 */
	private const APP_ID = 'hermiq';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where the choice is kept.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The chosen backend: `exapp` unless an admin chose `hosted` with a source.
	 *
	 * @return string One of BACKENDS.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function backend(): string {
		$stored = $this->stored();
		if (($stored['backend'] ?? null) === self::BACKEND_HOSTED && $this->hostedSource() !== null) {
			return self::BACKEND_HOSTED;
		}

		return self::BACKEND_EXAPP;
	}//end backend()

	/**
	 * The integriq source a hosted run goes through, or null when none is chosen.
	 *
	 * @return string|null The source id.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function hostedSource(): ?string {
		$source = ($this->stored()['hostedSource'] ?? null);
		if (is_string($source) === false || trim($source) === '') {
			return null;
		}

		return trim($source);
	}//end hostedSource()

	/**
	 * How far a run's code and input reach: `self` in the own sandbox, `external` at a hosted one.
	 *
	 * @return string The reach, in the agent-capability-reach vocabulary.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function reach(): string {
		if ($this->backend() === self::BACKEND_HOSTED) {
			return 'external';
		}

		return 'self';
	}//end reach()

	/**
	 * Store the admin's choice.
	 *
	 * @param string      $backend      One of BACKENDS.
	 * @param string|null $hostedSource The integriq source; required for `hosted`, dropped for `exapp`.
	 *
	 * @return array{backend: string, hostedSource: string|null} The stored choice.
	 *
	 * @throws InvalidArgumentException When the backend is unknown, or hosted names no source.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function save(string $backend, ?string $hostedSource=null): array {
		if (in_array($backend, self::BACKENDS, true) === false) {
			throw new InvalidArgumentException('Choose where code runs: exapp or hosted.');
		}

		$source = null;
		if ($backend === self::BACKEND_HOSTED) {
			$source = trim((string)$hostedSource);
			if ($source === '') {
				throw new InvalidArgumentException('A hosted sandbox needs the integriq source it is reached through.');
			}
		}

		$choice = ['backend' => $backend, 'hostedSource' => $source];
		$this->appConfig->setValueString(self::APP_ID, self::CONFIG_KEY, (string)json_encode($choice));

		return $choice;
	}//end save()

	/**
	 * The choice as the admin section shows it.
	 *
	 * @return array{backend: string, hostedSource: string|null, reach: string}
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function toArray(): array {
		$backend = $this->backend();
		$source  = null;
		if ($backend === self::BACKEND_HOSTED) {
			$source = $this->hostedSource();
		}

		return [
			'backend' => $backend,
			'hostedSource' => $source,
			'reach' => $this->reach(),
		];
	}//end toArray()

	/**
	 * The stored JSON, or an empty array when absent or unreadable.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$decoded = json_decode($this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end stored()
}//end class
