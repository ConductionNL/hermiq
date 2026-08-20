<?php

/**
 * Tests for the composer-facing speech endpoints.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SpeechController;
use OCA\Hermiq\Service\Speech\SpeechClient;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for SpeechController.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 */
class SpeechControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * Mock sidecar transport.
	 *
	 * @var SpeechClient
	 */
	private SpeechClient $speech;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Mock session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Wire fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->speech = $this->createMock(SpeechClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return SpeechController
	 */
	private function controller(): SpeechController {
		return new SpeechController(
			$this->request,
			$this->speech,
			$this->userSession,
			$this->logger
		);

	}//end controller()

	/**
	 * Stub `getParam`.
	 *
	 * @param array<string, mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

	}//end stubParams()

	/**
	 * Write a temporary clip and describe it the way PHP describes an upload.
	 *
	 * @param string $bytes The clip contents.
	 * @param int $error The upload error code.
	 *
	 * @return array<string, mixed> The upload array.
	 */
	private function upload(string $bytes, int $error = UPLOAD_ERR_OK): array {
		$path = tempnam(sys_get_temp_dir(), 'speech');
		file_put_contents($path, $bytes);

		return [
			'name' => 'dictation.webm',
			'tmp_name' => $path,
			'size' => strlen($bytes),
			'error' => $error,
		];

	}//end upload()

	/**
	 * A request with no clip is refused, and the sidecar is never called.
	 *
	 * @return void
	 */
	public function testTranscribeWithoutAudioIs400(): void {
		$this->request->method('getUploadedFile')->willReturn([]);
		$this->speech->expects($this->never())->method('transcribe');

		$response = $this->controller()->transcribe();

		$this->assertSame(400, $response->getStatus());

	}//end testTranscribeWithoutAudioIs400()

	/**
	 * A failed upload is refused rather than transcribed as an empty clip.
	 *
	 * @return void
	 */
	public function testTranscribeWithAFailedUploadIs400(): void {
		$this->request->method('getUploadedFile')->willReturn(
			$this->upload('something', UPLOAD_ERR_PARTIAL)
		);
		$this->speech->expects($this->never())->method('transcribe');

		$this->assertSame(400, $this->controller()->transcribe()->getStatus());

	}//end testTranscribeWithAFailedUploadIs400()

	/**
	 * 🔴 THE SIZE CAP IS A DENIAL-OF-SERVICE BOUND, NOT A UX LIMIT.
	 *
	 * Transcription is CPU-bound and roughly realtime at best on the hardware
	 * this ships to, so minutes of audio is minutes of a pegged core per
	 * request. An authenticated caller must not be able to buy that with one
	 * POST, which is why the check happens BEFORE the bytes are read.
	 *
	 * @return void
	 */
	public function testTranscribeRefusesAnOversizedClipWithoutReadingIt(): void {
		$upload = $this->upload('x');
		// Claim a size far past the cap while the file itself stays tiny: the
		// guard must act on the declared size, not on what it manages to read.
		$upload['size'] = (64 * 1024 * 1024);
		$this->request->method('getUploadedFile')->willReturn($upload);
		$this->speech->expects($this->never())->method('transcribe');

		$this->assertSame(400, $this->controller()->transcribe()->getStatus());

	}//end testTranscribeRefusesAnOversizedClipWithoutReadingIt()

	/**
	 * The happy path returns the transcript, the language and the engine.
	 *
	 * @return void
	 */
	public function testTranscribeReturnsTheTranscript(): void {
		$this->request->method('getUploadedFile')->willReturn($this->upload('AUDIOBYTES'));
		$this->stubParams(['language' => 'nl']);
		$this->speech->expects($this->once())
			->method('transcribe')
			->with('AUDIOBYTES', 'nl')
			->willReturn(['text' => 'hoeveel verlofdagen heb ik nog', 'language' => 'nl', 'engine' => 'base']);

		$response = $this->controller()->transcribe();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('hoeveel verlofdagen heb ik nog', $response->getData()['text']);
		$this->assertSame('nl', $response->getData()['language']);

	}//end testTranscribeReturnsTheTranscript()

	/**
	 * An unreachable sidecar is a 502 with a stated reason.
	 *
	 * Reported rather than swallowed: the caller's alternative is a cloud
	 * engine, and a failure it cannot see is a failure it cannot decide about.
	 *
	 * @return void
	 */
	public function testTranscribeReportsAnUnavailableService(): void {
		$this->request->method('getUploadedFile')->willReturn($this->upload('AUDIOBYTES'));
		$this->stubParams([]);
		$this->speech->method('transcribe')->willThrowException(new RuntimeException('down'));

		$this->assertSame(502, $this->controller()->transcribe()->getStatus());

	}//end testTranscribeReportsAnUnavailableService()

	/**
	 * 🔴 THE TRANSCRIPT IS NEVER LOGGED.
	 *
	 * An oversight record that quotes the dictated text is a second copy of the
	 * conversation somewhere with different access rules — the exact thing
	 * routing speech to the instance's own service was meant to avoid. The
	 * engine, the language and the byte count are fine; the words are not.
	 *
	 * @return void
	 */
	public function testTranscriptionIsNeverLogged(): void {
		$this->request->method('getUploadedFile')->willReturn($this->upload('AUDIOBYTES'));
		$this->stubParams(['language' => 'nl']);
		$this->speech->method('transcribe')->willReturn(
			['text' => 'het BSN van de klant is 123456782', 'language' => 'nl', 'engine' => 'base']
		);

		$logged = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = ($message . ' ' . json_encode($context));
			}
		);

		$this->controller()->transcribe();

		$this->assertNotEmpty($logged, 'the call should be recorded at all');
		foreach ($logged as $line) {
			$this->assertStringNotContainsString('123456782', $line);
			$this->assertStringNotContainsString('het BSN', $line);
		}

	}//end testTranscriptionIsNeverLogged()

	/**
	 * Empty text is refused rather than synthesising silence.
	 *
	 * @return void
	 */
	public function testSynthesiseWithoutTextIs400(): void {
		$this->stubParams(['text' => '   ']);
		$this->speech->expects($this->never())->method('synthesise');

		$this->assertSame(400, $this->controller()->synthesise()->getStatus());

	}//end testSynthesiseWithoutTextIs400()

	/**
	 * Very long text is refused — synthesis is slower than realtime on CPU.
	 *
	 * @return void
	 */
	public function testSynthesiseRefusesVeryLongText(): void {
		$this->stubParams(['text' => str_repeat('a', 5000)]);
		$this->speech->expects($this->never())->method('synthesise');

		$this->assertSame(400, $this->controller()->synthesise()->getStatus());

	}//end testSynthesiseRefusesVeryLongText()

	/**
	 * The happy path returns audio bytes, as audio.
	 *
	 * @return void
	 */
	public function testSynthesiseReturnsAudio(): void {
		$this->stubParams(['text' => 'Dit is een test.']);
		$this->speech->expects($this->once())
			->method('synthesise')
			->willReturn(['audio' => 'RIFFDATA', 'engine' => 'kokoro']);

		$response = $this->controller()->synthesise();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('RIFFDATA', $response->render());

	}//end testSynthesiseReturnsAudio()

	/**
	 * A failing sidecar is a 502 here too.
	 *
	 * @return void
	 */
	public function testSynthesiseReportsAnUnavailableService(): void {
		$this->stubParams(['text' => 'Dit is een test.']);
		$this->speech->method('synthesise')->willThrowException(new RuntimeException('down'));

		$response = $this->controller()->synthesise();

		$this->assertSame(502, $response->getStatus());

	}//end testSynthesiseReportsAnUnavailableService()

	/**
	 * 🔴 CAPABILITIES REACHES THE SERVICE, AND REPORTS WHAT IT FOUND.
	 *
	 * This instance advertised `core:audio2text` for months while its speech
	 * service was unreachable, because registration is not reachability. Here a
	 * wrong answer is worse than a failed transcription: the frontend's
	 * fallback is a cloud engine, so "available" when it is not can send audio
	 * off-instance for an agent configured never to do that.
	 *
	 * @return void
	 */
	public function testCapabilitiesReportsReachability(): void {
		$this->speech->expects($this->once())->method('isReachable')->willReturn(true);
		$available = $this->controller()->capabilities();

		$this->assertTrue($available->getData()['available']);
		$this->assertSame('', $available->getData()['reason']);

	}//end testCapabilitiesReportsReachability()

	/**
	 * An unreachable service is reported as unavailable, with a reason.
	 *
	 * @return void
	 */
	public function testCapabilitiesReportsUnreachable(): void {
		$this->speech->method('isReachable')->willReturn(false);

		$data = $this->controller()->capabilities()->getData();

		$this->assertFalse($data['available']);
		$this->assertSame('speech_service_unreachable', $data['reason']);

	}//end testCapabilitiesReportsUnreachable()

	/**
	 * A probe that THROWS is unavailable, not an exception to the caller.
	 *
	 * @return void
	 */
	public function testCapabilitiesTreatsAThrowAsUnavailable(): void {
		$this->speech->method('isReachable')->willThrowException(new RuntimeException('boom'));

		$this->assertFalse($this->controller()->capabilities()->getData()['available']);

	}//end testCapabilitiesTreatsAThrowAsUnavailable()

}//end class
