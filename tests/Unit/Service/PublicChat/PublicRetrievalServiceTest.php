<?php

/**
 * Public chat retrieval reads only what an anonymous caller may read, inside the channel's scope.
 *
 * Pins delivery-public-web-chat task 2 (REQ-PUBCHAT-002, REQ-PUBCHAT-004).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\PublicChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\PublicChat;

use OCA\Hermiq\Service\PublicChat\PublicRetrievalService;
use OCA\Hermiq\Service\PublicChat\QuestionTerms;
use OCA\Hermiq\Service\PublicChat\ScopeSchemas;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\PublicChat\PublicRetrievalService
 * @covers \OCA\Hermiq\Service\PublicChat\QuestionTerms
 * @covers \OCA\Hermiq\Service\PublicChat\ScopeSchemas
 */
class PublicRetrievalServiceTest extends TestCase {

	/**
	 * Whether the current read runs inside the anonymous scope.
	 *
	 * @var bool
	 */
	private bool $anonymous = false;

	/**
	 * The organisation the current read acts for.
	 *
	 * @var string|null
	 */
	private ?string $actingFor = null;

	/**
	 * The queries searched, with the scope each ran in.
	 *
	 * @var list<array{query: array, anonymous: bool, organisation: string|null}>
	 */
	private array $searches = [];

