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

use OCA\Hermiq\Kernel\Contracts\ActingUserScopeInterface;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ActionMatrixInterface;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentAvailabilityServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentCatalogInterface;
use OCA\Hermiq\Kernel\Contracts\AgentContextBuilderInterface;
use OCA\Hermiq\Kernel\Contracts\AgentDraftServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentGitServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Kernel\Contracts\AgentTemplateServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentVersionServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AiFeatureBindingServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AiFeatureServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AiOversightAccessInterface;
use OCA\Hermiq\Kernel\Contracts\AiOversightServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AlgoritmekaderMapperInterface;
use OCA\Hermiq\Kernel\Contracts\AnalyticsServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AppAssistantServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalTaskBridgeInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalVerdictServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AppTemplateOffersInterface;
use OCA\Hermiq\Kernel\Contracts\AssistantPromptLibraryInterface;
use OCA\Hermiq\Kernel\Contracts\AssistantServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AsyncStageDispatchServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AttachmentStoreInterface;
use OCA\Hermiq\Kernel\Contracts\BudgetServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ConnectionReporterInterface;
use OCA\Hermiq\Kernel\Contracts\ContextAgentInteractionServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ConversationTitleWriterInterface;
use OCA\Hermiq\Kernel\Contracts\CourseRecommendationEngineInterface;
use OCA\Hermiq\Kernel\Contracts\DelegationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\DemoDataServiceInterface;
use OCA\Hermiq\Kernel\Contracts\DocumentFeatureRunInterface;
use OCA\Hermiq\Kernel\Contracts\EffectiveModelPolicyInterface;
use OCA\Hermiq\Kernel\Contracts\EngineInterface;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\FlowAgentRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ForgeEgressPolicyInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplateCatalogServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplatePushServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GoalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GovernedMcpEndpointInterface;
use OCA\Hermiq\Kernel\Contracts\GrantWaiverAuditInterface;
use OCA\Hermiq\Kernel\Contracts\GraphExtractionServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GraphToolsInterface;
use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ImageGenerationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\InstructionVariablesServiceInterface;
use OCA\Hermiq\Kernel\Contracts\IntakeServiceInterface;
use OCA\Hermiq\Kernel\Contracts\IntakeSettingsInterface;
use OCA\Hermiq\Kernel\Contracts\LessonAuthoringEngineInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyCourseInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyReportInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Kernel\Contracts\LlmSettingsHandlerInterface;
use OCA\Hermiq\Kernel\Contracts\MailReadServiceInterface;
use OCA\Hermiq\Kernel\Contracts\MemoryServiceInterface;
use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Kernel\Contracts\MessageTranslationEngineInterface;
use OCA\Hermiq\Kernel\Contracts\ModelCapabilityRegistryInterface;
use OCA\Hermiq\Kernel\Contracts\NcNativeWriteServiceInterface;
use OCA\Hermiq\Kernel\Contracts\OrganisationControlServiceInterface;
use OCA\Hermiq\Kernel\Contracts\OrganisationModelPolicyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\OrganisationOpsServiceInterface;
use OCA\Hermiq\Kernel\Contracts\OutsideAgentGatewayInterface;
use OCA\Hermiq\Kernel\Contracts\OutsideToolSurfaceInterface;
use OCA\Hermiq\Kernel\Contracts\ProviderDataUseRegistryInterface;
use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Kernel\Contracts\ProviderResidencyRegistryInterface;
use OCA\Hermiq\Kernel\Contracts\PublicationGatewayInterface;
use OCA\Hermiq\Kernel\Contracts\RecordSummaryServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ReportSimilarityServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ReportSimilaritySettingsInterface;
use OCA\Hermiq\Kernel\Contracts\RunHistoryServiceInterface;
use OCA\Hermiq\Kernel\Contracts\RunRetentionCleanerInterface;
use OCA\Hermiq\Kernel\Contracts\RunRetentionPolicyInterface;
use OCA\Hermiq\Kernel\Contracts\RunStepBusInterface;
use OCA\Hermiq\Kernel\Contracts\RunTokenServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleFlowBridgeInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleWebhookSecretServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedFreshnessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ServerSideWorkspaceProviderInterface;
use OCA\Hermiq\Kernel\Contracts\SessionParticipantServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SessionPendingApprovalsInterface;
use OCA\Hermiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillBundleInstallerInterface;
use OCA\Hermiq\Kernel\Contracts\SkillBundleSerializerInterface;
use OCA\Hermiq\Kernel\Contracts\SkillConsolidationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillLearningsCaptureServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillLearningsPromotionServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMarketplaceServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMaturityServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillVersionServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SpeechClientInterface;
use OCA\Hermiq\Kernel\Contracts\StageDispatchServiceInterface;
use OCA\Hermiq\Kernel\Contracts\TalkAgentBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkApprovalBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkBotInstallerInterface;
use OCA\Hermiq\Kernel\Contracts\TalkBridgeStatusInterface;
use OCA\Hermiq\Kernel\Contracts\TalkMentionMatcherInterface;
use OCA\Hermiq\Kernel\Contracts\TalkRoomBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkRoomGroupingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkSessionRoomInterface;
use OCA\Hermiq\Kernel\Contracts\TalkTurnDispatcherInterface;
use OCA\Hermiq\Kernel\Contracts\TalkTurnServiceInterface;
use OCA\Hermiq\Kernel\Contracts\TaskWriteServiceInterface;
use OCA\Hermiq\Kernel\Contracts\TalkMessageToolInterface;
use OCA\Hermiq\Service\NcNative\TalkMessageTool;
use OCA\Hermiq\Kernel\Contracts\ToolAccessRequestServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ToolLoopInterface;
use OCA\Hermiq\Kernel\Contracts\ToolSearchServiceInterface;
use OCA\Hermiq\Kernel\Contracts\WebFetchServiceInterface;
use OCA\Hermiq\Kernel\Contracts\WebhookAgentRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\WebhookSecretServiceInterface;
use OCA\Hermiq\Kernel\Contracts\WebResearchEgressGuardInterface;
use OCA\Hermiq\Kernel\Contracts\WebResearchSettingsHandlerInterface;
use OCA\Hermiq\Kernel\Contracts\WebSearchClientInterface;
use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\Hermiq\Service\Agent\AgentCatalog;
use OCA\Hermiq\Service\Agent\AgentContextBuilder;
use OCA\Hermiq\Service\Agent\AgentDraftService;
use OCA\Hermiq\Service\Agent\AgentGitService;
use OCA\Hermiq\Service\Agent\AgentObjectMatcher;
use OCA\Hermiq\Service\Agent\AppAssistantService;
use OCA\Hermiq\Service\Agent\GrantWaiverAudit;
use OCA\Hermiq\Service\Agent\InstructionVariablesService;
use OCA\Hermiq\Service\AiFeature\AiFeatureBindingService;
use OCA\Hermiq\Service\AiFeature\DocumentFeatureRun;
use OCA\Hermiq\Service\AiFeature\ProviderDataUseRegistry;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeature\RunRetentionCleaner;
use OCA\Hermiq\Service\AiFeature\RunRetentionPolicy;
use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\Approval\ApprovalVerdictService;
use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCA\Hermiq\Service\Assistant\AssistantService;
use OCA\Hermiq\Service\Assistant\RecordSummaryService;
use OCA\Hermiq\Service\Chat\AttachmentStore;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\Engine\ConversationTitleWriter;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Graph\ActingUserScope;
use OCA\Hermiq\Service\Graph\GraphExtractionService;
use OCA\Hermiq\Service\Graph\GraphTools;
use OCA\Hermiq\Service\Intake\IntakeService;
use OCA\Hermiq\Service\Intake\IntakeSettings;
use OCA\Hermiq\Service\Literacy\LiteracyCourse;
use OCA\Hermiq\Service\Literacy\LiteracyReport;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\Hermiq\Service\Llm\GovernedMcpEndpoint;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\NcNative\TaskWriteService;
use OCA\Hermiq\Service\OutsideAgent\OutsideAgentGateway;
use OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilarityService;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\Speech\SpeechClient;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkApprovalBinding;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\Hermiq\Service\Talk\TalkBridgeStatus;
use OCA\Hermiq\Service\Talk\TalkMentionMatcher;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\Hermiq\Service\Talk\TalkTurnService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCA\Hermiq\Service\Workspace\ForgeEgressPolicy;
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
		$context->registerServiceAlias(ActingUserScopeInterface::class, ActingUserScope::class);
		$context->registerServiceAlias(ActionAuthServiceInterface::class, ActionAuthService::class);
		$context->registerServiceAlias(ActionMatrixInterface::class, ActionMatrix::class);
		$context->registerServiceAlias(AgentAccessServiceInterface::class, AgentAccessService::class);
		$context->registerServiceAlias(AgentAvailabilityServiceInterface::class, AgentAvailabilityService::class);
		$context->registerServiceAlias(AgentCatalogInterface::class, AgentCatalog::class);
		$context->registerServiceAlias(AgentContextBuilderInterface::class, AgentContextBuilder::class);
		$context->registerServiceAlias(AgentDraftServiceInterface::class, AgentDraftService::class);
		$context->registerServiceAlias(AgentGitServiceInterface::class, AgentGitService::class);
		$context->registerServiceAlias(AgentObjectMatcherInterface::class, AgentObjectMatcher::class);
		$context->registerServiceAlias(AgentTemplateServiceInterface::class, AgentTemplateService::class);
		$context->registerServiceAlias(AgentVersionServiceInterface::class, AgentVersionService::class);
		$context->registerServiceAlias(AiFeatureBindingServiceInterface::class, AiFeatureBindingService::class);
		$context->registerServiceAlias(AiFeatureServiceInterface::class, AiFeatureService::class);
		$context->registerServiceAlias(AiOversightAccessInterface::class, AiOversightAccess::class);
		$context->registerServiceAlias(AiOversightServiceInterface::class, AiOversightService::class);
		$context->registerServiceAlias(AlgoritmekaderMapperInterface::class, AlgoritmekaderMapper::class);
		$context->registerServiceAlias(AnalyticsServiceInterface::class, AnalyticsService::class);
		$context->registerServiceAlias(AppAssistantServiceInterface::class, AppAssistantService::class);
		$context->registerServiceAlias(AppTemplateOffersInterface::class, AppTemplateOffers::class);
		$context->registerServiceAlias(ApprovalServiceInterface::class, ApprovalService::class);
		$context->registerServiceAlias(ApprovalTaskBridgeInterface::class, ApprovalTaskBridge::class);
		$context->registerServiceAlias(ApprovalVerdictServiceInterface::class, ApprovalVerdictService::class);
		$context->registerServiceAlias(AssistantPromptLibraryInterface::class, AssistantPromptLibrary::class);
		$context->registerServiceAlias(AssistantServiceInterface::class, AssistantService::class);
		$context->registerServiceAlias(AsyncStageDispatchServiceInterface::class, AsyncStageDispatchService::class);
		$context->registerServiceAlias(AttachmentStoreInterface::class, AttachmentStore::class);
		$context->registerServiceAlias(BudgetServiceInterface::class, BudgetService::class);
		$context->registerServiceAlias(ComplianceServiceInterface::class, ComplianceService::class);
		$context->registerServiceAlias(ConnectionReporterInterface::class, ConnectionReporter::class);
		$context->registerServiceAlias(ContextAgentInteractionServiceInterface::class, ContextAgentInteractionService::class);
		$context->registerServiceAlias(ConversationTitleWriterInterface::class, ConversationTitleWriter::class);
		$context->registerServiceAlias(CourseRecommendationEngineInterface::class, CourseRecommendationEngine::class);
		$context->registerServiceAlias(DelegationServiceInterface::class, DelegationService::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(DocumentFeatureRunInterface::class, DocumentFeatureRun::class);
		$context->registerServiceAlias(EffectiveModelPolicyInterface::class, EffectiveModelPolicy::class);
		$context->registerServiceAlias(EngineInterface::class, Engine::class);
		$context->registerServiceAlias(EvalRunServiceInterface::class, EvalRunService::class);
		$context->registerServiceAlias(FlowAgentRunServiceInterface::class, FlowAgentRunService::class);
		$context->registerServiceAlias(ForgeEgressPolicyInterface::class, ForgeEgressPolicy::class);
		$context->registerServiceAlias(GitHubTemplateCatalogServiceInterface::class, GitHubTemplateCatalogService::class);
		$context->registerServiceAlias(GitHubTemplatePushServiceInterface::class, GitHubTemplatePushService::class);
		$context->registerServiceAlias(GoalServiceInterface::class, GoalService::class);
		$context->registerServiceAlias(GovernedMcpEndpointInterface::class, GovernedMcpEndpoint::class);
		$context->registerServiceAlias(GrantWaiverAuditInterface::class, GrantWaiverAudit::class);
		$context->registerServiceAlias(GraphExtractionServiceInterface::class, GraphExtractionService::class);
		$context->registerServiceAlias(GraphToolsInterface::class, GraphTools::class);
		$context->registerServiceAlias(GuardrailPolicyServiceInterface::class, GuardrailPolicyService::class);
		$context->registerServiceAlias(ImageGenerationServiceInterface::class, ImageGenerationService::class);
		$context->registerServiceAlias(InstructionVariablesServiceInterface::class, InstructionVariablesService::class);
		$context->registerServiceAlias(IntakeServiceInterface::class, IntakeService::class);
		$context->registerServiceAlias(IntakeSettingsInterface::class, IntakeSettings::class);
		$context->registerServiceAlias(LessonAuthoringEngineInterface::class, LessonAuthoringEngine::class);
		$context->registerServiceAlias(LiteracyCourseInterface::class, LiteracyCourse::class);
		$context->registerServiceAlias(LiteracyReportInterface::class, LiteracyReport::class);
		$context->registerServiceAlias(LiteracyRequirementInterface::class, LiteracyRequirement::class);
		$context->registerServiceAlias(LlmSettingsHandlerInterface::class, LlmSettingsHandler::class);
		$context->registerServiceAlias(MailReadServiceInterface::class, MailReadService::class);
		$context->registerServiceAlias(MemoryServiceInterface::class, MemoryService::class);
		$context->registerServiceAlias(MessageHistoryHandlerInterface::class, MessageHistoryHandler::class);
		$context->registerServiceAlias(MessageTranslationEngineInterface::class, MessageTranslationEngine::class);
		$context->registerServiceAlias(ModelCapabilityRegistryInterface::class, ModelCapabilityRegistry::class);
		$context->registerServiceAlias(NcNativeWriteServiceInterface::class, NcNativeWriteService::class);
		$context->registerServiceAlias(OrganisationControlServiceInterface::class, OrganisationControlService::class);
		$context->registerServiceAlias(OrganisationModelPolicyServiceInterface::class, OrganisationModelPolicyService::class);
		$context->registerServiceAlias(OrganisationOpsServiceInterface::class, OrganisationOpsService::class);
		$context->registerServiceAlias(OutsideAgentGatewayInterface::class, OutsideAgentGateway::class);
		$context->registerServiceAlias(OutsideToolSurfaceInterface::class, OutsideToolSurface::class);
		$context->registerServiceAlias(ProviderDataUseRegistryInterface::class, ProviderDataUseRegistry::class);
		$context->registerServiceAlias(ProviderFactoryInterface::class, ProviderFactory::class);
		$context->registerServiceAlias(ProviderResidencyRegistryInterface::class, ProviderResidencyRegistry::class);
		$context->registerServiceAlias(PublicationGatewayInterface::class, PublicationGateway::class);
		$context->registerServiceAlias(RecordSummaryServiceInterface::class, RecordSummaryService::class);
		$context->registerServiceAlias(ReportSimilarityServiceInterface::class, ReportSimilarityService::class);
		$context->registerServiceAlias(ReportSimilaritySettingsInterface::class, ReportSimilaritySettings::class);
		$context->registerServiceAlias(RunHistoryServiceInterface::class, RunHistoryService::class);
		$context->registerServiceAlias(RunRetentionCleanerInterface::class, RunRetentionCleaner::class);
		$context->registerServiceAlias(RunRetentionPolicyInterface::class, RunRetentionPolicy::class);
		$context->registerServiceAlias(RunStepBusInterface::class, RunStepBus::class);
		$context->registerServiceAlias(RunTokenServiceInterface::class, RunTokenService::class);
		$context->registerServiceAlias(ScheduleFlowBridgeInterface::class, ScheduleFlowBridge::class);
		$context->registerServiceAlias(ScheduleServiceInterface::class, ScheduleService::class);
		$context->registerServiceAlias(ScheduleWebhookSecretServiceInterface::class, ScheduleWebhookSecretService::class);
		$context->registerServiceAlias(SeedCustodyServiceInterface::class, SeedCustodyService::class);
		$context->registerServiceAlias(SeedFreshnessServiceInterface::class, SeedFreshnessService::class);
		$context->registerServiceAlias(ServerSideWorkspaceProviderInterface::class, ServerSideWorkspaceProvider::class);
		$context->registerServiceAlias(SessionParticipantServiceInterface::class, SessionParticipantService::class);
		$context->registerServiceAlias(SessionPendingApprovalsInterface::class, SessionPendingApprovals::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(SkillBundleInstallerInterface::class, SkillBundleInstaller::class);
		$context->registerServiceAlias(SkillBundleSerializerInterface::class, SkillBundleSerializer::class);
		$context->registerServiceAlias(SkillConsolidationServiceInterface::class, SkillConsolidationService::class);
		$context->registerServiceAlias(SkillLearningsCaptureServiceInterface::class, SkillLearningsCaptureService::class);
		$context->registerServiceAlias(SkillLearningsPromotionServiceInterface::class, SkillLearningsPromotionService::class);
		$context->registerServiceAlias(SkillMarketplaceServiceInterface::class, SkillMarketplaceService::class);
		$context->registerServiceAlias(SkillMaturityServiceInterface::class, SkillMaturityService::class);
		$context->registerServiceAlias(SkillServiceInterface::class, SkillService::class);
		$context->registerServiceAlias(SkillVersionServiceInterface::class, SkillVersionService::class);
		$context->registerServiceAlias(SpeechClientInterface::class, SpeechClient::class);
		$context->registerServiceAlias(StageDispatchServiceInterface::class, StageDispatchService::class);
		$context->registerServiceAlias(TalkAgentBindingInterface::class, TalkAgentBinding::class);
		$context->registerServiceAlias(TalkApprovalBindingInterface::class, TalkApprovalBinding::class);
		$context->registerServiceAlias(TalkBotInstallerInterface::class, TalkBotInstaller::class);
		$context->registerServiceAlias(TalkBridgeStatusInterface::class, TalkBridgeStatus::class);
		$context->registerServiceAlias(TalkMentionMatcherInterface::class, TalkMentionMatcher::class);
		$context->registerServiceAlias(TalkRoomBindingInterface::class, TalkRoomBinding::class);
		$context->registerServiceAlias(TalkRoomGroupingInterface::class, TalkRoomGrouping::class);
		$context->registerServiceAlias(TalkSessionRoomInterface::class, TalkSessionRoom::class);
		$context->registerServiceAlias(TalkTurnDispatcherInterface::class, TalkTurnDispatcher::class);
		$context->registerServiceAlias(TalkTurnServiceInterface::class, TalkTurnService::class);
		$context->registerServiceAlias(TaskWriteServiceInterface::class, TaskWriteService::class);
		$context->registerServiceAlias(TalkMessageToolInterface::class, TalkMessageTool::class);
		$context->registerServiceAlias(ToolAccessRequestServiceInterface::class, ToolAccessRequestService::class);
		$context->registerServiceAlias(ToolLoopInterface::class, ToolLoop::class);
		$context->registerServiceAlias(ToolSearchServiceInterface::class, ToolSearchService::class);
		$context->registerServiceAlias(WebFetchServiceInterface::class, WebFetchService::class);
		$context->registerServiceAlias(WebResearchEgressGuardInterface::class, WebResearchEgressGuard::class);
		$context->registerServiceAlias(WebResearchSettingsHandlerInterface::class, WebResearchSettingsHandler::class);
		$context->registerServiceAlias(WebSearchClientInterface::class, WebSearchClient::class);
		$context->registerServiceAlias(WebhookAgentRunServiceInterface::class, WebhookAgentRunService::class);
		$context->registerServiceAlias(WebhookSecretServiceInterface::class, WebhookSecretService::class);
	}//end register()
}//end class
