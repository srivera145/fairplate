<?php

use Keel\App\Controllers\AdminController;
use Keel\App\Controllers\App\AddressesController;
use Keel\App\Controllers\App\BrowseController;
use Keel\App\Controllers\App\CartController;
use Keel\App\Controllers\App\CheckoutController;
use Keel\App\Controllers\App\MembershipController;
use Keel\App\Controllers\App\MenuController as AppMenuController;
use Keel\App\Controllers\App\OrdersController as AppOrdersController;
use Keel\App\Controllers\App\TipController;
use Keel\App\Controllers\AuthController;
use Keel\App\Controllers\Drive\DeliveryController as DriveDeliveryController;
use Keel\App\Controllers\Drive\EarningsController as DriveEarningsController;
use Keel\App\Controllers\Drive\HomeController as DriveHomeController;
use Keel\App\Controllers\Drive\LocationController as DriveLocationController;
use Keel\App\Controllers\Drive\OffersController as DriveOffersController;
use Keel\App\Controllers\Drive\OnboardingController as DriveOnboardingController;
use Keel\App\Controllers\Kitchen\BillingController as KitchenBillingController;
use Keel\App\Controllers\Kitchen\ConnectController as KitchenConnectController;
use Keel\App\Controllers\Kitchen\HoursController as KitchenHoursController;
use Keel\App\Controllers\Kitchen\MenuController as KitchenMenuController;
use Keel\App\Controllers\Kitchen\OnboardingController as KitchenOnboardingController;
use Keel\App\Controllers\Kitchen\OrdersController as KitchenOrdersController;
use Keel\App\Controllers\Kitchen\SpecialsController as KitchenSpecialsController;
use Keel\App\Controllers\Kitchen\StaffController as KitchenStaffController;
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
use Keel\App\Controllers\Webhooks\StripeController as StripeWebhookController;
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
    //
    // The customer app. RequireCustomer answers "is this a customer"; every route
    // carrying a record id then asks App\CustomerController whether it is *their*
    // record, because addresses, carts and orders all live in tables shared by
    // every customer on the platform.
    $router->group(['prefix' => '/app', 'middleware' => [RequireCustomer::class]], function ($router) {
        $router->get('', [BrowseController::class, 'index']);

        $router->get('/addresses', [AddressesController::class, 'index']);
        $router->post('/addresses', [AddressesController::class, 'store']);
        $router->get('/addresses/{id}/edit', [AddressesController::class, 'edit']);
        $router->post('/addresses/{id}', [AddressesController::class, 'update']);
        $router->post('/addresses/{id}/delete', [AddressesController::class, 'destroy']);
        $router->post('/addresses/{id}/default', [AddressesController::class, 'makeDefault']);

        $router->get('/r/{slug}', [AppMenuController::class, 'show']);
        $router->get('/r/{slug}/items/{id}', [AppMenuController::class, 'item']);

        $router->get('/cart', [CartController::class, 'index']);
        $router->post('/cart/items', [CartController::class, 'store']);
        $router->post('/cart/items/{id}', [CartController::class, 'updateLine']);
        $router->post('/cart/items/{id}/delete', [CartController::class, 'destroyLine']);
        $router->post('/cart/clear', [CartController::class, 'clear']);
        $router->post('/cart/address', [CartController::class, 'setAddress']);

        $router->get('/checkout', [CheckoutController::class, 'index']);
        $router->post('/checkout/quote', [CheckoutController::class, 'quote']);
        $router->get('/checkout/complete', [CheckoutController::class, 'complete']);

        $router->get('/orders', [AppOrdersController::class, 'index']);
        $router->get('/orders/{id}', [AppOrdersController::class, 'show']);
        $router->get('/orders/{id}/status', [AppOrdersController::class, 'status']);
        $router->get('/orders/{id}/driver-location', [AppOrdersController::class, 'driverLocation']);
        $router->post('/orders/{id}/reorder', [AppOrdersController::class, 'reorder']);
        $router->post('/orders/{id}/tip', [TipController::class, 'store']);

        $router->get('/membership', [MembershipController::class, 'index']);
        $router->post('/membership/subscribe', [MembershipController::class, 'subscribe']);
        $router->post('/membership/portal', [MembershipController::class, 'portal']);
    });

    // The kitchen app. RequireRestaurantStaff answers "is this a kitchen user";
    // every route below then asks Kitchen\KitchenController whether it is *their*
    // kitchen, because these URLs carry record ids from a table shared by every
    // restaurant on the platform.
    $router->group(['prefix' => '/kitchen', 'middleware' => [RequireRestaurantStaff::class]], function ($router) {
        $router->get('', [KitchenOrdersController::class, 'index']);
        $router->get('/orders/feed', [KitchenOrdersController::class, 'feed']);
        $router->post('/orders/{id}/accept', [KitchenOrdersController::class, 'accept']);
        $router->post('/orders/{id}/reject', [KitchenOrdersController::class, 'reject']);
        $router->post('/orders/{id}/ready', [KitchenOrdersController::class, 'ready']);
        // Billing. /month is where the projection panel lived before there was
        // anything to bill, and a tablet that bookmarked it should land on the
        // page that replaced it.
        $router->get('/month', [KitchenBillingController::class, 'month']);
        $router->get('/billing', [KitchenBillingController::class, 'index']);
        $router->get('/statements', [KitchenBillingController::class, 'statements']);
        $router->post('/billing/method/start', [KitchenBillingController::class, 'startPaymentMethod']);
        $router->get('/billing/method', [KitchenBillingController::class, 'paymentMethodForm']);
        $router->get('/billing/method/complete', [KitchenBillingController::class, 'completePaymentMethod']);

        $router->get('/onboarding', [KitchenOnboardingController::class, 'show']);
        $router->post('/onboarding', [KitchenOnboardingController::class, 'save']);
        $router->post('/onboarding/photo/{kind}', [KitchenOnboardingController::class, 'uploadPhoto']);
        $router->post('/restaurant/select', [KitchenOnboardingController::class, 'selectRestaurantAction']);

        $router->post('/connect/start', [KitchenConnectController::class, 'start']);
        $router->get('/connect/refresh', [KitchenConnectController::class, 'refresh']);
        $router->get('/connect/return', [KitchenConnectController::class, 'return']);

        $router->get('/menu', [KitchenMenuController::class, 'index']);
        $router->post('/menu/sort', [KitchenMenuController::class, 'sort']);
        $router->post('/menu/{type}/{id}/move', [KitchenMenuController::class, 'move']);
        $router->post('/menu/categories', [KitchenMenuController::class, 'storeCategory']);
        $router->post('/menu/categories/{id}', [KitchenMenuController::class, 'updateCategory']);
        $router->post('/menu/categories/{id}/delete', [KitchenMenuController::class, 'destroyCategory']);
        $router->post('/menu/items', [KitchenMenuController::class, 'storeItem']);
        $router->get('/menu/items/{id}', [KitchenMenuController::class, 'editItem']);
        $router->post('/menu/items/{id}', [KitchenMenuController::class, 'updateItem']);
        $router->post('/menu/items/{id}/delete', [KitchenMenuController::class, 'destroyItem']);
        $router->post('/menu/items/{id}/stock', [KitchenMenuController::class, 'toggleStock']);
        $router->post('/menu/items/{id}/photo', [KitchenMenuController::class, 'uploadItemPhoto']);
        $router->post('/menu/items/{id}/photo/delete', [KitchenMenuController::class, 'removeItemPhoto']);
        $router->post('/menu/items/{id}/groups', [KitchenMenuController::class, 'storeGroup']);
        $router->post('/menu/groups/{id}', [KitchenMenuController::class, 'updateGroup']);
        $router->post('/menu/groups/{id}/delete', [KitchenMenuController::class, 'destroyGroup']);
        $router->post('/menu/groups/{id}/options', [KitchenMenuController::class, 'storeOption']);
        $router->post('/menu/options/{id}', [KitchenMenuController::class, 'updateOption']);
        $router->post('/menu/options/{id}/delete', [KitchenMenuController::class, 'destroyOption']);

        $router->get('/specials', [KitchenSpecialsController::class, 'index']);
        $router->post('/specials', [KitchenSpecialsController::class, 'store']);
        $router->post('/specials/{id}', [KitchenSpecialsController::class, 'update']);
        $router->post('/specials/{id}/delete', [KitchenSpecialsController::class, 'destroy']);

        $router->get('/hours', [KitchenHoursController::class, 'index']);
        $router->post('/hours', [KitchenHoursController::class, 'save']);
        $router->post('/pause', [KitchenHoursController::class, 'pause']);
        $router->post('/resume', [KitchenHoursController::class, 'resume']);

        $router->get('/staff', [KitchenStaffController::class, 'index']);
        $router->post('/staff', [KitchenStaffController::class, 'invite']);
        $router->post('/staff/{id}/delete', [KitchenStaffController::class, 'remove']);
    });

    // The driver app. RequireDriver answers "is this a driver"; every route
    // below that carries a record id then asks Drive\DriverController whether it
    // is *theirs*, because offers and orders both live in tables shared by every
    // driver on the platform. A no is a 403 rather than the customer app's 404:
    // a driver who hesitated over a card and followed a stale link should be
    // told plainly that somebody else took it.
    $router->group(['prefix' => '/drive', 'middleware' => [RequireDriver::class]], function ($router) {
        $router->get('', [DriveHomeController::class, 'index']);
        $router->post('/online', [DriveHomeController::class, 'setOnline']);

        $router->get('/onboarding', [DriveOnboardingController::class, 'show']);
        $router->post('/onboarding', [DriveOnboardingController::class, 'save']);
        $router->post('/connect/start', [DriveOnboardingController::class, 'startConnect']);
        $router->get('/connect/refresh', [DriveOnboardingController::class, 'refreshConnect']);
        $router->get('/connect/return', [DriveOnboardingController::class, 'returnFromConnect']);

        // /offers/current is registered before /offers/{id} on purpose: routes
        // match in the order they are declared, and "current" is a word, not an
        // id.
        $router->get('/offers/current', [DriveOffersController::class, 'current']);
        $router->get('/offers/{id}', [DriveOffersController::class, 'show']);
        $router->post('/offers/{id}/accept', [DriveOffersController::class, 'accept']);
        $router->post('/offers/{id}/decline', [DriveOffersController::class, 'decline']);

        $router->get('/orders/{id}', [DriveDeliveryController::class, 'show']);
        $router->post('/orders/{id}/arrived-restaurant', [DriveDeliveryController::class, 'arrivedAtRestaurant']);
        $router->post('/orders/{id}/picked-up', [DriveDeliveryController::class, 'pickedUp']);
        $router->post('/orders/{id}/arrived-customer', [DriveDeliveryController::class, 'arrivedAtCustomer']);
        $router->post('/orders/{id}/delivered', [DriveDeliveryController::class, 'delivered']);

        $router->get('/earnings', [DriveEarningsController::class, 'index']);

        $router->post('/location', [DriveLocationController::class, 'store']);
    });

    $router->group(['prefix' => '/admin', 'middleware' => [RequireAdmin::class]], function ($router) {
        $router->get('', [AdminController::class, 'index']);
        $router->post('/drivers/{id}/approve', [AdminController::class, 'approveDriver']);
        $router->post('/orders/{id}/refund', [AdminController::class, 'refundOrder']);
        $router->post('/payouts/{id}/retry', [AdminController::class, 'retryPayout']);
    });
});

$router->group(['prefix' => '/api/v1', 'middleware' => [ThrottleMiddleware::class, ApiAuthMiddleware::class]], function ($router) {
    $router->get('/files', [ApiFileController::class, 'index']);
});

$router->post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