	/**
	 * A service over a register with a public page and a private note.
	 *
	 * The search double answers like OpenRegister: the private note is
	 * returned only to a read made outside the anonymous scope, i.e. as the
	 * signed-in session.
	 *
	 * @param bool $anonymousFails Whether the anonymous scope throws.
	 *
	 * @return PublicRetrievalService
	 */
	private function service(bool $anonymousFails=false): PublicRetrievalService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAsAnonymous')->willReturnCallback(
			function (callable $operation) use ($anonymousFails): mixed {
				if ($anonymousFails === true) {
					throw new RuntimeException('no incognito');
				}

				$this->anonymous = true;
				try {
					return $operation();
				} finally {
					$this->anonymous = false;
				}
			}
		);
		$objects->method('runForOrganisation')->willReturnCallback(
			function (string $organisation, callable $operation): mixed {
				$this->anonymous = true;
				$this->actingFor = $organisation;
				try {
					return $operation();
				} finally {
					$this->anonymous = false;
					$this->actingFor = null;
				}
			}
		);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) {
				$this->searches[] = ['query' => $query, 'anonymous' => $this->anonymous, 'organisation' => $this->actingFor];
				$rows = [
					['id' => 'page-1', 'title' => 'Bulky waste', 'summary' => '<p>Collected on  the first Monday.</p>', 'url' => 'https://www.example.nl/bulky-waste'],
				];
				if ($this->anonymous === false) {
					$rows[] = ['id' => 'note-9', 'title' => 'Internal note on bulky waste', 'summary' => 'Not public.'];
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://cloud.example.nl' . $path);

		return new PublicRetrievalService($objects, $urls, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A non-public object in the scope is never a source: the read runs as anonymous.
	 *
	 * @return void
	 */
	public function testANonPublicObjectIsNeverASource(): void {
		$sources = $this->service()->retrieve(
			scope: [['register' => 'publication', 'schema' => 'page']],
			question: 'When is bulky waste collected?'
		);

		self::assertSame(['page-1'], array_column($sources, 'id'));
		// One search per subject word, longest first; every one of them anonymous.
		self::assertSame(
			expected: ['collected', 'bulky', 'waste'],
			actual: array_map(callback: static fn (array $s): string => $s['query']['_search'], array: $this->searches)
		);
		self::assertSame(expected: [true, true, true], actual: array_column(array: $this->searches, column_key: 'anonymous'));
		self::assertSame(
			expected: ['_search' => 'collected', '_limit' => 5, '_register' => 'publication', '_schema' => 'page'],
			actual: $this->searches[0]['query']
		);
		self::assertSame(
			['id' => 'page-1', 'register' => 'publication', 'schema' => 'page', 'title' => 'Bulky waste', 'url' => 'https://www.example.nl/bulky-waste', 'excerpt' => 'Collected on the first Monday.'],
			$sources[0]
		);
	}//end testANonPublicObjectIsNeverASource()

	/**
	 * A channel of an organisation reads as anonymous acting for that organisation.
	 *
	 * @return void
	 */
	public function testAnOrganisationsChannelReadsActingForIt(): void {
		$this->service()->retrieve(scope: [['register' => 'publication', 'schema' => '']], question: 'waste', organisation: 'org-1');

		self::assertSame('org-1', $this->searches[0]['organisation']);
		self::assertTrue($this->searches[0]['anonymous']);
		self::assertNull($this->searches[0]['query']['_schema']);
		self::assertArrayNotHasKey(key: '_schemas', array: $this->searches[0]['query']);
	}//end testAnOrganisationsChannelReadsActingForIt()

	/**
	 * A visitor's sentence is searched by its subject words, because OpenRegister's search asks for every word.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function testAQuestionIsSearchedByItsSubjectWords(): void {
		$terms = new QuestionTerms();

		$cases = [
			'Wat staat er over de afvalkalender?'                         => ['afvalkalender'],
			'Wat zijn de openingstijden van het gemeentehuis op zaterdag?' => ['openingstijden', 'gemeentehuis', 'zaterdag'],
			'Kan ik een parkeervergunning aanvragen voor mijn woonboot?'  => ['parkeervergunning', 'aanvragen', 'woonboot'],
			'Één Straße!'                                                 => ['straße', 'één'],
			// At most MAX_TERMS words, longest first, each once.
			'ccc dddd eeeee ccc fff ggg'                                  => ['eeeee', 'dddd', 'ccc', 'fff'],
			// A question of only question words is searched as it was asked.
			' Wat is dat? '                                               => ['Wat is dat?'],
		];
		foreach ($cases as $question => $expected) {
			self::assertSame(expected: $expected, actual: $terms->termsOf(question: $question), message: $question);
		}
	}//end testAQuestionIsSearchedByItsSubjectWords()

	/**
	 * An object found by more of the question's words ranks first; each object is a source once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function testAnObjectFoundByMoreWordsRanksFirst(): void {
		$byTerm  = [
			'parkeervergunning' => [['id' => 'a', 'title' => 'Parking in general']],
			'woonboot'          => [['id' => 'b', 'title' => 'Houseboat parking permit']],
			'aanvragen'         => [['id' => 'b', 'title' => 'Houseboat parking permit']],
		];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('runAsAnonymous')->willReturnCallback(callback: fn (callable $op): mixed => $op());
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			callback: static fn (array $query): array => ['results' => ($byTerm[$query['_search']] ?? [])]
		);
		$service = new PublicRetrievalService(
			objectService: $objects,
			urls: $this->createMock(originalClassName: IURLGenerator::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$sources = $service->retrieve(
			scope: [['register' => 'pub', 'schema' => 'page']],
			question: 'Kan ik een parkeervergunning aanvragen voor mijn woonboot?'
		);

		self::assertSame(expected: ['b', 'a'], actual: array_column(array: $sources, column_key: 'id'));
	}//end testAnObjectFoundByMoreWordsRanksFirst()

	/**
	 * A whole-register scope searches the register's schemas; a register that cannot be read leaves them out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function testAWholeRegisterScopeSearchesItsSchemas(): void {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('runAsAnonymous')->willReturnCallback(callback: fn (callable $op): mixed => $op());
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			callback: function (array $query): array {
				$this->searches[] = ['query' => $query, 'anonymous' => true, 'organisation' => null];
				return ['results' => []];
			}
		);
		$register = $this->createMock(originalClassName: Register::class);
		$register->method('getSchemas')->willReturn(value: [91, 92]);
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			callback: static function (string|int $id) use ($register): Register {
				if ($id === 'pub') {
					return $register;
				}

				throw new RuntimeException(message: 'no such register');
			}
		);
		$logger  = $this->createMock(originalClassName: LoggerInterface::class);
		$service = new PublicRetrievalService(
			objectService: $objects,
			urls: $this->createMock(originalClassName: IURLGenerator::class),
			logger: $logger,
			scopeSchemas: new ScopeSchemas(registers: $registers, logger: $logger)
		);

		$service->retrieve(
			scope: [['register' => 'pub', 'schema' => ''], ['register' => 'gone', 'schema' => ''], ['register' => 'pub', 'schema' => 'page']],
			question: 'afvalkalender'
		);

		self::assertSame(
			expected: ['_search' => 'afvalkalender', '_limit' => 5, '_register' => 'pub', '_schema' => null, '_schemas' => [91, 92]],
			actual: $this->searches[0]['query']
		);
		self::assertArrayNotHasKey(key: '_schemas', array: $this->searches[1]['query']);
		self::assertArrayNotHasKey(key: '_schemas', array: $this->searches[2]['query']);
		self::assertSame(expected: 'page', actual: $this->searches[2]['query']['_schema']);
	}//end testAWholeRegisterScopeSearchesItsSchemas()

	/**
	 * When the anonymous scope cannot be opened nothing is read as the session instead.
	 *
	 * @return void
	 */
	public function testNoAnonymousScopeMeansNoSources(): void {
		$sources = $this->service(anonymousFails: true)->retrieve(scope: [['register' => 'publication', 'schema' => 'page']], question: 'waste');

		self::assertSame([], $sources);
		self::assertSame([], $this->searches);
	}//end testNoAnonymousScopeMeansNoSources()

	/**
	 * An empty question or scope reads nothing; a source without a link of its own links to its object.
	 *
	 * @return void
	 */
	public function testEmptyInputsReadNothingAndALinkIsBuilt(): void {
		self::assertSame([], $this->service()->retrieve(scope: [], question: 'waste'));
		self::assertSame([], $this->service()->retrieve(scope: [['register' => 'r', 'schema' => 's']], question: '   '));
		self::assertSame([], $this->searches);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAsAnonymous')->willReturnCallback(fn (callable $op): mixed => $op());
		$objects->method('searchObjectsPaginated')->willReturn(['results' => [['id' => 'x 1', 'name' => 'Opening hours'], ['title' => 'no id']]]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://cloud.example.nl' . $path);

		$sources = (new PublicRetrievalService($objects, $urls, $this->createMock(LoggerInterface::class)))
			->retrieve(scope: [['register' => 'pub', 'schema' => 'page']], question: 'hours', limit: 50);

		self::assertCount(1, $sources);
		self::assertSame('Opening hours', $sources[0]['title']);
		self::assertSame('https://cloud.example.nl/index.php/apps/openregister/api/objects/pub/page/x%201', $sources[0]['url']);
		self::assertSame('', $sources[0]['excerpt']);
	}//end testEmptyInputsReadNothingAndALinkIsBuilt()

	/**
	 * An app's scope only narrows the channel's: pairs outside it are dropped, a whole register is narrowed.
	 *
	 * @return void
	 */
	public function testARequestedScopeOnlyNarrows(): void {
		$channel = [['register' => 'portal', 'schema' => 'page'], ['register' => 'portal', 'schema' => 'glossary'], ['register' => 'pub', 'schema' => '']];

		self::assertSame(
			[['register' => 'portal', 'schema' => 'page'], ['register' => 'portal', 'schema' => 'glossary'], ['register' => 'pub', 'schema' => '']],
			PublicRetrievalService::intersectScope(channelScope: $channel, requested: null)
		);
		self::assertSame(
			[['register' => 'portal', 'schema' => 'page'], ['register' => 'pub', 'schema' => 'publication']],
			PublicRetrievalService::intersectScope(
				channelScope: $channel,
				requested: [['register' => 'portal', 'schema' => 'page'], ['register' => 'secret', 'schema' => 'note'], ['register' => 'pub', 'schema' => 'publication'], ['register' => 'portal', 'schema' => 'users']]
			)
		);
		self::assertSame(
			[['register' => 'portal', 'schema' => 'page'], ['register' => 'portal', 'schema' => 'glossary']],
			PublicRetrievalService::intersectScope(channelScope: $channel, requested: [['register' => 'portal']])
		);
		self::assertSame([], PublicRetrievalService::intersectScope(channelScope: $channel, requested: []));
		self::assertSame([], PublicRetrievalService::normaliseScope(scope: 'publication'));
		self::assertSame([['register' => 'a', 'schema' => '']], PublicRetrievalService::normaliseScope(scope: [['register' => ' a '], ['schema' => 'b'], 'x', ['register' => 'a', 'schema' => '']]));
	}//end testARequestedScopeOnlyNarrows()
}//end class
