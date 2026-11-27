<?php

/**
 * Tests for resolving a schema slug inside one register.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair\Support;

use OCA\Hermiq\Repair\Support\RegisterScopedSchema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Exact schema ids by register, and the failure answers.
 *
 * @covers \OCA\Hermiq\Repair\Support\RegisterScopedSchema
 *
 * @spec openspec/changes/session-data-migration/specs/session-data-migration/spec.md
 */
class RegisterScopedSchemaTest extends TestCase {

	/**
	 * The first match inside the register wins; with no register schemas the first
	 * match wins; no match, and a failing lookup (logged), are null.
	 *
	 * @return void
	 */
	public function testTheSchemaIdByRegister(): void {
		$lines = [];
		$answers = [
			$this->resolver(registerSchemas: [12, 30], matches: [7, 30, 12], lines: $lines)->idFor(registerSlug: 'hermiq', schemaSlug: 'agent'),
			$this->resolver(registerSchemas: [], matches: [7, 30], lines: $lines)->idFor(registerSlug: 'hermiq', schemaSlug: 'agent'),
			$this->resolver(registerSchemas: null, matches: [7], lines: $lines)->idFor(registerSlug: 'hermiq', schemaSlug: 'agent'),
			$this->resolver(registerSchemas: [12], matches: [7], lines: $lines)->idFor(registerSlug: 'hermiq', schemaSlug: 'agent'),
			$this->resolver(registerSchemas: [12], matches: null, lines: $lines)->idFor(registerSlug: 'hermiq', schemaSlug: 'agent'),
		];

		$this->assertSame(expected: ['30', '7', '7', null, null], actual: $answers);
		$this->assertSame(expected: ['error [hermiq] could not resolve schema slug "agent": schema store down'], actual: $lines);

	}//end testTheSchemaIdByRegister()

	/**
	 * The resolver over stand-in mappers.
	 *
	 * @param array<int, int>|null $registerSchemas The register's schema ids, or null when the register lookup throws.
	 * @param array<int, int>|null $matches         The ids of the schemas with the slug, or null when that lookup throws.
	 * @param list<string>         $lines           The log lines, recorded.
	 *
	 * @return RegisterScopedSchema
	 */
	private function resolver(?array $registerSchemas, ?array $matches, array &$lines): RegisterScopedSchema {
		$registers = new class($registerSchemas) {

			/**
			 * Hold the register's schema ids.
			 *
			 * @param array<int, int>|null $schemas The ids, or null to throw.
			 */
			public function __construct(private readonly ?array $schemas) {
			}//end __construct()

			/**
			 * The register, or a throw.
			 *
			 * @param string|int $id            The slug.
			 * @param bool       $_rbac         Unused.
			 * @param bool       $_multitenancy Unused.
			 *
			 * @return object
			 */
			public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				if ($this->schemas === null) {
					throw new RuntimeException(message: 'register store down');
				}

				$schemas = $this->schemas;
				return new class($schemas) {

					/**
					 * Hold the ids.
					 *
					 * @param array<int, int> $schemas The ids.
					 */
					public function __construct(private readonly array $schemas) {
					}//end __construct()

					/**
					 * The ids.
					 *
					 * @return array<int, int>
					 */
					public function getSchemas(): array {
						return $this->schemas;
					}//end getSchemas()
				};
			}//end find()
		};
		$schemaMapper = new class($matches) {

			/**
			 * Hold the matching ids.
			 *
			 * @param array<int, int>|null $matches The ids, or null to throw.
			 */
			public function __construct(private readonly ?array $matches) {
			}//end __construct()

			/**
			 * The schemas with the slug, or a throw.
			 *
			 * @param string $slug          The slug.
			 * @param int    $limit         Unused.
			 * @param bool   $_rbac         Unused.
			 * @param bool   $_multitenancy Unused.
			 *
			 * @return array<int, object>
			 */
			public function findBySlug(string $slug, int $limit, bool $_rbac, bool $_multitenancy): array {
				if ($this->matches === null) {
					throw new RuntimeException(message: 'schema store down');
				}

				return array_map(
					static fn (int $id): object => new class($id) {

						/**
						 * Hold the id.
						 *
						 * @param int $id The id.
						 */
						public function __construct(private readonly int $id) {
						}//end __construct()

						/**
						 * The id.
						 *
						 * @return int
						 */
						public function getId(): int {
							return $this->id;
						}//end getId()
					},
					$this->matches
				);
			}//end findBySlug()
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => [
				'OCA\\OpenRegister\\Db\\RegisterMapper' => $registers,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
			][$id]
		);
		$logger = new class($lines) extends AbstractLogger {

			/**
			 * The recorded lines.
			 *
			 * @var list<string>
			 */
			private array $lines;

			/**
			 * Record into the given list.
			 *
			 * @param list<string> $lines The list.
			 */
			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}//end __construct()

			/**
			 * Record one line.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ' ' . $message;
			}//end log()
		};

		return new RegisterScopedSchema(container: $container, logger: $logger);

	}//end resolver()
}//end class
