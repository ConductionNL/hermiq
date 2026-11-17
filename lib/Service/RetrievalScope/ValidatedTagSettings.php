<?php

/**
 * Hermiq Validated Tag Settings.
 *
 * Which Nextcloud system tag marks a document as validated (memory-retrieval-scope,
 * D2). Hermiq stores only the tag's id (`IAppConfig` `validated_tag_id`); the tag
 * itself, and who may assign it, live in Nextcloud, so the organisation decides
 * who validates in one place and people see the tag on the file in Files. Hermiq
 * keeps no validated status of its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\RetrievalScope
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\RetrievalScope;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ValidatedTagSettingsInterface;
use OCP\IAppConfig;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;

/**
 * The validated tag setting.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */
class ValidatedTagSettings implements ValidatedTagSettingsInterface {

	/**
	 * The app config key holding the tag id.
	 */
	public const CONFIG_KEY = 'validated_tag_id';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig        $appConfig Hermiq's app config.
	 * @param ISystemTagManager $tags      Nextcloud's system tags.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISystemTagManager $tags,
	) {
	}//end __construct()

	/**
	 * The validated tag's id, or null when none is chosen or it no longer exists.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function tagId(): ?string {
		return $this->tag()?->getId();
	}//end tagId()

	/**
	 * The setting as the admin page shows it.
	 *
	 * @return array{tagId: ?string, name: ?string, groups: list<string>}
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function all(): array {
		$tag = $this->tag();
		if ($tag === null) {
			return ['tagId' => null, 'name' => null, 'groups' => []];
		}

		return [
			'tagId' => $tag->getId(),
			'name' => $tag->getName(),
			'groups' => array_values(array_map('strval', $this->tags->getTagGroups($tag))),
		];
	}//end all()

	/**
	 * Name an existing system tag as the validated tag.
	 *
	 * @param string            $tagId  The tag.
	 * @param list<string>|null $groups The groups that may assign it, or null to leave them.
	 *
	 * @return array{tagId: ?string, name: ?string, groups: list<string>}
	 *
	 * @throws InvalidArgumentException When the tag does not exist.
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function choose(string $tagId, ?array $groups): array {
		$tag = $this->find(tagId: $tagId);
		if ($tag === null) {
			throw new InvalidArgumentException('No system tag with this id exists.');
		}

		return $this->store(tag: $tag, groups: $groups);
	}//end choose()

	/**
	 * Create a restricted tag assignable by the given groups, and name it.
	 *
	 * @param string       $name   The tag name.
	 * @param list<string> $groups The groups that may assign it.
	 *
	 * @return array{tagId: ?string, name: ?string, groups: list<string>}
	 *
	 * @throws InvalidArgumentException When the name is empty.
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function create(string $name, array $groups): array {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('The tag needs a name.');
		}

		// Restricted in Nextcloud's terms: everyone sees it, only the tag's groups assign it.
		try {
			$tag = $this->tags->createTag($name, true, false);
		} catch (TagAlreadyExistsException) {
			$tag = $this->tags->getTag($name, true, false);
		}

		return $this->store(tag: $tag, groups: $groups);
	}//end create()

	/**
	 * Store the tag as the validated tag, setting its groups when given.
	 *
	 * @param ISystemTag        $tag    The tag.
	 * @param list<string>|null $groups The groups, or null to leave them.
	 *
	 * @return array{tagId: ?string, name: ?string, groups: list<string>}
	 */
	private function store(ISystemTag $tag, ?array $groups): array {
		if ($groups !== null) {
			$this->tags->setTagGroups($tag, self::cleanGroups(groups: $groups));
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, $tag->getId());

		return $this->all();
	}//end store()

	/**
	 * The stored tag, or null when none is chosen or it no longer exists.
	 *
	 * @return ISystemTag|null
	 */
	private function tag(): ?ISystemTag {
		$id = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '');
		if ($id === '') {
			return null;
		}

		return $this->find(tagId: $id);
	}//end tag()

	/**
	 * A tag by id, or null when it does not exist.
	 *
	 * @param string $tagId The tag id.
	 *
	 * @return ISystemTag|null
	 */
	private function find(string $tagId): ?ISystemTag {
		if (trim($tagId) === '') {
			return null;
		}

		try {
			$found = $this->tags->getTagsByIds([$tagId]);
		} catch (TagNotFoundException | InvalidArgumentException) {
			return null;
		}

		return ($found[$tagId] ?? (array_values($found)[0] ?? null));
	}//end find()

	/**
	 * Non-empty, unique group ids.
	 *
	 * @param array<mixed> $groups The groups as given.
	 *
	 * @return list<string>
	 */
	private static function cleanGroups(array $groups): array {
		$clean = [];
		foreach ($groups as $group) {
			if (is_string($group) === true && trim($group) !== '') {
				$clean[] = trim($group);
			}
		}

		return array_values(array_unique($clean));
	}//end cleanGroups()
}//end class
