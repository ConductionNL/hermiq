<?php

/**
 * Hermiq AssistantPromptLibrary.
 *
 * The prompts the assistant offers on a record, as objects rather than as code. A
 * gemeente that cannot read the prompt cannot defend the output: when a citizen asks
 * why the assistant summarised their bezwaar as it did, the answer is the prompt, and
 * it has to be retrievable by somebody who does not read code.
 *
 * Three rules hold the library together. The text that is sent is the text the object
 * carries, so nothing here templates, appends or "improves" a prompt on the way out.
 * The order is the administrator's, so the library is returned in it and never
 * re-sorted for display. And an administrator's edit outlives the app that shipped
 * the prompt: once a prompt has been edited, reordered, scoped or disabled, the
 * shipping app may no longer restore it.
 *
 * Disabling everything is one act, and re-enabling is per prompt. An incident
 * response that requires editing twelve rows is not a response; a bulk re-enable
 * would switch a prompt back on that had been disabled weeks earlier for an entirely
 * different reason.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-the-prompts-the-assistant-offers-must-be-administered-objects
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Assistant;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, administers and switches off the assistant's prompt library.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-the-prompts-the-assistant-offers-must-be-administered-objects
 */
class AssistantPromptLibrary {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for the assistant prompts.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'agentassistantprompt';

	/**
	 * The audit action a wholesale disable is recorded under. The first question
	 * after an incident is when the assistant was switched off, and that answer
	 * must not be somebody's memory.
	 *
	 * @var string
	 */
	public const DISABLE_ALL_ACTION = 'assistant-prompts-disabled';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister single read/write path.
	 * @param AuditTrailMapper $auditTrailMapper Records the wholesale disable.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every prompt, in the administrator's order.
	 *
	 * @return array<int, array<string, mixed>> The library.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-order-is-the-administrators
	 */
	public function all(): array {
		$rows = [];
		foreach ($this->objects() as $object) {
			$data = $object->getObject();
			$data['id'] = (string)($object->getUuid() ?? '');
			$rows[] = $data;
		}

		usort(
			$rows,
			static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
		);

		return $rows;
	}//end all()

	/**
	 * The prompts offered on one record type: enabled, in the administrator's
	 * order, and scoped. A prompt scoped to another type is not offered here, and
	 * a prompt with no scope is offered everywhere.
	 *
	 * @param string $usageScope The record type the surface is open on.
	 *
	 * @return array<int, array<string, mixed>> The prompts to offer.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-scope-decides-where-a-prompt-appears
	 */
	public function forScope(string $usageScope): array {
		$offered = [];
		foreach ($this->all() as $prompt) {
			if (($prompt['enabled'] ?? true) === false) {
				continue;
			}

			$scope = trim((string)($prompt['usageScope'] ?? ''));
			if ($scope !== '' && $scope !== $usageScope) {
				continue;
			}

			$offered[] = $prompt;
		}

		return $offered;
	}//end forScope()

	/**
	 * Create or update one prompt. Any write from an administrator marks the prompt
	 * administered, which is what stops a shipping app restoring it later.
	 *
	 * @param string|null $id The prompt uuid, or null to create.
	 * @param array<string, mixed> $payload The fields to write.
	 * @param bool $administered Whether this write is an administrator's.
	 *
	 * @return array<string, mixed> The stored prompt.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-the-text-that-will-be-sent-is-the-text-on-screen
	 */
	/**
	 * Copy the fields a payload may set onto the stored prompt.
	 *
	 * Only the declared fields move, and each keeps its own type: the four text
	 * fields are cast to string, `order` to int, `enabled` to a strict boolean.
	 * A field the payload does not mention is left exactly as it was, which is
	 * what makes a partial update partial.
	 *
	 * @param array<string, mixed> $data The prompt as stored.
	 * @param array<string, mixed> $payload The incoming fields.
	 *
	 * @return array<string, mixed> The prompt with the payload applied.
	 *
	 * @spec exclude extracted verbatim from upsert(); covered by its tests
	 */
	private function applyPayload(array $data, array $payload): array {
		foreach (['label', 'prompt', 'usageScope', 'source'] as $field) {
			if (array_key_exists($field, $payload) === true) {
				$data[$field] = (string)$payload[$field];
			}
		}

		if (array_key_exists('order', $payload) === true) {
			$data['order'] = (int)$payload['order'];
		}

		if (array_key_exists('enabled', $payload) === true) {
			$data['enabled'] = ($payload['enabled'] === true);
		}

		return $data;
	}//end applyPayload()

