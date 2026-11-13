<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Hermiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\AgentTemplateServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AiOversightServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AnalyticsServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AssistantServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ContextAgentInteractionServiceInterface;
use OCA\Hermiq\Kernel\Contracts\CourseRecommendationEngineInterface;
use OCA\Hermiq\Kernel\Contracts\DelegationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\EngineInterface;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\FlowAgentRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplateCatalogServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplatePushServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GoalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\IntakeServiceInterface;
use OCA\Hermiq\Kernel\Contracts\LessonAuthoringEngineInterface;
use OCA\Hermiq\Kernel\Contracts\MailReadServiceInterface;
use OCA\Hermiq\Kernel\Contracts\MemoryServiceInterface;
use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Kernel\Contracts\OrganisationOpsServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Kernel\Contracts\RecordSummaryServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ReportSimilarityServiceInterface;
use OCA\Hermiq\Kernel\Contracts\RunHistoryServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleFlowBridgeInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ServerSideWorkspaceProviderInterface;
use OCA\Hermiq\Kernel\Contracts\SkillBundleSerializerInterface;
use OCA\Hermiq\Kernel\Contracts\SkillLearningsCaptureServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillLearningsPromotionServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMarketplaceServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMaturityServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\Hermiq\Kernel\Contracts\StageDispatchServiceInterface;
use OCA\Hermiq\Kernel\Contracts\TalkRoomBindingInterface;
use OCA\Hermiq\Kernel\Contracts\ToolAccessRequestServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ToolLoopInterface;
use OCA\Hermiq\Kernel\Contracts\WebhookAgentRunServiceInterface;
use OCA\Hermiq\Service\Assistant\AssistantService;
use OCA\Hermiq\Service\Assistant\RecordSummaryService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Intake\IntakeService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilarityService;
use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Hermiq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AgentTemplateServiceInterface::class, AgentTemplateService::class);
		$context->registerServiceAlias(AiOversightServiceInterface::class, AiOversightService::class);
		$context->registerServiceAlias(AnalyticsServiceInterface::class, AnalyticsService::class);
		$context->registerServiceAlias(ApprovalServiceInterface::class, ApprovalService::class);
		$context->registerServiceAlias(AssistantServiceInterface::class, AssistantService::class);
		$context->registerServiceAlias(ComplianceServiceInterface::class, ComplianceService::class);
		$context->registerServiceAlias(ContextAgentInteractionServiceInterface::class, ContextAgentInteractionService::class);
		$context->registerServiceAlias(CourseRecommendationEngineInterface::class, CourseRecommendationEngine::class);
		$context->registerServiceAlias(DelegationServiceInterface::class, DelegationService::class);
		$context->registerServiceAlias(EngineInterface::class, Engine::class);
		$context->registerServiceAlias(EvalRunServiceInterface::class, EvalRunService::class);
		$context->registerServiceAlias(FlowAgentRunServiceInterface::class, FlowAgentRunService::class);
		$context->registerServiceAlias(GitHubTemplateCatalogServiceInterface::class, GitHubTemplateCatalogService::class);
		$context->registerServiceAlias(GitHubTemplatePushServiceInterface::class, GitHubTemplatePushService::class);
		$context->registerServiceAlias(GoalServiceInterface::class, GoalService::class);
		$context->registerServiceAlias(GuardrailPolicyServiceInterface::class, GuardrailPolicyService::class);
		$context->registerServiceAlias(IntakeServiceInterface::class, IntakeService::class);
		$context->registerServiceAlias(LessonAuthoringEngineInterface::class, LessonAuthoringEngine::class);
		$context->registerServiceAlias(MailReadServiceInterface::class, MailReadService::class);
		$context->registerServiceAlias(MemoryServiceInterface::class, MemoryService::class);
		$context->registerServiceAlias(MessageHistoryHandlerInterface::class, MessageHistoryHandler::class);
		$context->registerServiceAlias(OrganisationOpsServiceInterface::class, OrganisationOpsService::class);
		$context->registerServiceAlias(ProviderFactoryInterface::class, ProviderFactory::class);
		$context->registerServiceAlias(RecordSummaryServiceInterface::class, RecordSummaryService::class);
		$context->registerServiceAlias(ReportSimilarityServiceInterface::class, ReportSimilarityService::class);
		$context->registerServiceAlias(RunHistoryServiceInterface::class, RunHistoryService::class);
		$context->registerServiceAlias(ScheduleFlowBridgeInterface::class, ScheduleFlowBridge::class);
		$context->registerServiceAlias(ScheduleServiceInterface::class, ScheduleService::class);
		$context->registerServiceAlias(ServerSideWorkspaceProviderInterface::class, ServerSideWorkspaceProvider::class);
		$context->registerServiceAlias(SkillBundleSerializerInterface::class, SkillBundleSerializer::class);
		$context->registerServiceAlias(SkillLearningsCaptureServiceInterface::class, SkillLearningsCaptureService::class);
		$context->registerServiceAlias(SkillLearningsPromotionServiceInterface::class, SkillLearningsPromotionService::class);
		$context->registerServiceAlias(SkillMarketplaceServiceInterface::class, SkillMarketplaceService::class);
		$context->registerServiceAlias(SkillMaturityServiceInterface::class, SkillMaturityService::class);
		$context->registerServiceAlias(SkillServiceInterface::class, SkillService::class);
		$context->registerServiceAlias(StageDispatchServiceInterface::class, StageDispatchService::class);
		$context->registerServiceAlias(TalkRoomBindingInterface::class, TalkRoomBinding::class);
		$context->registerServiceAlias(ToolAccessRequestServiceInterface::class, ToolAccessRequestService::class);
		$context->registerServiceAlias(ToolLoopInterface::class, ToolLoop::class);
		$context->registerServiceAlias(WebhookAgentRunServiceInterface::class, WebhookAgentRunService::class);
	}//end register()
}//end class
