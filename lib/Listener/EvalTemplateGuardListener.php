<?php

/**
 * Hermiq EvalTemplateGuardListener.
 *
 * Keeps a seeded eval dataset template read-only (reuse-scheduled-bias-evals,
 * REQ-BIASEVAL-002): an update of an `evaldataset` object in the hermiq register
 * whose stored version carries `template: true` is refused before the write, on
 * every write path. The template is changed by shipping a new one, and an owner
 * who wants to adapt it uses "Use for this agent", which copies it.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses an edit of a seeded eval dataset template.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
class EvalTemplateGuardListener implements IEventListener {

	/**
	 * What an edit of a template is refused with.
	 *
	 * @var string
	 */
	public const REFUSED = 'This test set is a template and cannot be changed. Use it for an agent to get a copy you can edit.';

	/**
	 * The register that holds hermiq's datasets.
	 *
	 * @var string
	 */
	private const REGISTER = 'hermiq';

	/**
	 * The dataset schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'evaldataset';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper    $schemaMapper   Tells a dataset from other objects.
	 * @param RegisterMapper  $registerMapper Tells hermiq's register from others.
	 * @param LoggerInterface $logger         Records a refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the update when the stored dataset is a template.
	 *
	 * @param Event $event The before-update event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false || $event->isPropagationStopped() === true) {
			return;
		}

		$stored = $event->getOldObject();
		if ($stored === null || ($stored->getObject()['template'] ?? false) !== true) {
			return;
		}

		if ($this->isDataset(object: $stored) === false) {
			return;
		}

		$event->setErrors(['template' => self::REFUSED]);
		$event->stopPropagation();
		$this->logger->info(
			'[EvalTemplateGuardListener] Refused an edit of eval dataset template {uuid}',
			['uuid' => (string)$stored->getUuid()]
		);
	}//end handle()

	/**
	 * Whether an object is one of hermiq's eval datasets. The register check
	 * matters: a schema slug is not unique across the instance.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isDataset(ObjectEntity $object): bool {
		$schema = (string)$object->getSchema();
		if ($schema === self::SCHEMA) {
			return true;
		}

		if ($schema === '' || ctype_digit($schema) === false) {
			return false;
		}

		try {
			return (string)$this->schemaMapper->find((int)$schema)->getSlug() === self::SCHEMA
				&& (string)$this->registerMapper->find((int)$object->getRegister())->getSlug() === self::REGISTER;
		} catch (Throwable $e) {
			return false;
		}
	}//end isDataset()
}//end class
