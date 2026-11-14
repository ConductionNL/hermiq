<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\RemoteAgent
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\RemoteAgent;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The remote agents an admin registered: an agent on another server, reached
 * over A2A through an integriq source.
 *
 * Registering fetches the agent card once through the source and keeps it as
 * a snapshot; the remote server's credential stays on the integriq source
 * and is never copied here. A new remote agent is switched off. Callers check
 * that the caller is an admin; the record is read and written as the system.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */
class RemoteAgentService {

	/**
	 * The register the RemoteAgent schema lives in.
	 */
	public const REGISTER = 'hermiq';

	/**
	 * The RemoteAgent schema.
	 */
	public const SCHEMA = 'remoteagent';

	/**
	 * The fields an admin may change after registering.
	 */
	private const EDITABLE = ['name', 'description', 'enabled'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object service.
	 * @param A2aClient     $a2a           Fetches the agent card through integriq.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly A2aClient $a2a,
	) {
	}//end __construct()

	/**
	 * Register a remote agent: fetch its card through the source and keep it switched off.
	 *
	 * @param array<string, mixed> $input name, source and optionally description and slug.
	 *
	 * @return array<string, mixed> The stored remote agent with its id.
	 *
	 * @throws InvalidArgumentException        When the name or source is missing, or the slug is taken.
	 * @throws RemoteAgentUnavailableException When the card cannot be fetched through the source.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	public function register(array $input): array {
		$name   = trim((string) ($input['name'] ?? ''));
		$source = trim((string) ($input['source'] ?? ''));
		if ($name === '' || $source === '') {
			throw new InvalidArgumentException('A remote agent needs a name and an integriq source.');
		}

		$slug = self::slugOf(value: (string) ($input['slug'] ?? $name));
		if ($slug === '' || $this->findBySlug(slug: $slug) !== null) {
			throw new InvalidArgumentException(sprintf('The handle "%s" is taken or empty.', $slug));
		}

		$card = $this->a2a->fetchCard(sourceId: $source);

		$saved = $this->objectService->saveObject(
			object: [
				'name'        => $name,
				'slug'        => $slug,
				'description' => trim((string) ($input['description'] ?? ($card['description'] ?? ''))),
				'source'      => $source,
				'agentCard'   => self::cardSnapshot(card: $card),
				'enabled'     => false,
			],
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return self::toArray(object: $saved);
	}//end register()

	/**
	 * Every registered remote agent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	public function list(): array {
		$found = $this->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(
			array_map(
				static fn (ObjectEntity $object): array => self::toArray(object: $object),
				array_filter($found, static fn (mixed $object): bool => $object instanceof ObjectEntity)
			)
		);
	}//end list()

	/**
	 * Change a remote agent's name, description or switch; the source and card stay as registered.
	 *
	 * @param string               $id    The remote agent.
	 * @param array<string, mixed> $input The fields to change.
	 *
	 * @return array<string, mixed>|null The stored remote agent, or null when it does not exist.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	public function update(string $id, array $input): ?array {
		$object = $this->find(id: $id);
		if ($object === null) {
			return null;
		}

		$data = $object->getObject();
		foreach (self::EDITABLE as $field) {
			if (array_key_exists($field, $input) === true) {
				$data[$field] = $input[$field];
			}
		}

		$data['enabled'] = ($data['enabled'] ?? false) === true;

		$saved = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		return self::toArray(object: $saved);
	}//end update()

	/**
	 * Remove a remote agent.
	 *
	 * @param string $id The remote agent.
	 *
	 * @return bool False when it does not exist.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	public function delete(string $id): bool {
		$object = $this->find(id: $id);
		if ($object === null) {
			return false;
		}

		$this->objectService->deleteObject(
			uuid: (string) $object->getUuid(),
			register: self::REGISTER,
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return true;
	}//end delete()

	/**
	 * A remote agent by its handle, or null.
	 *
	 * @param string $slug The handle.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-asking-a-remote-agent-is-a-default-denied-external-tool-req-a2a-002
	 */
	public function findBySlug(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$found = $this->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['filters' => ['slug' => $slug], 'limit' => 10],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($found as $object) {
			if ($object instanceof ObjectEntity && (string) ($object->getObject()['slug'] ?? '') === $slug) {
				return self::toArray(object: $object);
			}
		}

		return null;
	}//end findBySlug()

	/**
	 * The card as kept: name, description, url and the skills' names and descriptions.
	 *
	 * @param array<string, mixed> $card The fetched card.
	 *
	 * @return array<string, mixed>
	 */
	private static function cardSnapshot(array $card): array {
		$skills = [];
		foreach ((array) ($card['skills'] ?? []) as $skill) {
			if (is_array($skill) === true) {
				$skills[] = [
					'id'          => (string) ($skill['id'] ?? ''),
					'name'        => (string) ($skill['name'] ?? ''),
					'description' => (string) ($skill['description'] ?? ''),
				];
			}
		}

		return [
			'name'        => (string) ($card['name'] ?? ''),
			'description' => (string) ($card['description'] ?? ''),
			'url'         => (string) ($card['url'] ?? ''),
			'version'     => (string) ($card['version'] ?? ''),
			'skills'      => $skills,
		];
	}//end cardSnapshot()

	/**
	 * A handle from a name: lower case, letters, digits and dashes.
	 *
	 * @param string $value The name or requested handle.
	 *
	 * @return string
	 */
	private static function slugOf(string $value): string {
		return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
	}//end slugOf()

	/**
	 * A remote agent by id, or null.
	 *
	 * @param string $id The remote agent.
	 *
	 * @return ObjectEntity|null
	 */
	private function find(string $id): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return $object;
	}//end find()

	/**
	 * The stored remote agent with its id.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private static function toArray(ObjectEntity $object): array {
		$data       = $object->getObject();
		$data['id'] = (string) $object->getUuid();

		return $data;
	}//end toArray()
}//end class
