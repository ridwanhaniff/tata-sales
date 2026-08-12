<?php

use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\CalculatorController as AdminCalculatorController;
use App\Http\Controllers\Api\V1\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Api\V1\Admin\ConversationController;
use App\Http\Controllers\Api\V1\Admin\CrmDeliveryController;
use App\Http\Controllers\Api\V1\Admin\CrmWebhookSettingController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\FollowUpStepController;
use App\Http\Controllers\Api\V1\Admin\KnowledgeBaseController;
use App\Http\Controllers\Api\V1\Admin\LandingPageController as AdminLandingPageController;
use App\Http\Controllers\Api\V1\Admin\LeadController as AdminLeadController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\PipelineStageController;
use App\Http\Controllers\Api\V1\Admin\ProductCategoryController as AdminProductCategoryController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\V1\Admin\QuotationController as AdminQuotationController;
use App\Http\Controllers\Api\V1\Admin\SalesDashboardController;
use App\Http\Controllers\Api\V1\Admin\SalesTargetController;
use App\Http\Controllers\Api\V1\Admin\SalesTeamController;
use App\Http\Controllers\Api\V1\Admin\TenantController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VoucherController as AdminVoucherController;
use App\Http\Controllers\Api\V1\Admin\WorkflowController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Public\CalculatorController;
use App\Http\Controllers\Api\V1\Public\ChatController;
use App\Http\Controllers\Api\V1\Public\EventController;
use App\Http\Controllers\Api\V1\Public\LandingPageController;
use App\Http\Controllers\Api\V1\Public\LeadController;
use App\Http\Controllers\Api\V1\Public\ProductController;
use App\Http\Controllers\Api\V1\Public\PromotionController;
use App\Http\Controllers\Api\V1\Public\QuotationTrackingController;
use App\Http\Controllers\Api\V1\Public\VoucherController;
use App\Http\Controllers\Api\V1\Public\WebhookController;
use App\Http\Controllers\Api\V1\Public\WhatsAppController;
use App\Http\Controllers\Api\V1\Public\WhatsAppStatusWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'data' => [
            'service' => 'tata-sales',
            'status' => 'ok',
            'tenant' => app()->bound('currentTenant') ? app('currentTenant')->slug : null,
        ],
    ]);
});

Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::apiResource('/admin/tenants', TenantController::class)->except(['edit', 'create']);
});

Route::middleware(['auth:sanctum', 'role:super_admin,owner'])->group(function () {
    Route::apiResource('/admin/users', UserController::class)->except(['edit', 'create']);
});

