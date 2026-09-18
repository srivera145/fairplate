<?php

use Keel\App\Controllers\AdminController;
use Keel\App\Controllers\AppController;
use Keel\App\Controllers\AuthController;
use Keel\App\Controllers\DriveController;
use Keel\App\Controllers\KitchenController;
use Keel\App\Controllers\PhoneAuthController;
use Keel\App\Controllers\ActivityController;
use Keel\App\Controllers\ApiFileController;
use Keel\App\Controllers\ApiTokenController;
use Keel\App\Controllers\BillingController;
use Keel\App\Controllers\DashboardController;
use Keel\App\Controllers\DocsController;
use Keel\App\Controllers\FileController;
use Keel\App\Controllers\HealthController;
use Keel\App\Controllers\LlmsTxtController;
use Keel\App\Controllers\ManifestController;
use Keel\App\Controllers\OrganizationController;
use Keel\App\Controllers\RobotsController;
use Keel\App\Controllers\SitemapController;
use Keel\App\Controllers\SuperAdminController;
use Keel\App\Controllers\ThemeController;
use Keel\App\Controllers\StripeWebhookController;
use Keel\App\Controllers\WelcomeController;
use Keel\App\Middleware\AuthMiddleware;
use Keel\App\Middleware\ApiAuthMiddleware;
use Keel\App\Middleware\CsrfMiddleware;
use Keel\App\Middleware\RequireAdmin;
use Keel\App\Middleware\RequireCustomer;
use Keel\App\Middleware\RequireDriver;
use Keel\App\Middleware\RequireRestaurantStaff;
use Keel\App\Middleware\RequireOrgAdminMiddleware;
use Keel\App\Middleware\RequireOrganizationMiddleware;
use Keel\App\Middleware\RequireSuperAdminMiddleware;
use Keel\App\Middleware\ThrottleMiddleware;
use Keel\Core\Env;

/** @var \Keel\Core\Router $router */

$multiTenancyEnabled = (bool) Env::get('MULTI_TENANCY_ENABLED', false);

$router->get('/up', [HealthController::class, 'index']);
$router->get('/manifest.webmanifest', [ManifestController::class, 'index']);
$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/robots.txt', [RobotsController::class, 'index']);
$router->get('/llms.txt', [LlmsTxtController::class, 'index']);

$router->group(['middleware' => [CsrfMiddleware::class]], function ($router) use ($multiTenancyEnabled) {
    $router->get('/', [WelcomeController::class, 'index'], ['sitemap' => true]);
    $router->get('/docs', [DocsController::class, 'index'], ['sitemap' => true]);
    $router->get('/docs/{slug}', [DocsController::class, 'show']);

    $router->group(['middleware' => [ThrottleMiddleware::class]], function ($router) {
        // FairPlate signs in by phone OTP. Keel's email OTP and magic link stay
        // available for the starter's own screens.
        $router->get('/login', [PhoneAuthController::class, 'showLogin'], ['sitemap' => true]);
        $router->post('/auth/phone/request', [PhoneAuthController::class, 'requestOtp']);
        $router->post('/auth/phone/verify', [PhoneAuthController::class, 'verifyOtp']);

        $router->get('/login/email', [AuthController::class, 'showLogin']);
        $router->post('/auth/otp/request', [AuthController::class, 'requestOtp']);
        $router->post('/auth/otp/verify', [AuthController::class, 'verifyOtp']);
        $router->post('/auth/magic/request', [AuthController::class, 'requestMagicLink']);
        $router->get('/auth/magic', [AuthController::class, 'verifyMagicLink']);
    });

    $router->post('/logout', [AuthController::class, 'logout']);

    if ($multiTenancyEnabled) {
        $router->get('/invite/accept', [OrganizationController::class, 'acceptInvite']);
    }

    $router->group(['middleware' => [AuthMiddleware::class]], function ($router) use ($multiTenancyEnabled) {
        $router->post('/settings/theme', [ThemeController::class, 'update']);

        $router->get('/settings/api-tokens', [ApiTokenController::class, 'index']);
        $router->post('/settings/api-tokens', [ApiTokenController::class, 'store']);
        $router->post('/settings/api-tokens/{id}/revoke', [ApiTokenController::class, 'destroy']);

        if ($multiTenancyEnabled) {
            $router->get('/onboarding/organization', [OrganizationController::class, 'showOnboarding']);
            $router->post('/onboarding/organization', [OrganizationController::class, 'createOrganization']);

            $router->group(['middleware' => [RequireOrgAdminMiddleware::class]], function ($router) {
                $router->get('/settings/organization', [OrganizationController::class, 'showSettings']);
                $router->get('/settings/members', [OrganizationController::class, 'showMembers']);
                $router->get('/settings/activity', [ActivityController::class, 'orgIndex']);
                $router->post('/settings/members/invite', [OrganizationController::class, 'sendInvite']);
            });

            $router->group(['middleware' => [RequireSuperAdminMiddleware::class]], function ($router) {
                $router->get('/super-admin/organizations', [SuperAdminController::class, 'index']);
                $router->get('/super-admin/organizations/{id}', [SuperAdminController::class, 'showOrganization']);
                $router->get('/super-admin/activity', [ActivityController::class, 'platformIndex']);
            });
        }

        $applicationMiddleware = $multiTenancyEnabled ? [RequireOrganizationMiddleware::class] : [];

        $router->group(['middleware' => $applicationMiddleware], function ($router) {
            $router->get('/dashboard', [DashboardController::class, 'index']);
            $router->get('/billing/upgrade', [BillingController::class, 'showPlans']);
            $router->get('/billing/success', [BillingController::class, 'success']);
            $router->get('/billing/cancel', [BillingController::class, 'cancel']);
            $router->post('/billing/checkout', [BillingController::class, 'checkout']);
            $router->post('/billing/portal', [BillingController::class, 'portal']);
            $router->post('/files', [FileController::class, 'store']);
            $router->get('/files/{id}', [FileController::class, 'show']);
        });
    });

    // The four FairPlate areas. Every route here carries a role gate; a signed-in
    // user of the wrong role gets a 403, not a redirect.
    $router->group(['prefix' => '/app', 'middleware' => [RequireCustomer::class]], function ($router) {
        $router->get('', [AppController::class, 'index']);
    });

    $router->group(['prefix' => '/kitchen', 'middleware' => [RequireRestaurantStaff::class]], function ($router) {
        $router->get('', [KitchenController::class, 'index']);
    });

    $router->group(['prefix' => '/drive', 'middleware' => [RequireDriver::class]], function ($router) {
        $router->get('', [DriveController::class, 'index']);
    });

    $router->group(['prefix' => '/admin', 'middleware' => [RequireAdmin::class]], function ($router) {
        $router->get('', [AdminController::class, 'index']);
    });
});

$router->group(['prefix' => '/api/v1', 'middleware' => [ThrottleMiddleware::class, ApiAuthMiddleware::class]], function ($router) {
    $router->get('/files', [ApiFileController::class, 'index']);
});

$router->post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