	/**
	 * Create or update a prompt an administrator is editing.
	 *
	 * Marks the prompt `administered`, which is what tells a shipping app's
	 * seed to leave it alone: the edited state lives here, and the app that
	 * shipped the original neither holds nor restores it.
	 *
	 * @param string|null $id The prompt id, or null to create.
	 * @param array<string, mixed> $payload The prompt fields.
	 *
	 * @return array<string, mixed> The stored prompt, with its id.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-a-consuming-app-may-ship-an-initial-library-and-must-not-hold-the-edited-state
	 */
	public function upsert(?string $id, array $payload): array {
		return $this->write(id: $id, payload: $payload, administered: true);
	}//end upsert()

	/**
	 * Write a prompt a consuming app SHIPPED, leaving it unadministered.
	 *
	 * The sibling of upsert(). The two exist instead of one method with a
	 * boolean flag, because the flag was the whole difference and a caller
	 * reading `administered: false` had to know what that implied. An
	 * administered prompt is one a human touched, and the shipping app neither
	 * holds nor restores that state.
	 *
	 * @param string|null $id The prompt id, or null to create.
	 * @param array<string, mixed> $payload The prompt fields.
	 *
	 * @return array<string, mixed> The stored prompt, with its id.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-a-consuming-app-may-ship-an-initial-library-and-must-not-hold-the-edited-state
	 */
	public function upsertShipped(?string $id, array $payload): array {
		return $this->write(id: $id, payload: $payload, administered: false);
	}//end upsertShipped()

	/**
	 * The shared write both upsert() and upsertShipped() perform.
	 *
	 * @param string|null $id The prompt id, or null to create.
	 * @param array<string, mixed> $payload The prompt fields.
	 * @param boolean $administered Whether to mark the prompt as touched by a human.
	 *
	 * @return array<string, mixed> The stored prompt, with its id.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec exclude the body of the former upsert(); covered by its tests
	 */
	private function write(?string $id, array $payload, bool $administered): array {
		$data = [];
		if ($id !== null && $id !== '') {
			$existing = $this->find(id: $id);
			if ($existing !== null) {
				$data = $existing->getObject();
			}
		}

		$data = $this->applyPayload(data: $data, payload: $payload);

		if ($administered === true) {
			$data['administered'] = true;
		}

		$uuid = $id;
		if ($id === '') {
			$uuid = null;
		}

		$stored = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: $uuid
		);

		$result = $stored->getObject();
		$result['id'] = (string)($stored->getUuid() ?? '');

