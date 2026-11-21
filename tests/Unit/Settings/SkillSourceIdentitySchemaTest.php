<?php

/**
 * Skill.sourceUrl and Skill.sourceUpdatedAt in the REAL register file
 * (skill-source-identity-schema, task 1).
 *
 * Pins the declaration the import needs: both properties exist on the Skill schema with the
 * declared type and format, neither is required, and a skill without an origin still validates.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/skill-source-identity-schema/specs/skills-marketplace/spec.md#requirement-an-installed-skill-records-its-canonical-origin
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Skill schema's source identity properties.
 *
 * @spec openspec/changes/skill-source-identity-schema/specs/skills-marketplace/spec.md#requirement-an-installed-skill-records-its-canonical-origin
 */
class SkillSourceIdentitySchemaTest extends TestCase {

	/**
	 * The Skill schema from lib/Settings/hermiq_register.json, decoded as arrays.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function skill(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		return $register['components']['schemas']['Skill'];
	}//end skill()

	/**
	 * The canonical origin is an optional string.
	 *
	 * @spec openspec/changes/skill-source-identity-schema/specs/skills-marketplace/spec.md#scenario-the-schema-accepts-a-canonical-source-url
	 *
	 * @return void
	 */
	public function testSourceUrlIsAnOptionalString(): void {
		$skill = $this->skill();

		$this->assertSame(expected: 'string', actual: $skill['properties']['sourceUrl']['type']);
		$this->assertArrayNotHasKey(key: 'format', array: $skill['properties']['sourceUrl']);
		$this->assertNotContains(needle: 'sourceUrl', haystack: $skill['required']);
	}//end testSourceUrlIsAnOptionalString()

	/**
	 * The refresh clock is an optional date-time, separate from publishedAt.
	 *
	 * @spec openspec/changes/skill-source-identity-schema/specs/skills-marketplace/spec.md#requirement-the-refresh-clock-is-separate-from-the-publish-clock
	 *
	 * @return void
	 */
	public function testSourceUpdatedAtIsAnOptionalDateTimeBesidePublishedAt(): void {
		$skill = $this->skill();

		$this->assertSame(expected: 'string', actual: $skill['properties']['sourceUpdatedAt']['type']);
		$this->assertSame(expected: 'date-time', actual: $skill['properties']['sourceUpdatedAt']['format']);
		$this->assertNotContains(needle: 'sourceUpdatedAt', haystack: $skill['required']);
		$this->assertArrayHasKey(key: 'publishedAt', array: $skill['properties']);
	}//end testSourceUpdatedAtIsAnOptionalDateTimeBesidePublishedAt()

	/**
	 * A skill without an origin is still valid: only `name` is required.
	 *
	 * @spec openspec/changes/skill-source-identity-schema/specs/skills-marketplace/spec.md#scenario-a-skill-without-an-origin-is-still-valid
	 *
	 * @return void
	 */
	public function testOnlyTheNameIsRequired(): void {
		$this->assertSame(expected: ['name'], actual: $this->skill()['required']);
	}//end testOnlyTheNameIsRequired()
}//end class
