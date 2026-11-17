<?php

/**
 * Unit tests for ValidatedTagSettings (memory-retrieval-scope, task 2).
 *
 * The validated tag is a Nextcloud system tag: hermiq stores its id only,
 * creates it restricted, and leaves who may assign it to the tag's groups.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\RetrievalScope
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

namespace OCA\Hermiq\Tests\Unit\Service\RetrievalScope;

use InvalidArgumentException;
use OCA\Hermiq\Service\RetrievalScope\ValidatedTagSettings;
use OCP\IAppConfig;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Choosing and creating the validated tag.
 */
class ValidatedTagSettingsTest extends TestCase {

	/**
	 * Stored app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Groups set per tag id.
	 *
	 * @var array<string, list<string>>
	 */
	private array $tagGroups = [];

	/**
	 * Every createTag call: [name, visible, assignable].
	 *
	 * @var list<array{0: string, 1: bool, 2: bool}>
	 */
	private array $created = [];

	/**
	 * A tag double.
	 *
	 * @param string $id   The id.
	 * @param string $name The name.
	 *
	 * @return ISystemTag
	 */
	private function tag(string $id, string $name): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		$tag->method('getName')->willReturn($name);
		return $tag;
	}//end tag()

	/**
	 * The settings over a tag manager that knows tag 7 (Gevalideerd) and, when $exists, a restricted Validated tag 9.
	 *
	 * @param bool $exists Whether "Validated" exists already.
	 *
	 * @return ValidatedTagSettings
	 */
	private function settings(bool $exists = false): ValidatedTagSettings {
		$known = ['7' => $this->tag('7', 'Gevalideerd')];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getTagsByIds')->willReturnCallback(
			function ($ids) use (&$known): array {
				$found = [];
				foreach ((array)$ids as $id) {
					if (isset($known[$id]) === false) {
						throw new TagNotFoundException('missing', 0, null, [$id]);
					}

					$found[$id] = $known[$id];
				}

				return $found;
			}
		);
		$tags->method('createTag')->willReturnCallback(
			function (string $name, bool $visible, bool $assignable) use (&$known, $exists): ISystemTag {
				$this->created[] = [$name, $visible, $assignable];
				if ($exists === true) {
					throw new TagAlreadyExistsException('exists');
				}

				$known['8'] = $this->tag('8', $name);
				return $known['8'];
			}
		);
		$tags->method('getTag')->willReturnCallback(
			function (string $name, bool $visible, bool $assignable) use (&$known): ISystemTag {
				$this->assertSame([true, false], [$visible, $assignable]);
				$known['9'] = $this->tag('9', $name);
				return $known['9'];
			}
		);
		$tags->method('setTagGroups')->willReturnCallback(
			function (ISystemTag $tag, array $groups): void {
				$this->tagGroups[$tag->getId()] = $groups;
			}
		);
		$tags->method('getTagGroups')->willReturnCallback(fn (ISystemTag $tag): array => ($this->tagGroups[$tag->getId()] ?? []));

		return new ValidatedTagSettings($config, $tags);
	}//end settings()

	/**
	 * Nothing chosen: no tag.
	 *
	 * @return void
	 */
	public function testNothingChosenIsNoTag(): void {
		$settings = $this->settings();

		$this->assertNull($settings->tagId());
		$this->assertSame(['tagId' => null, 'name' => null, 'groups' => []], $settings->all());
	}//end testNothingChosenIsNoTag()

	/**
	 * Creating makes a restricted tag (visible, not freely assignable), sets its groups and stores its id.
	 *
	 * @return void
	 */
	public function testCreateMakesARestrictedTagForTheGroups(): void {
		$settings = $this->settings();

		$result = $settings->create(name: ' Validated ', groups: ['informatiebeheer', '', 'informatiebeheer']);

		$this->assertSame([['Validated', true, false]], $this->created);
		$this->assertSame(['8' => ['informatiebeheer']], $this->tagGroups);
		$this->assertSame('8', $this->config[ValidatedTagSettings::CONFIG_KEY]);
		$this->assertSame(['tagId' => '8', 'name' => 'Validated', 'groups' => ['informatiebeheer']], $result);
		$this->assertSame('8', $settings->tagId());
	}//end testCreateMakesARestrictedTagForTheGroups()

	/**
	 * An existing restricted tag of that name is taken, not duplicated; an empty name is refused.
	 *
	 * @return void
	 */
	public function testCreateTakesAnExistingTagAndRefusesAnEmptyName(): void {
		$settings = $this->settings(exists: true);

		$this->assertSame('9', $settings->create(name: 'Validated', groups: [])['tagId']);

		$this->expectException(InvalidArgumentException::class);
		$settings->create(name: '  ', groups: []);
	}//end testCreateTakesAnExistingTagAndRefusesAnEmptyName()

	/**
	 * Choosing names an existing tag and leaves its groups alone when none are given; an unknown tag is refused.
	 *
	 * @return void
	 */
	public function testChooseNamesAnExistingTag(): void {
		$settings = $this->settings();
		$this->tagGroups['7'] = ['kwaliteit'];

		$this->assertSame(['tagId' => '7', 'name' => 'Gevalideerd', 'groups' => ['kwaliteit']], $settings->choose(tagId: '7', groups: null));
		$this->assertSame(['informatiebeheer'], $settings->choose(tagId: '7', groups: ['informatiebeheer'])['groups']);

		try {
			$settings->choose(tagId: '404', groups: null);
			$this->fail('An unknown tag must be refused.');
		} catch (InvalidArgumentException) {
			$this->assertSame('7', $this->config[ValidatedTagSettings::CONFIG_KEY]);
		}
	}//end testChooseNamesAnExistingTag()

	/**
	 * A chosen tag that was deleted in Nextcloud reads as none.
	 *
	 * @return void
	 */
	public function testADeletedTagReadsAsNone(): void {
		$this->config[ValidatedTagSettings::CONFIG_KEY] = '55';

		$this->assertNull($this->settings()->tagId());
	}//end testADeletedTagReadsAsNone()
}//end class
