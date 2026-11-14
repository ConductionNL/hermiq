<?php

/**
 * ComputerTargetService: the machines an agent may operate, and their test screenshot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ComputerUse
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ComputerUse;

use OCA\Hermiq\Support\FleetAppId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Keeps `ComputerTarget` objects and takes a test screenshot of one.
 *
 * Hermiq never connects to the machine: the screenshot is asked of the
 * remote-control service through integriq's CallService on the target's
 * source, and is returned to the admin who asked, never stored. Callers are
 * the admin-only settings routes, so the reads and writes run system-wide.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
class ComputerTargetService {

	/**
	 * The register and schema of a computer target.
	 */
	public const REGISTER = 'hermiq';

	public const SCHEMA = 'computertarget';

	/**
	 * The fields an admin may write.
	 *
	 * @var string[]
	 */
	private const FIELDS = ['name', 'description', 'source', 'os', 'applications', 'enabled'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService OpenRegister's object service.
	 * @param ContainerInterface $container     Resolves integriq's CallService by canonical name.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Every computer target.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	public function list(): array {
		$rows = $this->objectService
			->setRegister(self::REGISTER)
			->setSchema(self::SCHEMA)
			->findAll(config: [], _rbac: false, _multitenancy: false);

		return array_values(array_map(fn (mixed $row): array => $this->asArray(row: $row), array_filter((array)$rows, 'is_object')));
	}//end list()

	/**
	 * One computer target, or a 404.
	 *
	 * @param string $id The target's uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ComputerTargetRefusal 404 when there is none.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	public function get(string $id): array {
		$row = null;
		if ($id !== '') {
			try {
				$row = $this->objectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
			} catch (Throwable) {
				$row = null;
			}
		}

		if (is_object($row) === false) {
			throw new ComputerTargetRefusal(message: 'There is no computer target "' . $id . '".', status: 404);
		}

		return $this->asArray(row: $row);
	}//end get()

	/**
	 * Create a computer target, or update one when an id is given.
	 *
	 * @param array<string, mixed> $data The fields; others are left out.
	 * @param string|null          $id   The target to update, null to create.
	 *
	 * @return array<string, mixed> The stored target.
	 *
	 * @throws ComputerTargetRefusal 400 for a missing name or a source integriq does not hold, 404 for an unknown id.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	public function save(array $data, ?string $id=null): array {
		$target = array_intersect_key($data, array_flip(self::FIELDS));
		if ($id !== null) {
			$target = array_replace(array_intersect_key($this->get(id: $id), array_flip(self::FIELDS)), $target);
		}

		$target['name'] = trim((string)($target['name'] ?? ''));
		if ($target['name'] === '') {
			throw new ComputerTargetRefusal(message: 'A computer target needs a name.', status: 400);
		}

		$target['os']      = 'windows';
		$target['enabled'] = (($target['enabled'] ?? false) === true);
		$target['source']  = trim((string)($target['source'] ?? ''));
		if ($this->source(id: $target['source']) === null) {
			throw new ComputerTargetRefusal(message: 'Choose an integriq source that reaches the machine.', status: 400);
		}

		$saved = $this->objectService->saveObject(
			object: $target,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		return $this->asArray(row: $saved);
	}//end save()

	/**
	 * Remove a computer target.
	 *
	 * @param string $id The target's uuid.
	 *
	 * @return void
	 *
	 * @throws ComputerTargetRefusal 404 for an unknown id.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	public function delete(string $id): void {
		$this->get(id: $id);
		$this->objectService->deleteObject(uuid: $id, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
	}//end delete()

	/**
	 * Take a test screenshot of a target through its integriq source; nothing is stored.
	 *
	 * The remote-control service answers `POST /screenshot` with
	 * `{image: <base64 PNG>, width, height}` (design D3).
	 *
	 * @param string $id The target's uuid.
	 *
	 * @return array{image: string, mimeType: string, width: int, height: int}
	 *
	 * @throws ComputerTargetRefusal 404 unknown target, 409 its source is gone or integriq is absent, 502 the machine did not answer with a screenshot.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	public function screenshot(string $id): array {
		$target = $this->get(id: $id);
		$source = $this->source(id: (string)($target['source'] ?? ''));
		$caller = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: 'Service\CallService');
		if ($source === null || $caller === null) {
			throw new ComputerTargetRefusal(message: 'The machine cannot be reached: its integriq source is not available.', status: 409);
		}

		try {
			$response = $caller->call(
				source: $source,
				endpoint: '/screenshot',
				method: 'POST',
				config: ['body' => '{}', 'headers' => ['Content-Type' => 'application/json']]
			);
		} catch (Throwable $e) {
			throw new ComputerTargetRefusal(message: 'The machine could not be reached: ' . $e->getMessage(), status: 502);
		}

		$answer = $this->answer(response: $response);
		$image  = (string)($answer['image'] ?? '');
		if ($answer['statusCode'] >= 400 || $image === '' || base64_decode($image, true) === false) {
			throw new ComputerTargetRefusal(message: 'The machine did not answer with a screenshot.', status: 502);
		}

		return ['image' => $image, 'mimeType' => 'image/png', 'width' => (int)($answer['width'] ?? 0), 'height' => (int)($answer['height'] ?? 0)];
	}//end screenshot()

	/**
	 * The integriq source with this id, or null when there is none.
	 *
	 * @param string $id The source's uuid.
	 *
	 * @return object|null
	 */
	private function source(string $id): ?object {
		if ($id === '') {
			return null;
		}

		try {
			$source = $this->objectService->find(id: $id, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}

		if (is_object($source) === false) {
			return null;
		}

		return $source;
	}//end source()

	/**
	 * The decoded body of a CallService answer, with its status code.
	 *
	 * @param mixed $response The call log CallService returns.
	 *
	 * @return array<string, mixed> The body's fields, plus `statusCode`.
	 */
	private function answer(mixed $response): array {
		$raw = [];
		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$raw = (array)$response->getResponse();
		}

		if ($raw === [] && is_object($response) === true && method_exists($response, 'getObject') === true) {
			$raw = (array)(((array)$response->getObject())['response'] ?? []);
		}

		$body = json_decode((string)($raw['body'] ?? ''), true);
		if (is_array($body) === false) {
			$body = [];
		}

		return $body + ['statusCode' => (int)($raw['statusCode'] ?? 200)];
	}//end answer()

	/**
	 * A stored row as an array with its id.
	 *
	 * @param mixed $row The ObjectEntity.
	 *
	 * @return array<string, mixed>
	 */
	private function asArray(mixed $row): array {
		if ($row instanceof ObjectEntity) {
			return ['id' => (string)$row->getUuid()] + (array)$row->getObject();
		}

		return [];
	}//end asArray()
}//end class
