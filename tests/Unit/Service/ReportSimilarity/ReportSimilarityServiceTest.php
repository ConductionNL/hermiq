<?php

/**
 * Hermiq ReportSimilarityService unit tests.
 *
 * Covers the scene the candidate is about: two hundred meldingen about one
 * street-wide power cut answering as one group, the middle band where a human's
 * attention belongs, the key that decides before the model does, the window that
 * keeps March and October apart, and the two things grouping must never touch — the
 * reports themselves and the confirmations of receipt their writers are owed.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ReportSimilarity
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ReportSimilarity;

use DateTimeImmutable;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilarityService;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCA\Hermiq\Service\ReportSimilarity\TermOverlapScorer;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ReportSimilarityService unit tests.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */
class ReportSimilarityServiceTest extends TestCase {

	/**
	 * The groups the ObjectService double holds, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $groups = [];

	/**
	 * The audit entries written during a test.
	 *
	 * @var array<int, array{action: string, context: array<string, mixed>}>
	 */
	private array $audits = [];

	/**
	 * An in-memory ObjectService double holding the groups.
	 *
	 * @return ObjectService The double.
	 */
	private function objectService(): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturnCallback(
			function (): array {
				$out = [];
				foreach ($this->groups as $uuid => $data) {
					$entity = new ObjectEntity();
					$entity->setUuid((string)$uuid);
					$entity->setObject($data);
					$out[] = $entity;
				}

				return $out;
			}
		);
		$service->method('find')->willReturnCallback(
			function (mixed $id, mixed ...$rest): ?ObjectEntity {
				if (isset($this->groups[(string)$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->groups[(string)$id]);
				return $entity;
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$key = ($uuid ?? ('group-' . (count($this->groups) + 1)));
				$this->groups[$key] = (array)$object;

				$entity = new ObjectEntity();
				$entity->setUuid($key);
				$entity->setObject($this->groups[$key]);
				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * An audit mapper recording what it was asked to write.
	 *
	 * @return AuditTrailMapper The double.
	 */
	private function auditTrailMapper(): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->audits[] = ['action' => $action, 'context' => $context];

				$entry = new AuditTrail();
				$entry->setAction($action);
				return $entry;
			}
		);

		return $mapper;
	}//end auditTrailMapper()

	/**
	 * Settings over a fixed stored configuration.
	 *
	 * @param array<string, mixed> $stored The stored settings.
	 *
	 * @return ReportSimilaritySettings The settings.
	 */
	private function settings(array $stored = []): ReportSimilaritySettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(($stored === [] ? '' : (string)json_encode($stored)));

		return new ReportSimilaritySettings($config);
	}//end settings()

	/**
	 * Build the service.
	 *
	 * @param array<string, mixed> $settings The stored settings.
	 *
	 * @return ReportSimilarityService The service.
	 */
	private function service(array $settings = []): ReportSimilarityService {
		$this->groups = [];
		$this->audits = [];

		return new ReportSimilarityService(
			$this->objectService(),
			$this->settings($settings),
			new TermOverlapScorer(),
			$this->auditTrailMapper(),
			new NullLogger()
		);
	}//end service()

	/**
	 * Two hundred reports of one street-wide power cut answer as one group with a
	 * count of two hundred.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-two-hundred-reports-of-one-power-cut-answer-as-one-group
	 */
	public function testTwoHundredReportsOfOnePowerCutAnswerAsOneGroup(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$answer = null;
		for ($i = 1; $i <= 200; $i++) {
			$answer = $service->evaluate(
				reportId: 'melding-' . $i,
				reportType: 'melding',
				text: 'Stroomstoring in de Kerkstraat, geen elektriciteit sinds vanmorgen',
				deterministicKey: '',
				now: $at
			);
		}

		$this->assertSame(200, $answer['count']);
		$this->assertCount(1, $this->groups);
		$this->assertFalse($answer['newGroup']);
	}//end testTwoHundredReportsOfOnePowerCutAnswerAsOneGroup()

	/**
	 * A clearly separate report starts its own group rather than being buried in
	 * the big one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-a-clearly-separate-report-stands-alone
	 */
	public function testAClearlySeparateReportStandsAlone(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring in de Kerkstraat, geen elektriciteit sinds vanmorgen',
			now: $at
		);

		$second = $service->evaluate(
			reportId: 'melding-2',
			reportType: 'melding',
			text: 'Losliggende stoeptegel voor het gemeentehuis, gevaarlijk voor rolstoelgebruikers',
			now: $at
		);

