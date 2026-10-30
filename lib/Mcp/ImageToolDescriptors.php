<?php

/**
 * Hermiq ImageToolDescriptors.
 *
 * The governed tool that creates an image through Nextcloud TaskProcessing
 * (chat-attachments-and-images D7). Scope `create`, reach user, default-deny like
 * every other grant. HermiqToolProvider lists it only while a text-to-image
 * provider is available and routes it to ImageGenerationService.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-an-agent-can-create-an-image-only-with-a-grant-req-cimg-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * The image tool descriptor.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-an-agent-can-create-an-image-only-with-a-grant-req-cimg-003
 */
final class ImageToolDescriptors {

	/**
	 * The tool id.
	 *
	 * @var string
	 */
	public const GENERATE_IMAGE = Application::APP_ID . '.generateImage';

	/**
	 * Every id this class describes.
	 *
	 * @var list<string>
	 */
	public const IDS = [self::GENERATE_IMAGE];

	/**
	 * The descriptors.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const ALL = [
		[
			'id' => self::GENERATE_IMAGE,
			'subject' => 'image',
			'action' => 'create',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Create an image',
			'description' => 'Create one PNG image from a description through the instance\'s text-to-image provider. '
				. 'The image is saved in the person\'s Files under Hermiq/Generated images and tagged Agent authored. '
				. 'Returns the file id and path, never the image.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'prompt' => ['type' => 'string', 'description' => 'What the image shows.'],
				],
				'required' => ['prompt'],
			],
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
			'scope' => 'create',
		],
	];
}//end class
