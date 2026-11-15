<?php

/**
 * The contact lookup and send-mail scenarios of the nc-native-tools rows.
 *
 * Rows `tl-contacts` and `tl-mail` (verify-row-scenarios, task 1.2): an agent
 * finds a contact's address in the address books its user can read, and sends
 * a mail through the instance's mailer from the user's own address.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/verify-row-scenarios/specs/nc-native-tools/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An agent looks up a contact and sends an email for its case handler.
 *
 * @covers \OCA\Hermiq\Mcp\HermiqToolProvider
 */
class HermiqToolProviderRowScenariosTest extends TestCase {

	/**
	 * The provider acting for the case handler.
	 *
	 * @param IContactsManager $contacts The address books.
	 * @param IMailer          $mailer   The instance mailer.
	 *
	 * @return HermiqToolProvider
	 */
	private function provider(IContactsManager $contacts, IMailer $mailer): HermiqToolProvider {
		$handler = $this->createMock(IUser::class);
		$handler->method('getUID')->willReturn('casehandler');
		$handler->method('getEMailAddress')->willReturn('m.devries@gemeente.example');
		$handler->method('getDisplayName')->willReturn('Marieke de Vries');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($handler);

		return new HermiqToolProvider(
			$session,
			$this->createMock(IRootFolder::class),
			$contacts,
			$this->createMock(ICalendarManager::class),
			$mailer,
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(CourseRecommendationEngine::class),
			$this->createMock(MemoryService::class),
			$this->createMock(WebSearchClient::class),
			$this->createMock(WebFetchService::class),
			$this->createMock(DelegationService::class),
			$this->createMock(NcNativeWriteService::class),
			$this->createMock(MailReadService::class),
			$this->createMock(ToolAccessRequestService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end provider()

	/**
	 * "What is the email address of Jansen Bouw?": the agent searches the
	 * address books by name and email and answers with the contact's address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/verify-row-scenarios/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
	 */
	public function testAnAgentLooksUpAContact(): void {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->expects($this->once())
			->method('search')
			->with('Jansen Bouw', ['FN', 'EMAIL'], ['limit' => 25])
			->willReturn([['FN' => 'Jansen Bouw BV', 'EMAIL' => ['info@jansenbouw.example', 'facturen@jansenbouw.example']]]);

		$answer = $this->provider($contacts, $this->createMock(IMailer::class))->invokeTool('hermiq.searchContacts', ['query' => 'Jansen Bouw']);

		$this->assertSame(['query' => 'Jansen Bouw', 'results' => [['name' => 'Jansen Bouw BV', 'email' => 'info@jansenbouw.example']]], $answer);
	}//end testAnAgentLooksUpAContact()

	/**
	 * "Mail 'Uw aanvraag is compleet' to the applicant": the mail goes out
	 * through the instance's mailer with that text, from the case handler's own address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/verify-row-scenarios/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
	 */
	public function testAnAgentSendsAnEmailFromTheCaseHandlersAddress(): void {
		$composed = [];
		$message = $this->createMock(IMessage::class);
		foreach (['setFrom', 'setTo', 'setSubject', 'setPlainBody'] as $setter) {
			$message->method($setter)->willReturnCallback(
				function (mixed $value) use (&$composed, $setter, $message) {
					$composed[$setter] = $value;
					return $message;
				}
			);
		}

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message)->willReturn([]);

		$sent = $this->provider($this->createMock(IContactsManager::class), $mailer)->invokeTool(
			'hermiq.sendMail',
			['to' => 'aanvrager@example.nl', 'subject' => 'Uw aanvraag', 'body' => 'Uw aanvraag is compleet']
		);

		$this->assertSame(['sent' => true, 'failedRecipients' => []], $sent);
		$this->assertSame(['m.devries@gemeente.example' => 'Marieke de Vries'], $composed['setFrom'], 'the sender is the case handler');
		$this->assertSame(['aanvrager@example.nl'], $composed['setTo']);
		$this->assertSame('Uw aanvraag is compleet', $composed['setPlainBody']);
	}//end testAnAgentSendsAnEmailFromTheCaseHandlersAddress()
}//end class