Route::middleware(['auth:sanctum', 'role:super_admin,owner,manager,content_manager'])->group(function () {
    Route::get('/admin/products', [AdminProductController::class, 'index']);
    Route::post('/admin/products', [AdminProductController::class, 'store']);
    Route::get('/admin/products/{product}', [AdminProductController::class, 'show']);
    Route::put('/admin/products/{product}', [AdminProductController::class, 'update']);
    Route::delete('/admin/products/{product}', [AdminProductController::class, 'destroy']);
    Route::post('/admin/products/{product}/publish', [AdminProductController::class, 'publish']);
    Route::post('/admin/products/{product}/unpublish', [AdminProductController::class, 'unpublish']);
    Route::post('/admin/products/{product}/images', [AdminProductController::class, 'uploadImages']);
    Route::delete('/admin/products/{product}/images/{image}', [AdminProductController::class, 'deleteImage']);

    Route::get('/admin/product-categories', [AdminProductCategoryController::class, 'index']);
    Route::post('/admin/product-categories', [AdminProductCategoryController::class, 'store']);
    Route::put('/admin/product-categories/{category}', [AdminProductCategoryController::class, 'update']);
    Route::delete('/admin/product-categories/{category}', [AdminProductCategoryController::class, 'destroy']);

    Route::get('/admin/landing-pages', [AdminLandingPageController::class, 'index']);
    Route::post('/admin/landing-pages', [AdminLandingPageController::class, 'store']);
    Route::get('/admin/landing-pages/{landingPage}', [AdminLandingPageController::class, 'show']);
    Route::put('/admin/landing-pages/{landingPage}', [AdminLandingPageController::class, 'update']);
    Route::delete('/admin/landing-pages/{landingPage}', [AdminLandingPageController::class, 'destroy']);
    Route::post('/admin/landing-pages/{landingPage}/publish', [AdminLandingPageController::class, 'publish']);
    Route::post('/admin/landing-pages/{landingPage}/unpublish', [AdminLandingPageController::class, 'unpublish']);
    Route::get('/admin/landing-pages/{landingPage}/sections', [AdminLandingPageController::class, 'sections']);
    Route::post('/admin/landing-pages/{landingPage}/sections', [AdminLandingPageController::class, 'storeSection']);
    Route::put('/admin/landing-pages/{landingPage}/sections/reorder', [AdminLandingPageController::class, 'reorder']);
    Route::put('/admin/landing-pages/{landingPage}/sections/{section}', [AdminLandingPageController::class, 'updateSection']);
    Route::delete('/admin/landing-pages/{landingPage}/sections/{section}', [AdminLandingPageController::class, 'destroySection']);

    Route::get('/admin/promotions', [AdminPromotionController::class, 'index']);
    Route::post('/admin/promotions', [AdminPromotionController::class, 'store']);
    Route::get('/admin/promotions/{promotion}', [AdminPromotionController::class, 'show']);
    Route::put('/admin/promotions/{promotion}', [AdminPromotionController::class, 'update']);
    Route::delete('/admin/promotions/{promotion}', [AdminPromotionController::class, 'destroy']);
    Route::post('/admin/promotions/{promotion}/vouchers/generate', [AdminVoucherController::class, 'generate']);

    Route::get('/admin/vouchers', [AdminVoucherController::class, 'index']);

    Route::get('/admin/campaigns', [AdminCampaignController::class, 'index']);
    Route::post('/admin/campaigns', [AdminCampaignController::class, 'store']);
    Route::get('/admin/campaigns/{campaign}', [AdminCampaignController::class, 'show']);
    Route::put('/admin/campaigns/{campaign}', [AdminCampaignController::class, 'update']);
    Route::delete('/admin/campaigns/{campaign}', [AdminCampaignController::class, 'destroy']);

    Route::get('/admin/calculators', [AdminCalculatorController::class, 'index']);
    Route::post('/admin/calculators', [AdminCalculatorController::class, 'store']);
    Route::get('/admin/calculators/{calculator}', [AdminCalculatorController::class, 'show']);
    Route::put('/admin/calculators/{calculator}', [AdminCalculatorController::class, 'update']);
    Route::delete('/admin/calculators/{calculator}', [AdminCalculatorController::class, 'destroy']);

    Route::get('/admin/knowledge', [KnowledgeBaseController::class, 'index']);
    Route::post('/admin/knowledge', [KnowledgeBaseController::class, 'store']);
    Route::get('/admin/knowledge/{article}', [KnowledgeBaseController::class, 'show']);
    Route::put('/admin/knowledge/{article}', [KnowledgeBaseController::class, 'update']);
    Route::delete('/admin/knowledge/{article}', [KnowledgeBaseController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:owner,manager,sales'])->group(function () {
    Route::get('/admin/leads', [AdminLeadController::class, 'index']);
    Route::get('/admin/leads/{lead}', [AdminLeadController::class, 'show']);
    Route::put('/admin/leads/{lead}', [AdminLeadController::class, 'update']);
    Route::post('/admin/leads/{lead}/assign', [AdminLeadController::class, 'assign']);
    Route::post('/admin/leads/{lead}/notes', [AdminLeadController::class, 'notes']);
    Route::get('/admin/sales/dashboard', [SalesDashboardController::class, 'dashboard']);

    Route::get('/admin/quotes', [AdminQuotationController::class, 'index']);
    Route::post('/admin/quotes', [AdminQuotationController::class, 'store']);
    Route::get('/admin/quotes/{quotation}', [AdminQuotationController::class, 'show']);
    Route::post('/admin/quotes/{quotation}/send', [AdminQuotationController::class, 'send']);
    Route::delete('/admin/quotes/{quotation}', [AdminQuotationController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:owner,manager'])->group(function () {
    Route::get('/admin/analytics/summary', [AnalyticsController::class, 'summary']);
    Route::get('/admin/analytics/funnel', [AnalyticsController::class, 'funnel']);
    Route::get('/admin/analytics/response-time', [AnalyticsController::class, 'responseTime']);
    Route::get('/admin/analytics/win-rate', [AnalyticsController::class, 'winRate']);
    Route::get('/admin/analytics/pipeline', [AnalyticsController::class, 'pipeline']);
    Route::get('/admin/analytics/campaign-roi', [AnalyticsController::class, 'campaignRoi']);

    Route::get('/admin/pipeline-stages', [PipelineStageController::class, 'index']);
    Route::post('/admin/pipeline-stages', [PipelineStageController::class, 'store']);
    Route::put('/admin/pipeline-stages/{stage}', [PipelineStageController::class, 'update']);
    Route::delete('/admin/pipeline-stages/{stage}', [PipelineStageController::class, 'destroy']);

    Route::get('/admin/customers', [CustomerController::class, 'index']);
    Route::get('/admin/customers/{customer}', [CustomerController::class, 'show']);

    Route::get('/admin/workflows', [WorkflowController::class, 'index']);
    Route::post('/admin/workflows', [WorkflowController::class, 'store']);
    Route::get('/admin/workflows/{workflow}', [WorkflowController::class, 'show']);
    Route::put('/admin/workflows/{workflow}', [WorkflowController::class, 'update']);
    Route::delete('/admin/workflows/{workflow}', [WorkflowController::class, 'destroy']);

    Route::get('/admin/followup-steps', [FollowUpStepController::class, 'index']);
    Route::post('/admin/followup-steps', [FollowUpStepController::class, 'store']);
    Route::put('/admin/followup-steps/{step}', [FollowUpStepController::class, 'update']);
    Route::delete('/admin/followup-steps/{step}', [FollowUpStepController::class, 'destroy']);

    Route::get('/admin/sales/teams', [SalesTeamController::class, 'index']);
    Route::post('/admin/sales/teams', [SalesTeamController::class, 'store']);
    Route::put('/admin/sales/teams/{team}', [SalesTeamController::class, 'update']);
    Route::delete('/admin/sales/teams/{team}', [SalesTeamController::class, 'destroy']);

    Route::get('/admin/sales/targets', [SalesTargetController::class, 'index']);
    Route::post('/admin/sales/targets', [SalesTargetController::class, 'store']);
    Route::put('/admin/sales/targets/{target}', [SalesTargetController::class, 'update']);
    Route::delete('/admin/sales/targets/{target}', [SalesTargetController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:owner,manager,sales'])->group(function () {
    Route::get('/admin/notifications', [NotificationController::class, 'index']);
    Route::post('/admin/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/admin/conversations', [ConversationController::class, 'index']);
    Route::get('/admin/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/admin/conversations/{conversation}/reply', [ConversationController::class, 'reply']);
    Route::post('/admin/conversations/{conversation}/handoff', [ConversationController::class, 'handoff']);
});

Route::middleware(['auth:sanctum', 'role:owner,manager'])->group(function () {
    Route::post('/admin/notifications', [NotificationController::class, 'send']);
});

Route::middleware(['auth:sanctum', 'role:super_admin,owner,manager'])->group(function () {
    Route::get('/admin/settings/webhook', [CrmWebhookSettingController::class, 'show']);
    Route::put('/admin/settings/webhook', [CrmWebhookSettingController::class, 'update']);
    Route::post('/admin/settings/webhook/test', [CrmWebhookSettingController::class, 'test']);

    Route::get('/admin/crm/deliveries', [CrmDeliveryController::class, 'index']);
    Route::get('/admin/crm/deliveries/{delivery}', [CrmDeliveryController::class, 'show']);
});

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/landing-pages/{slug}', [LandingPageController::class, 'show']);
Route::get('/promotions/active', [PromotionController::class, 'active']);
Route::post('/events', [EventController::class, 'track']);
Route::post('/calculators/{calculator}/calculate', [CalculatorController::class, 'calculate']);
Route::middleware('throttle:leads')->group(function () {
    Route::post('/leads', [LeadController::class, 'store']);
});
Route::middleware('throttle:chat')->group(function () {
    Route::post('/chat/message', [ChatController::class, 'message']);
});
Route::post('/whatsapp/context', [WhatsAppController::class, 'context']);
Route::post('/vouchers/redeem', [VoucherController::class, 'redeem']);
Route::middleware('throttle:quotes')->group(function () {
    Route::get('/quotes/{token}', [QuotationTrackingController::class, 'show']);
    Route::post('/quotes/{token}/respond', [QuotationTrackingController::class, 'respond']);
});

Route::post('/webhooks/whatsapp', [WebhookController::class, 'whatsapp']);
Route::post('/webhooks/whatsapp-status', [WhatsAppStatusWebhookController::class, 'handle']);
Route::post('/webhooks/payment', [WebhookController::class, 'payment']);
Route::post('/webhooks/crm', [WebhookController::class, 'crm']);
