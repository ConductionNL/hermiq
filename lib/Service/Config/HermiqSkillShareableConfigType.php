<?php

/**
 * Agent skills as a shareable configuration type.
 *
 * hermiq's skills are agentskills.io packages — a `---` fenced markdown frontmatter
 * block plus a body — not plain OpenRegister object fields. So a skill cannot ride
 * the generic object marker (which would emit its fields as JSON and break
 * interop with the agentskills.io ecosystem and hermiq's own quarantine install).
 * Instead this per-app type carries each skill's package string verbatim through
 * the federated-config engine, delegating (de)serialisation to hermiq's own
 * `SkillSerializer` / `SkillMarketplaceService` so a shared skill installs into
 * quarantine exactly as a hub-installed one does.
 *
 * Heavy hermiq services are resolved lazily (this type is built by OpenRegister's
 * cross-app dispatcher via the SERVER container, so construction stays cheap and
 * the skill chain is only assembled when a skill is actually shared or installed).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Config;

use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillSerializer;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Shares and installs hermiq agent skills through the federated-config engine.
 */
class HermiqSkillShareableConfigType implements IShareableConfigType
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container Resolves the skill services lazily.
     */
    public function __construct(
        private readonly ContainerInterface $container
    ) {

    }//end __construct()

    /**
     * The type id.
     *
     * @return string The id.
     */
    public function getId(): string
    {
        return 'hermiq.skill';

    }//end getId()

    /**
     * The display name.
     *
     * @return string The name.
     */
    public function getDisplayName(): string
    {
        return 'Agent skills';

    }//end getDisplayName()

    /**
     * The discovery topic. Pinned to hermiq's existing corpus so previously
     * published skill repos stay discoverable after the cutover.
     *
     * @return string The topic.
     */
    public function getTopic(): string
    {
        return 'hermiq-skill';

    }//end getTopic()

    /**
     * Package selected skills (or all) into a portable bundle.
     *
     * Each skill keeps its agentskills.io package string verbatim, so a byte-for-byte
     * round trip survives the store.
     *
     * @param array $selection `{skillIds?: [...]}`.
     *
     * @return array `{type, version, skills: [{name, package}]}`.
     */
    public function serialise(array $selection): array
    {
        $skillService = $this->container->get(SkillService::class);
        $serializer   = $this->container->get(SkillSerializer::class);

        $wanted = array_map('strval', (array) ($selection['skillIds'] ?? []));

        $skills = [];
        if ($wanted !== []) {
            foreach ($wanted as $id) {
                $skill = $skillService->getSkill(skillId: $id);
                if ($skill !== null) {
                    $skills[] = $skill;
                }
            }
        } else {
            $skills = $skillService->listSkills();
        }

        $out = [];
        foreach ($skills as $skill) {
            if (($skill instanceof ObjectEntity) === false) {
                continue;
            }

            $data  = $skill->getObject();
            $out[] = [
                'name'    => (string) ($data['name'] ?? ''),
                'package' => $serializer->toPackage(skill: $data),
            ];
        }

        return [
            'type'    => $this->getId(),
            'version' => '1.0',
            'skills'  => $out,
        ];

    }//end serialise()

    /**
     * Install a skill bundle into this instance (into quarantine, never active).
     *
     * @param array $bundle A bundle produced by this type.
     *
     * @return array `{installed: [uuid, ...]}`.
     */
    public function deserialise(array $bundle): array
    {
        $marketplace = $this->container->get(SkillMarketplaceService::class);

        $uid = '';
        try {
            $user = $this->container->get(IUserSession::class)->getUser();
            if ($user !== null) {
                $uid = (string) $user->getUID();
            }
        } catch (Throwable $e) {
            $uid = '';
        }

        $installed = [];
        foreach ((array) ($bundle['skills'] ?? []) as $skill) {
            if (is_array($skill) === false) {
                continue;
            }

            $package = (string) ($skill['package'] ?? '');
            if ($package === '') {
                continue;
            }

            $saved       = $marketplace->installFromSource(package: $package, source: 'hub', createdBy: $uid);
            $installed[] = (string) $saved->getUuid();
        }

        return ['installed' => $installed];

    }//end deserialise()
}//end class
