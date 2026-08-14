<?php

/**
 * Contributes hermiq's shareable configuration types to OpenRegister's engine.
 *
 * OpenRegister owns one federated way to share configuration over GitHub. hermiq
 * contributes its skill type through the same `RegisterShareableConfigTypesEvent`
 * every consuming app uses (its agent templates ride the schema marker instead).
 * The type is resolved lazily so the heavy skill chain is only built when the
 * event actually fires.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Config\HermiqSkillShareableConfigType;
use OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers hermiq's skill shareable-config type.
 *
 * @template-implements IEventListener<RegisterShareableConfigTypesEvent>
 *
 * @spec exclude Contributes to OpenRegister's federated-config engine, whose
 * canonical spec has its single home in openregister (federated-config-sharing)
 * and is not yet archived into openspec/specs/ there. Hermiq adopts that spec
 * rather than forking a local copy.
 */
class ShareableConfigTypeListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the type lazily.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Register the skill type.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec exclude Contributes to OpenRegister's federated-config engine; the
	 * canonical spec has its single home in openregister
	 * (federated-config-sharing).
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterShareableConfigTypesEvent) === false) {
			return;
		}

		try {
			$event->registerType(type: $this->container->get(HermiqSkillShareableConfigType::class));
		} catch (Throwable $e) {
			$this->logger->warning(
				'[Hermiq] could not register the skill shareable-config type: ' . $e->getMessage(),
				['app' => 'hermiq']
			);
		}

	}//end handle()
}//end class
