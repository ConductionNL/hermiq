<?php

/**
 * Unit tests for the CheckOpenRegisterCompatibility repair step.
 *
 * Drives run() in both branches without needing to actually remove OpenRegister's
 * classes from the autoloader: the "classes present" case exercises the real,
 * hardcoded FQCN list (both classes exist as test stubs — see composer.json
 * autoload-dev `OCA\OpenRegister\ => tests/Stubs/`); the "classes absent" case uses
 * an anonymous subclass that overrides the protected getRequiredClasses() seam with
 * a guaranteed-nonexistent FQCN, proving the detection + actionable-message logic
 * without depending on OpenRegister's real install state.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Live e2e verification finding, no design change — see the repair
 * step's file-level docblock.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\CheckOpenRegisterCompatibility;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OpenRegister-compatibility repair step.
 *
 * @spec exclude See file-level docblock.
 */
class CheckOpenRegisterCompatibilityTest extends TestCase
{

    /**
     * When both required OpenRegister classes are present (the real FQCNs, backed
     * by the test stubs), run() must log info only — no warning, no error.
     *
     * @return void
     */
    public function testAllClassesPresentLogsInfoOnly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info');
        $output->expects($this->never())->method('warning');

        $subject = new CheckOpenRegisterCompatibility($logger);
        $subject->run($output);

    }//end testAllClassesPresentLogsInfoOnly()

    /**
     * When a required class is missing, run() must warn (console) and log an error
     * (persistent log trail) naming the minimum OpenRegister version and the
     * missing class — never throw.
     *
     * @return void
     */
    public function testMissingClassLogsActionableWarningAndError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $capturedError = null;
        $logger->expects($this->once())->method('error')->willReturnCallback(
            function (string $message) use (&$capturedError): void {
                $capturedError = $message;
            }
        );

        $capturedWarning = null;
        $output = $this->createMock(IOutput::class);
        $output->expects($this->never())->method('info');
        $output->expects($this->once())->method('warning')->willReturnCallback(
            function (string $message) use (&$capturedWarning): void {
                $capturedWarning = $message;
            }
        );

        $subject = new class ($logger) extends CheckOpenRegisterCompatibility {
            protected function getRequiredClasses(): array
            {
                return ['OCA\Hermiq\Tests\Fixtures\DoesNotExist' => 'Fake missing class (test fixture)'];
            }//end getRequiredClasses()
        };

        $subject->run($output);

        $this->assertNotNull($capturedWarning);
        $this->assertStringContainsString('Fake missing class (test fixture)', $capturedWarning);
        $this->assertStringContainsString(
            CheckOpenRegisterCompatibility::MIN_OPENREGISTER_VERSION,
            $capturedWarning
        );
        $this->assertSame($capturedWarning, $capturedError, 'The console warning and the logged error must carry the same actionable message.');

    }//end testMissingClassLogsActionableWarningAndError()
}//end class
