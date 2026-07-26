<?php

/**
 * Hermiq's adapter onto OpenRegister's federated configuration store.
 *
 * The GitHub store (search / install / publish of agent templates and skills)
 * used to be two bespoke hermiq services (GitHubTemplatePushService +
 * GitHubTemplateCatalogService, ~1,400 lines that re-implemented repo creation,
 * topic tagging, Git-Data pushes and topic search). That capability now lives
 * once, for the whole fleet, in OpenRegister's FederatedConfigService — signed
 * bundles, broker-custodied credentials, org trust rules. This thin adapter maps
 * hermiq's two "kinds" onto that engine (topic / type / bundle-path) and shapes
 * its output back into the exact envelope the store gallery already consumes, so
 * the controllers become pure delegators and the bespoke services are retired.
 *
 * The engine is resolved lazily via the server container (hermiq's established
 * cross-app pattern, mirroring the old broker resolution) and guarded on the
 * class existing, so an instance whose OpenRegister predates the engine still
 * boots.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Maps hermiq's agent-template + skill stores onto the shared federated engine.
 */
class FederatedStoreService
{
    /**
     * Kind: agent template.
     */
    public const KIND_AGENT_TEMPLATE = 'agent-template';

    /**
     * Kind: skill.
     */
    public const KIND_SKILL = 'skill';

    /**
     * Search outcome: success.
     */
    public const OUTCOME_OK = 'ok';

    /**
     * Search outcome: GitHub rate-limited.
     */
    public const OUTCOME_RATE_LIMITED = 'github_rate_limited';

    /**
     * Search outcome: GitHub unreachable.
     */
    public const OUTCOME_UNREACHABLE = 'github_unreachable';

    /**
     * The federated engine FQCN (resolved lazily; may be absent).
     */
    private const FED_CLASS = 'OCA\\OpenRegister\\Service\\Config\\FederatedConfigService';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Whether the federated engine is present on this instance.
     *
     * @return boolean Whether publish/discover through the shared engine is possible.
     */
    public function isBrokerAvailable(): bool
    {
        return class_exists(self::FED_CLASS) === true;

    }//end isBrokerAvailable()

    /**
     * Search the store for a kind, shaped as the gallery's envelope.
     *
     * @param string      $kind         KIND_AGENT_TEMPLATE|KIND_SKILL.
     * @param string|null $query        Optional free-text filter.
     * @param string|null $credentialId Optional broker credential (authenticated search).
     *
     * @return array{outcome: string, cards: array<int, array<string, mixed>>, brokerCredentialAvailable: bool, brokerUsed: bool, rateLimited: bool}
     */
    public function search(string $kind, ?string $query, ?string $credentialId): array
    {
        $fed = $this->engine();
        if ($fed === null) {
            return $this->envelope(outcome: self::OUTCOME_UNREACHABLE, cards: [], brokerUsed: false);
        }

        try {
            $found = $fed->discover(topic: $this->topicFor(kind: $kind), credentialId: $credentialId);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq store search failed: '.$e->getMessage(), ['exception' => $e]);
            return $this->envelope(outcome: self::OUTCOME_UNREACHABLE, cards: [], brokerUsed: false);
        }

        $cards = [];
        foreach ($found as $card) {
            $cards[] = $this->mapCard(card: (array) $card, kind: $kind);
        }

        // A topic search underlies discover(); apply the optional free-text filter here.
        if (is_string($query) === true && trim($query) !== '') {
            $needle = strtolower(trim($query));
            $cards  = array_values(
                array_filter(
                    $cards,
                    static function (array $c) use ($needle): bool {
                        $hay = strtolower($c['name'].' '.$c['owner'].' '.$c['repo'].' '.$c['description']);
                        return str_contains($hay, $needle);
                    }
                )
            );
        }

        return $this->envelope(
            outcome: self::OUTCOME_OK,
            cards: $cards,
            brokerUsed: ($credentialId !== null && $credentialId !== '')
        );

    }//end search()

    /**
     * Install a discovered repo's bundle into quarantine (skills) / as an object
     * (agent templates), via fetch → install on the shared engine.
     *
     * @param string      $kind         KIND_AGENT_TEMPLATE|KIND_SKILL.
     * @param string      $owner        The repo owner.
     * @param string      $repo         The repo short name.
     * @param string|null $credentialId Optional broker credential.
     *
     * @return array<string, mixed>|null The install result, or null when the bundle could not be fetched.
     */
    public function install(string $kind, string $owner, string $repo, ?string $credentialId): ?array
    {
        $fed = $this->engine();
        if ($fed === null) {
            return null;
        }

        $full = $owner.'/'.$repo;
        try {
            $bundle = $fed->fetchBundle(repo: $full, path: $this->pathFor(kind: $kind), credentialId: $credentialId);
        } catch (Throwable $e) {
            $this->logger->warning('Hermiq store install fetch failed: '.$e->getMessage());
            return null;
        }

        return $fed->install(typeId: $this->typeFor(kind: $kind), bundle: $bundle, source: $full);

    }//end install()

