<?php

/**
 * Golden tests for RedactionOutcomeReader::outcomeFor.
 *
 * Pins the exact outcome for every branch: filinq absent, no reference, a read that
 * throws (with its warning), no matching link, a non-entity and a link for another
 * file skipped, each redacted status, a status that is not a redaction, and the
 * trimming and lower-casing of the reference and status.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-a-feature-may-require-that-a-document-was-redacted-before-it-is-read
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\RedactionOutcomeReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the redaction outcome read.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-a-feature-may-require-that-a-document-was-redacted-before-it-is-read
 */
class RedactionOutcomeReaderGoldenTest extends TestCase {

	/**
	 * What was read and logged.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * Read the outcome for a reference over the given links.
	 *
	 * @param string $reference The document reference.
	 * @param array<int, mixed>|RuntimeException $links The stored links, or what the read throws.
	 * @param bool $installed Whether filinq is installed.
	 *
	 * @return array
	 */
	private function outcome(string $reference, array|RuntimeException $links, bool $installed = true): array {
		$this->log = [];
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'filinq');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($links): array {
				$this->log[] = $config;
				if ($links instanceof RuntimeException) {
					throw $links;
				}

				return array_map(
					static function ($link) {
						if (is_array($link) === false) {
							return $link;
						}

						$entity = new ObjectEntity();
						$entity->setObject($link);
						return $entity;
					},
					$links
				);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (string $m) => $this->log[] = 'warning ' . $m);

		return (new RedactionOutcomeReader($apps, $objects, $logger))->outcomeFor($reference);
	}//end outcome()

	/**
	 * Every branch, with its exact outcome.
	 *
	 * @return void
	 */
	public function testEveryOutcome(): void {
		$unavailable = static fn (string $reason): array => ['available' => false, 'redacted' => false, 'status' => 'unavailable', 'redactedAt' => '', 'reason' => $reason];

		$this->assertSame($unavailable('the redaction client (filinq) is not installed on this instance'), $this->outcome('42', [], false));
		$this->assertSame([], $this->log);
		$this->assertSame($unavailable('no document reference was given'), $this->outcome('  ', []));
		$this->assertSame($unavailable('the redaction outcome could not be read from filinq'), $this->outcome('42', new RuntimeException('down')));
		$this->assertSame("warning Hermiq could not read filinq's redaction outcome: down", $this->log[1]);

		$none = ['available' => true, 'redacted' => false, 'status' => 'none', 'redactedAt' => '', 'reason' => 'no redaction has been recorded for this document'];
		$this->assertSame($none, $this->outcome('42', []));
		$this->assertSame([['filters' => ['sourceFileId' => '42'], 'limit' => 50]], $this->log);
		$this->assertSame($none, $this->outcome('42', ['junk', ['sourceFileId' => '43', 'status' => 'done']]));

		foreach (['completed', 'success', 'anonymized', 'anonymised', 'done'] as $status) {
			$this->assertSame(
				['available' => true, 'redacted' => true, 'status' => $status, 'redactedAt' => '2026-01-02', 'reason' => ''],
				$this->outcome(' 42 ', [['sourceFileId' => 42, 'status' => ' ' . strtoupper($status) . ' ', 'anonymizedAt' => '2026-01-02']])
			);
		}

		$this->assertSame(
			['available' => true, 'redacted' => false, 'status' => 'running', 'redactedAt' => '', 'reason' => ''],
			$this->outcome('42', [['sourceFileId' => '42', 'status' => 'running'], ['sourceFileId' => '42', 'status' => 'done']])
		);
		$this->assertSame([['filters' => ['sourceFileId' => ' 42 '], 'limit' => 50]], $this->outcome(' 42 ', []) !== [] ? $this->log : []);
	}//end testEveryOutcome()
}//end class
