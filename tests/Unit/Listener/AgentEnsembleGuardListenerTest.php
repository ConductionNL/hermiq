<?php

/**
 * Tests for AgentEnsembleGuardListener.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Listener
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
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Listener\AgentEnsembleGuardListener;
use OCA\Hermiq\Service\Agent\ReferenceModelSettings;
use OCA\Hermiq\Service\EffectiveModelPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Allowed, refused, unchanged, and the writes the guard leaves alone.
 */
#[CoversClass(AgentEnsembleGuardListener::class)]
#[UsesClass(ReferenceModelSettings::class)]
final class AgentEnsembleGuardListenerTest extends TestCase {

	/**
	 * The organisations the policy was asked about, in order.
	 *
	 * @var array<int, string>
	 */
	private array $askedFor = [];

	/**
	 * The guard over a policy allowing only ollama; alice's active organisation is org-1.
	 *
	 * @return AgentEnsembleGuardListener
	 */
	private function guard(): AgentEnsembleGuardListener {
		$agents = $this->createMock(originalClassName: AgentObjectMatcherInterface::class);
		$agents->method('isAgent')->willReturnCallback(static fn (ObjectEntity $o): bool => (string)$o->getSchema() === 'agent');

		$policies = $this->createMock(originalClassName: EffectiveModelPolicy::class);
		$policies->method('isAllowed')->willReturnCallback(
			function (string $organisation, string $provider, string $model): bool {
				$this->askedFor[] = $organisation;
				return $provider === 'ollama';
			}
		);
		$organisations = $this->createMock(originalClassName: OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturnCallback(
			static fn (string $userId): ?string => $userId === 'alice' ? 'org-1' : null
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AgentEnsembleGuardListener(
			agents: $agents,
			referenceModels: new ReferenceModelSettings(policies: $policies, organisations: $organisations),
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end guard()

	/**
	 * An object of a schema with this data.
	 *
	 * @param array<string, mixed> $data         The data.
	 * @param string               $organisation The object's organisation.
	 * @param string               $schema       The schema.
	 *
	 * @return ObjectEntity
	 */
	private static function object(array $data, string $organisation = '', string $schema = 'agent'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setObject($data);
		if ($organisation !== '') {
			$object->setOrganisation($organisation);
		}

		return $object;
	}//end object()

	/**
	 * An ensemble naming these providers.
	 *
	 * @param string ...$providers The providers.
	 *
	 * @return array<string, mixed>
	 */
	private static function ensemble(string ...$providers): array {
		return [
			'enabled' => true,
			'referenceModels' => array_map(static fn (string $p): array => ['provider' => $p, 'model' => 'm-' . $p], $providers),
		];
	}//end ensemble()

	/**
	 * A new agent with an allowed ensemble is written, checked against the writer's organisation.
	 *
	 * @return void
	 */
	public function testANewAgentWithAnAllowedEnsembleIsWritten(): void {
		$event = new ObjectCreatingEvent(self::object(data: ['name' => 'Beleidsadviseur', 'ensemble' => self::ensemble('ollama', 'ollama')]));

		$this->guard()->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['org-1', 'org-1'], $this->askedFor);
	}//end testANewAgentWithAnAllowedEnsembleIsWritten()

	/**
	 * A new agent naming a model outside the policy is refused before it is saved.
	 *
	 * @return void
	 */
	public function testANewAgentWithAModelOutsideThePolicyIsRefused(): void {
		$event = new ObjectCreatingEvent(self::object(data: ['name' => 'Beleidsadviseur', 'ensemble' => self::ensemble('ollama', 'openai')]));

		$this->guard()->handle($event);

		self::assertSame(['ensemble' => ReferenceModelSettings::NOT_ALLOWED], $event->getErrors());
		self::assertTrue($event->isPropagationStopped());
	}//end testANewAgentWithAModelOutsideThePolicyIsRefused()

	/**
	 * A changed ensemble on an update is checked against the agent's own organisation.
	 *
	 * @return void
	 */
	public function testAChangedEnsembleIsCheckedAgainstTheAgentsOrganisation(): void {
		$event = new ObjectUpdatingEvent(
			self::object(data: ['ensemble' => self::ensemble('openai', 'ollama')], organisation: 'org-9'),
			self::object(data: ['ensemble' => self::ensemble('ollama', 'ollama')], organisation: 'org-9')
		);

		$this->guard()->handle($event);

		self::assertSame(['ensemble' => ReferenceModelSettings::NOT_ALLOWED], $event->getErrors());
		self::assertSame(['org-9'], $this->askedFor);
	}//end testAChangedEnsembleIsCheckedAgainstTheAgentsOrganisation()

	/**
	 * An unchanged ensemble is not checked again, so a narrowed policy does not block other edits.
	 *
	 * @return void
	 */
	public function testAnUnchangedEnsembleIsNotCheckedAgain(): void {
		$event = new ObjectUpdatingEvent(
			self::object(data: ['name' => 'Nieuw', 'ensemble' => self::ensemble('openai', 'openai')], organisation: 'org-9'),
			self::object(data: ['name' => 'Oud', 'ensemble' => self::ensemble('openai', 'openai')], organisation: 'org-9')
		);

		$this->guard()->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertSame([], $this->askedFor);
	}//end testAnUnchangedEnsembleIsNotCheckedAgain()

	/**
	 * Writes without an ensemble, of other schemas, after an earlier refusal, or of other events are left alone.
	 *
	 * @return void
	 */
	public function testOtherWritesAreLeftAlone(): void {
		$guard = $this->guard();

		$noEnsemble = new ObjectCreatingEvent(self::object(data: ['name' => 'Gewoon']));
		$guard->handle($noEnsemble);

		$otherSchema = new ObjectCreatingEvent(self::object(data: ['ensemble' => self::ensemble('openai')], schema: 'skill'));
		$guard->handle($otherSchema);

		$stopped = new ObjectCreatingEvent(self::object(data: ['ensemble' => self::ensemble('openai')]));
		$stopped->stopPropagation();
		$guard->handle($stopped);

		$guard->handle(new ObjectCreatedEvent(self::object(data: ['ensemble' => self::ensemble('openai')])));

		self::assertSame([], $noEnsemble->getErrors());
		self::assertSame([], $otherSchema->getErrors());
		self::assertSame([], $stopped->getErrors());
		self::assertSame([], $this->askedFor);
	}//end testOtherWritesAreLeftAlone()
}//end class