    /**
     * Publish one template/skill to a GitHub repo through the shared engine
     * (repo created + topic tagged + bundle signed).
     *
     * @param string $kind         KIND_AGENT_TEMPLATE|KIND_SKILL.
     * @param string $uuid         The template/skill UUID to publish.
     * @param string $owner        The repo owner.
     * @param string $repo         The repo short name.
     * @param string $credentialId The broker credential.
     *
     * @return array{repoUrl: string, commitSha: string, status: int} The publish result.
     *
     * @throws RuntimeException When the engine is absent or the push fails.
     */
    public function publish(string $kind, string $uuid, string $owner, string $repo, string $credentialId): array
    {
        $fed = $this->engine();
        if ($fed === null) {
            throw new RuntimeException('The GitHub credential broker is not available');
        }

        $full   = $owner.'/'.$repo;
        $result = $fed->publish(
            typeId: $this->typeFor(kind: $kind),
            selection: [$this->selectionKeyFor(kind: $kind) => [$uuid]],
            repo: $full,
            path: $this->pathFor(kind: $kind),
            credentialId: $credentialId
        );

        return [
            'repoUrl'   => 'https://github.com/'.$full,
            'commitSha' => (string) ($result['commitSha'] ?? ''),
            'status'    => (int) ($result['status'] ?? 0),
        ];

    }//end publish()

    /**
     * Resolve the federated engine lazily, or null when absent.
     *
     * @return FederatedConfigService|null The engine.
     */
    private function engine(): ?FederatedConfigService
    {
        if ($this->isBrokerAvailable() === false) {
            return null;
        }

        try {
            return Server::get(FederatedConfigService::class);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq store: could not resolve the federated engine: '.$e->getMessage());
            return null;
        }

    }//end engine()

    /**
     * Map a discover() card into the gallery's card shape.
     *
     * The discover() card is `{repo (=owner/name), name, description, url, stars, ...}`.
     * The gallery needs `{owner, repo (short), kind, name, stars, description,
     * unparseable, installable}`. Parseability/version aren't known without
     * fetching each bundle, so cards are optimistic; install validates.
     *
     * @param array  $card The discover() card.
     * @param string $kind The kind.
     *
     * @return array<string, mixed> The gallery card.
     */
    private function mapCard(array $card, string $kind): array
    {
        $full  = (string) ($card['repo'] ?? '');
        $owner = '';
        $short = $full;
        if (str_contains($full, '/') === true) {
            [$owner, $short] = explode('/', $full, 2);
        }

        $name = (string) ($card['name'] ?? '');
        if ($name === '') {
            $name = $short;
        }

        return [
            'owner'       => $owner,
            'repo'        => $short,
            'kind'        => $kind,
            'name'        => $name,
            'stars'       => (int) ($card['stars'] ?? 0),
            'description' => (string) ($card['description'] ?? ''),
            'unparseable' => false,
            'installable' => true,
        ];

    }//end mapCard()

    /**
     * The gallery envelope.
     *
     * @param string $outcome    The outcome string.
     * @param array  $cards      The cards.
     * @param bool   $brokerUsed Whether a credential was used.
     *
     * @return array{outcome: string, cards: array, brokerCredentialAvailable: bool, brokerUsed: bool, rateLimited: bool}
     */
    private function envelope(string $outcome, array $cards, bool $brokerUsed): array
    {
        return [
            'outcome'                   => $outcome,
            'cards'                     => $cards,
            'brokerCredentialAvailable' => $this->isBrokerAvailable(),
            'brokerUsed'                => $brokerUsed,
            'rateLimited'               => ($outcome === self::OUTCOME_RATE_LIMITED),
        ];

    }//end envelope()

    /**
     * The discovery topic for a kind (pinned to hermiq's published corpus).
     *
     * @param string $kind The kind.
     *
     * @return string The topic.
     */
    private function topicFor(string $kind): string
    {
        if ($kind === self::KIND_SKILL) {
            return 'hermiq-skill';
        }

        return 'hermiq-agent-template';

    }//end topicFor()

    /**
     * The shareable-config type id for a kind.
     *
     * @param string $kind The kind.
     *
     * @return string The type id.
     */
    private function typeFor(string $kind): string
    {
        if ($kind === self::KIND_SKILL) {
            return 'hermiq.skill';
        }

        return 'hermiq.agent-template';

    }//end typeFor()

    /**
     * The bundle file path within a repo for a kind.
     *
     * @param string $kind The kind.
     *
     * @return string The path.
     */
    private function pathFor(string $kind): string
    {
        if ($kind === self::KIND_SKILL) {
            return 'hermiq-skill.json';
        }

        return 'hermiq-agent-template.json';

    }//end pathFor()

    /**
     * The selection key a type's serialise() expects for a kind.
     *
     * @param string $kind The kind.
     *
     * @return string The selection key.
     */
    private function selectionKeyFor(string $kind): string
    {
        if ($kind === self::KIND_SKILL) {
            return 'skillIds';
        }

        return 'ids';

    }//end selectionKeyFor()
}//end class