		return $result;
	}//end write()

	/**
	 * Disable every prompt, wholesale or within one scope, in a single act, and
	 * record who did it and when.
	 *
	 * @param string|null $usageScope The scope to disable, or null for every prompt.
	 * @param string $actor The administrator performing the act.
	 *
	 * @return int How many prompts were switched off.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-everything-stops-in-one-act
	 */
	public function disableAll(?string $usageScope, string $actor): int {
		$disabled = 0;

		foreach ($this->objects() as $object) {
			$data = $object->getObject();

			if (($data['enabled'] ?? true) === false) {
				continue;
			}

			$scope = trim((string)($data['usageScope'] ?? ''));
			if ($usageScope !== null && $scope !== $usageScope) {
				continue;
			}

			$data['enabled'] = false;
			$data['administered'] = true;

			try {
				$this->objectService->saveObject(
					object: $data,
					register: self::REGISTER_SLUG,
					schema: self::SCHEMA_SLUG,
					uuid: (string)$object->getUuid()
				);
				$disabled++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Hermiq could not disable an assistant prompt: ' . $e->getMessage(),
					['exception' => $e, 'prompt' => (string)$object->getUuid()]
				);
			}
		}//end foreach

		$this->recordDisableAll(usageScope: $usageScope, actor: $actor, disabled: $disabled);

		return $disabled;
	}//end disableAll()

	/**
	 * Install a consuming app's initial library. A prompt an administrator has
	 * touched is left exactly as it is: the edited state lives here, and the
	 * shipping app neither holds nor restores it.
	 *
	 * @param string $appId The app shipping the prompts.
	 * @param array<int, array<string, mixed>> $prompts The prompts it ships.
	 *
	 * @return array{installed: int, skipped: int} What the seed did.
	 *
	 * @orphaned-write-capability exclude this is the CONSUMER-FACING half of
	 *   `a-consuming-app-may-ship-an-initial-library`, and the caller is by
	 *   definition another app: hermiq holds the library, a shipping app hands
	 *   it the prompts it ships. No app in the fleet ships prompts yet, so
	 *   there is no caller for gate-57 to find, and there would be none in a
	 *   single-repo checkout even after one existed — the gate says so itself
	 *   when it declines to judge a foundation repo (hydra#106). Deleting the
	 *   method would delete an implemented spec requirement; giving it an HTTP
	 *   route would invent a write endpoint the spec does not ask for. It
	 *   stays, unreachable and deliberate, until a consuming app calls it.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-a-consuming-app-may-ship-an-initial-library-and-must-not-hold-the-edited-state
	 */
	public function seed(string $appId, array $prompts): array {
		$existing = [];
		foreach ($this->all() as $prompt) {
			$existing[(string)($prompt['label'] ?? '')] = $prompt;
		}

		$installed = 0;
		$skipped = 0;

		foreach ($prompts as $prompt) {
			$label = (string)($prompt['label'] ?? '');
			if ($label === '') {
				continue;
			}

			$known = ($existing[$label] ?? null);
			if ($known !== null && ($known['administered'] ?? false) === true) {
				// An administrator has edited, reordered, scoped or disabled this
				// one. The shipping app does not get it back.
				$skipped++;
				continue;
			}

			$knownId = null;
			if ($known !== null) {
				$knownId = (string)$known['id'];
			}

			$this->upsertShipped(
				id: $knownId,
				payload: array_merge($prompt, ['source' => $appId])
			);
			$installed++;
		}//end foreach

		return ['installed' => $installed, 'skipped' => $skipped];
	}//end seed()

	/**
	 * One prompt by uuid.
	 *
	 * @param string $id The prompt uuid.
	 *
	 * @return ObjectEntity|null The prompt, or null.
	 *
	 * @spec exclude Pre-existing internal helper; no capability requirement governs it.
	 */
	public function find(string $id): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		try {
			return $this->objectService->find(
				id: $id,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG
			);
		} catch (Throwable $e) {
			return null;
		}

	}//end find()

	/**
	 * Write the record of a wholesale disable: the actor, the scope and the time.
	 *
	 * @param string|null $usageScope The scope that was disabled, or null for all.
	 * @param string $actor The administrator.
	 * @param int $disabled How many prompts were switched off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-the-switch-off-is-on-the-record
	 */
	private function recordDisableAll(?string $usageScope, string $actor, int $disabled): void {
		try {
			$marker = new ObjectEntity();
			$marker->setUuid('assistant-prompts');

			$this->auditTrailMapper->createAuditTrailEntry(
				object: $marker,
				action: self::DISABLE_ALL_ACTION,
				context: [
					'actor' => $actor,
					'usageScope' => ($usageScope ?? '*'),
					'disabled' => $disabled,
					'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not record the assistant-prompt disable-all: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end recordDisableAll()

	/**
	 * Every stored prompt object.
	 *
	 * @return array<int, ObjectEntity> The objects.
	 */
	private function objects(): array {
		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: ['limit' => 200]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read the assistant prompt library: ' . $e->getMessage(),
				['exception' => $e]
			);

			return [];
		}

		return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));
	}//end objects()
}//end class
