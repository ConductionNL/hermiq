<?php

/**
 * Hermiq CustomToolService
 *
 * Stores the custom tools of an organisation and reads the ones an acting
 * user's agents may be offered.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CustomTool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CustomTool;

use InvalidArgumentException;
use OCA\Hermiq\Mcp\CustomToolDescriptors;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Custom tools in the hermiq register.
 *
 * Writes run without RBAC because the controller has already decided that the
 * caller administers the organisation; the object API only reads. Reads for
 * the catalogue run as the acting user, so each organisation sees its own.
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
class CustomToolService {

	/**
	 * The register.
	 */
	private const REGISTER = 'hermiq';

	/**
	 * The schema.
	 */
	public const SCHEMA = 'customtool';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister objects.
	 * @param LoggerInterface $logger        Logs a catalogue that could not be read.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One custom tool by uuid, whatever its organisation.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	public function findById(string $uuid): ?ObjectEntity {
		if ($uuid === '') {
			return null;
		}

		return $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
	}//end findById()

	/**
	 * The custom tools of one organisation.
	 *
	 * @param string $organisation The organisation uuid.
	 *
	 * @return list<array<string, mixed>> Each tool with its id and tool id.
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	public function listForOrganisation(string $organisation): array {
		$tools = [];
		foreach ($this->all(multitenancy: false) as $entity) {
			if ((string)($entity->getOrganisation() ?? '') === $organisation) {
				$tools[] = $this->shape(entity: $entity);
			}
		}

		return $tools;
	}//end listForOrganisation()

	/**
	 * Create or update a custom tool in an organisation.
	 *
	 * @param string               $organisation The organisation the tool belongs to.
	 * @param array<string, mixed> $payload      What the admin sent.
	 * @param string|null          $uuid         The tool to update, or null to create one.
	 *
	 * @return array<string, mixed> The stored tool.
	 *
	 * @throws InvalidArgumentException When the payload is invalid or the slug is taken.
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	public function save(string $organisation, array $payload, ?string $uuid=null): array {
		$data = CustomToolDefinition::normalise(payload: $payload);
		foreach ($this->all(multitenancy: false) as $entity) {
			if ((string)($entity->getObject()['slug'] ?? '') === $data['slug'] && (string)$entity->getUuid() !== (string)$uuid) {
				throw new InvalidArgumentException('slug: another custom tool already uses ' . $data['slug']);
			}
		}

		$data['@self'] = ['organisation' => $organisation];
		$saved         = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return $this->shape(entity: $saved);
	}//end save()

	/**
	 * The descriptors of the enabled custom tools the acting user can read.
	 *
	 * Never throws: a catalogue that cannot be read offers no custom tools.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
	 */
	public function descriptors(): array {
		try {
			$entities = $this->all(multitenancy: true);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq custom tools could not be read: ' . $e->getMessage());
			return [];
		}

		$descriptors = [];
		foreach ($entities as $entity) {
			$descriptor = CustomToolDescriptors::describe(tool: $entity->getObject());
			if ($descriptor !== null) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end descriptors()

	/**
	 * Every custom tool, as the acting user (multitenancy on) or instance-wide.
	 *
	 * @param bool $multitenancy Whether to read only the acting user's organisation.
	 *
	 * @return list<ObjectEntity>
	 */
	private function all(bool $multitenancy): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER)
			->setSchema(self::SCHEMA)
			->findAll(config: ['limit' => 500], _rbac: $multitenancy, _multitenancy: $multitenancy);

		return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));
	}//end all()

	/**
	 * A stored tool as the API answers it.
	 *
	 * @param ObjectEntity $entity The stored tool.
	 *
	 * @return array<string, mixed>
	 */
	private function shape(ObjectEntity $entity): array {
		$data = $entity->getObject();
		unset($data['@self']);
		$data['id']           = (string)$entity->getUuid();
		$data['organisation'] = (string)($entity->getOrganisation() ?? '');
		$data['toolId']       = CustomToolDescriptors::idOf(slug: (string)($data['slug'] ?? ''));

		return $data;
	}//end shape()
}//end class
