<?php

/**
 * Hermiq hosted code sandbox dispatch (tools-code-sandbox D5).
 *
 * Sends a run to a hosted sandbox through integriq, and nowhere else: the
 * admin's chosen integriq source (by uuid or slug) answers the same run
 * contract as the own sandbox, its key sits in the credential broker, and
 * integriq's CallService carries the request. Hermiq opens no HTTP client of
 * its own. Integriq is looked up by its canonical name, because its classes
 * ship under more than one namespace.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use OCA\Hermiq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Carries a run to a hosted sandbox through integriq's CallService.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */
class HostedSandboxDispatch {

	/**
	 * OpenRegister's object service, where integriq keeps its sources.
	 *
	 * @var string
	 */
	public const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The refusal when integriq or the chosen source is not there.
	 *
	 * @var string
	 */
	public const SOURCE_MISSING = 'The hosted code sandbox cannot be reached: integriq or its source is not available.';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves integriq's CallService and OpenRegister's ObjectService.
	 * @param LoggerInterface    $logger    Records transport failures, never code or output.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-2-dispatch-and-the-backend-choice
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether integriq and the source are there to take a run.
	 *
	 * @param string $sourceId The source's uuid or slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-2-dispatch-and-the-backend-choice
	 */
	public function isAvailable(string $sourceId): bool {
		return $this->callService() !== null && $this->source(sourceId: $sourceId) !== null;
	}//end isAvailable()

	/**
	 * Send one run to the source's `/run` and return the answer's body.
	 *
	 * @param string               $sourceId       The source's uuid or slug.
	 * @param array<string, mixed> $payload        The run contract's request.
	 * @param int                  $timeoutSeconds The transport timeout.
	 *
	 * @return string The response body.
	 *
	 * @throws CodeSandboxUnavailableException When integriq or the source is not there; nothing was sent.
	 * @throws RuntimeException               When the call failed or answered with a non-2xx status.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-2-dispatch-and-the-backend-choice
	 */
	public function run(string $sourceId, array $payload, int $timeoutSeconds): string {
		$callService = $this->callService();
		$source = $this->source(sourceId: $sourceId);
		if ($callService === null || $source === null) {
			throw new CodeSandboxUnavailableException(self::SOURCE_MISSING);
		}

		try {
			$callLog = $callService->call(
				source: $source,
				endpoint: CodeSandboxClient::RUN_ROUTE,
				method: 'POST',
				config: ['json' => $payload, 'timeout' => $timeoutSeconds],
				withholdBodies: true
			);
			$record = $callLog->getObject();
		} catch (Throwable $e) {
			$this->logger->warning('[HostedSandboxDispatch] the hosted sandbox could not be reached', ['reason' => $e->getMessage()]);
			throw new RuntimeException('The hosted code sandbox could not be reached.');
		}

		$status = (int)($record['response']['statusCode'] ?? 0);
		if ($status < 200 || $status > 299) {
			$this->logger->warning('[HostedSandboxDispatch] the hosted sandbox refused the run', ['status' => $status]);
			throw new RuntimeException('The code sandbox could not run the code (HTTP ' . $status . ').');
		}

		return (string)($record['response']['body'] ?? '');
	}//end run()

	/**
	 * Integriq's CallService, or null when integriq is not installed.
	 *
	 * @return object|null
	 */
	private function callService(): ?object {
		$service = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: 'Service\\CallService');
		if ($service === null || method_exists($service, 'call') === false) {
			return null;
		}

		return $service;
	}//end callService()

	/**
	 * The integriq source with this uuid or slug, or null.
	 *
	 * @param string $sourceId The uuid or slug.
	 *
	 * @return object|null The source entity.
	 */
	private function source(string $sourceId): ?object {
		if (trim($sourceId) === '') {
			return null;
		}

		try {
			$objects = $this->container->get(self::OBJECT_SERVICE)->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => 'source'], 'limit' => 500],
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[HostedSandboxDispatch] the integriq sources could not be read', ['reason' => $e->getMessage()]);
			return null;
		}

		foreach ($objects as $object) {
			if (is_object($object) === false || method_exists($object, 'getObject') === false) {
				continue;
			}

			$data = (array)$object->getObject();
			$uuid = '';
			if (method_exists($object, 'getUuid') === true) {
				$uuid = (string)$object->getUuid();
			}

			if ($uuid === $sourceId || (string)($data['slug'] ?? '') === $sourceId || (string)($data['id'] ?? '') === $sourceId) {
				return $object;
			}
		}

		return null;
	}//end source()
}//end class
