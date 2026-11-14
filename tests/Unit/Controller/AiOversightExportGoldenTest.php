<?php

/**
 * Golden tests for the AI oversight export: paging, the row cap, the CSV cells
 * and a read failure, plus the exact page request of the listing route.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/ai-oversight-subject-export/specs/ai-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AiOversightController;
use OCA\Hermiq\Service\AiOversightAccess;
use OCA\Hermiq\Service\AiOversightService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the subject export.
 *
 * @spec openspec/changes/ai-oversight-subject-export/specs/ai-oversight/spec.md
 */
class AiOversightExportGoldenTest extends TestCase {

    /**
     * Every listForSubject call, as its arguments.
     *
     * @var array<int, array<int, mixed>>
     */
    private array $calls = [];

    /**
     * A controller for a signed-in oversight holder over a paging service.
     *
     * @param callable $pageFor Answers a page for (limit, offset).
     *
     * @return AiOversightController
     */
    private function controller(callable $pageFor): AiOversightController {
        $this->calls = [];
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('dpo');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $access = $this->createMock(AiOversightAccess::class);
        $access->method('hasOversightRole')->willReturn(true);
        $access->method('maySeeSubject')->willReturn(true);

        $oversight = $this->createMock(AiOversightService::class);
        $oversight->method('listForSubject')->willReturnCallback(
            function (...$args) use ($pageFor): array {
                $this->calls[] = $args;
                return $pageFor($args[3], $args[4]);
            }
        );

        return new AiOversightController(
            $this->createMock(IRequest::class),
            $session,
            $access,
            $oversight,
            $this->createMock(LoggerInterface::class)
        );
    }//end controller()

    /**
     * Rows numbered from an offset.
     *
     * @param int $from The first number.
     * @param int $count How many.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $from, int $count): array {
        $rows = [];
        for ($i = $from; $i < ($from + $count); $i++) {
            $rows[] = ['decidedAt' => 't' . $i, 'decidedBy' => 'u', 'prompt' => 'p', 'status' => 's', 'advisoryContext' => ['model' => 'm']];
        }

        return $rows;
    }//end rows()

    /**
     * The export pages 200 at a time until the total, and stops on an empty page.
     *
     * @return void
     */
    public function testTheExportPagesUntilTheTotal(): void {
        $response = $this->controller(fn (int $limit, int $offset): array => ['results' => $this->rows($offset, min($limit, 450 - $offset)), 'total' => 450])
            ->subjectExport('caseapp', 'c-1', 'case');

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $this->assertSame(
            [['caseapp', 'c-1', 'case', 200, 0], ['caseapp', 'c-1', 'case', 200, 200], ['caseapp', 'c-1', 'case', 200, 400]],
            $this->calls
        );
        $this->assertSame(451, substr_count($response->render(), "\n"));

        $this->controller(fn (int $limit, int $offset): array => ['results' => ($offset === 0 ? $this->rows(0, 10) : []), 'total' => 999])
            ->subjectExport('caseapp', 'c-1');
        $this->assertCount(2, $this->calls);
    }//end testTheExportPagesUntilTheTotal()

    /**
     * The export stops at 5000 rows even when more exist.
     *
     * @return void
     */
    public function testTheExportIsCappedAt5000Rows(): void {
        $response = $this->controller(fn (int $limit, int $offset): array => ['results' => $this->rows($offset, $limit), 'total' => 99999])
            ->subjectExport('caseapp', 'c-1');

        $this->assertCount(25, $this->calls);
        $this->assertSame(5001, substr_count($response->render(), "\n"));
    }//end testTheExportIsCappedAt5000Rows()

    /**
     * The CSV header, a formula-looking cell quoted, a non-scalar cell blank.
     *
     * @return void
     */
    public function testTheCsvCells(): void {
        $rows = [
            ['decidedAt' => '=SUM(A1)', 'decidedBy' => '+alice', 'prompt' => "say \"hi\", then\nleave", 'status' => ['x'], 'advisoryContext' => ['model' => '@m']],
            ['decidedAt' => 5, 'decidedBy' => null, 'prompt' => '-1', 'status' => true],
        ];
        $response = $this->controller(fn (): array => ['results' => $rows, 'total' => 2])->subjectExport('a', 's');

        $this->assertSame(
            "decidedAt,decidedBy,prompt,status,model\n"
            . "'=SUM(A1),'+alice,\"say \"\"hi\"\", then\nleave\",,'@m\n"
            . "5,,'-1,1,\n",
            $response->render()
        );
    }//end testTheCsvCells()

    /**
     * A read that fails is a 500, and the listing route asks for exactly its page.
     *
     * @return void
     */
    public function testAReadFailureAndTheListingPage(): void {
        $response = $this->controller(fn (): array => throw new RuntimeException('db'))->subjectExport('a', 's');
        $this->assertSame([500, ['error' => 'The AI oversight records could not be read']], [$response->getStatus(), $response->getData()]);

        $response = $this->controller(fn (): array => ['results' => [], 'total' => 0])->subject('a', 's', 't', 10, 30);
        $this->assertSame([['a', 's', 't', 10, 30]], $this->calls);
        $this->assertSame(['results' => [], 'total' => 0], $response->getData());
    }//end testAReadFailureAndTheListingPage()
}//end class