		$this->assertTrue($second['newGroup']);
		$this->assertCount(2, $this->groups);
	}//end testAClearlySeparateReportStandsAlone()

	/**
	 * A report scoring between the thresholds joins the group flagged uncertain,
	 * and appears beside it rather than counted silently into it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-a-near-duplicate-is-visible-not-buried
	 */
	public function testANearDuplicateIsVisibleNotBuried(): void {
		// A wide middle band, so a partial overlap lands in it rather than above or
		// below: the band is the point of the test, not the exact score.
		$service = $this->service(['upper' => 0.95, 'lower' => 0.2]);
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat geen elektriciteit vanmorgen',
			now: $at
		);

		$second = $service->evaluate(
			reportId: 'melding-2',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat lantaarnpalen uit en verkeerslichten defect',
			now: $at
		);

		$this->assertFalse($second['newGroup']);
		$this->assertTrue($second['uncertain']);
		$this->assertCount(1, $second['nearDuplicates']);
		$this->assertSame('melding-2', $second['nearDuplicates'][0]['reportId']);
	}//end testANearDuplicateIsVisibleNotBuried()

	/**
	 * A deterministic key decides where it can, and the membership records that it
	 * did rather than implying the model was consulted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-key-decides-where-it-can
	 */
	public function testTheKeyDecidesWhereItCan(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring',
			deterministicKey: 'kerkstraat|stroom|2026-03-01T10',
			now: $at
		);

		$second = $service->evaluate(
			reportId: 'melding-2',
			// Entirely different wording: only the key can have matched these.
			reportType: 'melding',
			text: 'Alles zit zonder licht hier in de buurt',
			deterministicKey: 'kerkstraat|stroom|2026-03-01T10',
			now: $at
		);

		$this->assertFalse($second['newGroup']);
		$member = end($second['members']);
		$this->assertSame(ReportSimilarityService::DECIDED_BY_KEY, $member['decidedBy']);
		$this->assertFalse($member['uncertain']);
	}//end testTheKeyDecidesWhereItCan()

	/**
	 * The model covers what the key misses: two reports of one outage filed with
	 * different keys still group on their text, and the membership records that the
	 * model decided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-model-covers-what-the-key-misses
	 */
	public function testTheModelCoversWhatTheKeyMisses(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat geen elektriciteit vanmorgen',
			deterministicKey: 'kerkstraat|stroom',
			now: $at
		);

		$second = $service->evaluate(
			reportId: 'melding-2',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat geen elektriciteit vanmorgen',
			deterministicKey: 'molenweg|stroom',
			now: $at
		);

		$member = end($second['members']);
		$this->assertSame(ReportSimilarityService::DECIDED_BY_MODEL, $member['decidedBy']);
		$this->assertSame(TermOverlapScorer::NAME, $member['scorer']);
	}//end testTheModelCoversWhatTheKeyMisses()

	/**
	 * An old report is out of scope. A streetlight in March and one in October are
	 * not one event, however alike the text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-an-old-report-is-out-of-scope
	 */
	public function testAnOldReportIsOutOfScope(): void {
		$service = $this->service(['windows' => ['melding' => 1440]]);

		$service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Lantaarnpaal kapot op de hoek van de Molenweg',
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$later = $service->evaluate(
			reportId: 'melding-2',
			reportType: 'melding',
			text: 'Lantaarnpaal kapot op de hoek van de Molenweg',
			now: new DateTimeImmutable('2026-10-01T10:00:00+00:00')
		);

		$this->assertTrue($later['newGroup']);
		$this->assertCount(2, $this->groups);
	}//end testAnOldReportIsOutOfScope()

	/**
	 * The window in force is part of the record, so a handler reads what was
	 * compared rather than what is configured today.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-window-is-part-of-the-record
	 */
	public function testTheWindowIsPartOfTheRecord(): void {
		$service = $this->service(['windows' => ['melding' => 60]]);

		$answer = $service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat',
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$this->assertSame(60, $answer['windowMinutes']);
	}//end testTheWindowIsPartOfTheRecord()

	/**
	 * Pulling one report out of a group costs nothing: it stands alone again and
	 * the count moves from two hundred to a hundred and ninety-nine.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-pulling-one-report-out-costs-nothing
	 */
	public function testPullingOneReportOutCostsNothing(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$answer = null;
		for ($i = 1; $i <= 200; $i++) {
			$answer = $service->evaluate(
				reportId: 'melding-' . $i,
				reportType: 'melding',
				text: 'Stroomstoring in de Kerkstraat, geen elektriciteit sinds vanmorgen',
				now: $at
			);
		}

		$after = $service->removeMember(groupId: $answer['groupId'], reportId: 'melding-7');

		$this->assertSame(199, $after['count']);

		$remaining = array_map(static fn (array $m): string => (string)$m['reportId'], $after['members']);
		$this->assertNotContains('melding-7', $remaining);
	}//end testPullingOneReportOutCostsNothing()

	/**
	 * No report is destroyed by grouping: a group holds references and scores, and
	 * never a copy of a report's content or its reporter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-no-report-is-destroyed-by-grouping
	 */
	public function testAGroupHoldsReferencesRatherThanCopies(): void {
		$service = $this->service();

		$text = 'Stroomstoring Kerkstraat, melder Mevrouw De Vries, telefoon 0612345678';

		$answer = $service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: $text,
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$stored = (string)json_encode($this->groups[$answer['groupId']]);

		// The terms it matched on are kept, as the reasons require. The report
		// itself, its reporter and their telephone number are not hermiq's to hold.
		$this->assertStringNotContainsString('0612345678', $stored);
		$this->assertStringNotContainsString($text, $stored);
		$this->assertSame('melding-1', $answer['members'][0]['reportId']);
	}//end testAGroupHoldsReferencesRatherThanCopies()

	/**
	 * Grouping carries no acknowledgement effect. Awb 4:3a owes every electronic
	 * request a confirmation of receipt, and a handler who sees one item where two
	 * hundred people wrote will find it natural that one confirmation went out.
	 * Which is why the answer says nothing about acknowledgement at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-grouping-carries-no-acknowledgement-effect
	 */
	public function testGroupingCarriesNoAcknowledgementEffect(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$reports = [];
		for ($i = 1; $i <= 200; $i++) {
			$reports[] = 'melding-' . $i;
			$answer = $service->evaluate(
				reportId: 'melding-' . $i,
				reportType: 'melding',
				text: 'Stroomstoring in de Kerkstraat, geen elektriciteit sinds vanmorgen',
				now: $at
			);
		}

		$flattened = (string)json_encode($answer);

		foreach (['acknowledg', 'ontvangstbevestiging', 'confirmation', 'suppress'] as $forbidden) {
			$this->assertStringNotContainsStringIgnoringCase(
				$forbidden,
				$flattened,
				'The grouping answer must carry no instruction about acknowledgement.'
			);
		}

		// Two hundred reports, two hundred owed confirmations: the group changed the
		// handler's view and not one reporter's entitlement.
		$this->assertCount(200, $answer['members']);
		$this->assertCount(200, array_unique($reports));
	}//end testGroupingCarriesNoAcknowledgementEffect()

	/**
	 * Why these are one thing is answerable: the terms, the window, the score and
	 * the deciding method are all on the group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
	 */
	public function testWhyTheseAreOneThingIsAnswerable(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$service->evaluate(
			reportId: 'melding-1',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat geen elektriciteit vanmorgen',
			now: $at
		);

		$answer = $service->evaluate(
			reportId: 'melding-2',
			reportType: 'melding',
			text: 'Stroomstoring Kerkstraat geen elektriciteit vanmorgen',
			now: $at
		);

		$group = $service->group(groupId: $answer['groupId']);

		$this->assertContains('stroomstoring', $group['terms']);
		$this->assertContains('kerkstraat', $group['terms']);
		$this->assertGreaterThan(0, $group['windowMinutes']);

		$member = end($group['members']);
		$this->assertArrayHasKey('score', $member);
		$this->assertArrayHasKey('decidedBy', $member);
		$this->assertArrayHasKey('scorer', $member);
	}//end testWhyTheseAreOneThingIsAnswerable()

	/**
	 * Each judgement is on the audit trail, like any other model output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-each-judgement-is-on-the-audit-trail
	 */
	public function testEachJudgementIsOnTheAuditTrail(): void {
		$service = $this->service();
		$at = new DateTimeImmutable('2026-03-01T10:00:00+00:00');

		$service->evaluate(reportId: 'melding-1', reportType: 'melding', text: 'Stroomstoring Kerkstraat', now: $at);
		$service->evaluate(reportId: 'melding-2', reportType: 'melding', text: 'Stroomstoring Kerkstraat', now: $at);

		$this->assertCount(2, $this->audits);
		$this->assertSame(ReportSimilarityService::AUDIT_ACTION, $this->audits[0]['action']);
		$this->assertSame('melding-2', $this->audits[1]['context']['reportId']);
		$this->assertArrayHasKey('score', $this->audits[1]['context']);
		$this->assertArrayHasKey('windowMinutes', $this->audits[1]['context']);
	}//end testEachJudgementIsOnTheAuditTrail()
}//end class
