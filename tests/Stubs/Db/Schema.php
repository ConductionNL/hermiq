<?php

/**
 * Test stub for OpenRegister Schema.
 *
 * Stands in for OCA\OpenRegister\Db\Schema when OpenRegister is not installed
 * (standalone CI). Mirrors only the accessors Hermiq reads: `getConfiguration()`
 * (agent-object-leaf's `x-openregister-agent-context` allowlist) and
 * `getProperties()` (hydra-console-agent-leaves' closed label vocabulary, read off
 * a hydra schema's state enum). The real entity ships with OpenRegister
 * (lib/Db/Schema.php).
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal Schema stub for standalone unit runs.
 */
class Schema {

	/**
	 * Configuration bag (holds `x-openregister-agent-context`, `x-openregister-flows`, ...).
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $configuration = null;

	/**
	 * JSON-schema property definitions, keyed by property name.
	 *
	 * @var array<string,mixed>
	 */
	private array $properties = [];

	/**
	 * Get the schema configuration.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getConfiguration(): ?array {
		return $this->configuration;
	}//end getConfiguration()

	/**
	 * Set the schema configuration.
	 *
	 * @param array<string,mixed>|null $configuration The configuration.
	 *
	 * @return void
	 */
	public function setConfiguration(?array $configuration): void {
		$this->configuration = $configuration;
	}//end setConfiguration()

	/**
	 * The schema's JSON-schema property definitions.
	 *
	 * @return array<string,mixed>
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()

	/**
	 * Set the schema's property definitions.
	 *
	 * @param array<string,mixed> $properties The property definitions.
	 *
	 * @return void
	 */
	public function setProperties(array $properties): void {
		$this->properties = $properties;
	}//end setProperties()

	/**
	 * The schema id.
	 *
	 * @var int|null
	 */
	private ?int $id = null;

	/**
	 * The schema slug.
	 *
	 * @var string|null
	 */
	private ?string $slug = null;

	/**
	 * Get the id.
	 *
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}//end getId()

	/**
	 * Set the id.
	 *
	 * @param int|null $id The id.
	 *
	 * @return void
	 */
	public function setId(?int $id): void {
		$this->id = $id;
	}//end setId()

	/**
	 * Get the slug.
	 *
	 * @return string|null
	 */
	public function getSlug(): ?string {
		return $this->slug;
	}//end getSlug()

	/**
	 * Set the slug.
	 *
	 * @param string|null $slug The slug.
	 *
	 * @return void
	 */
	public function setSlug(?string $slug): void {
		$this->slug = $slug;
	}//end setSlug()
}//end class
