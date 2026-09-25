<?php

use CodeIgniter\Router\RouteCollection;

/**
 * Every URL the application answers. Auto-routing is off (Config\Routing), so
 * nothing outside this file is reachable.
 *
 * Protection is declared on the GROUP, not on each route: every /admin route
 * inherits auth + staff + noindex, every /dealer route auth + dealer + noindex.
 * A route added inside a group cannot forget them. Individual routes add the
 * permission they need with can:<permission>, written with dots because
 * CodeIgniter splits filter arguments on colons (see PermissionFilter).
 *
 * @var RouteCollection $routes
 */
$routes->addPlaceholder('uuid', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
$routes->addPlaceholder('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');

// =============================================================================
// Public website — indexable
// =============================================================================
$routes->get('/', 'Site\Home::index');
$routes->get('mattresses', 'Site\Mattresses::index');
$routes->get('mattresses/(:slug)', 'Site\Mattresses::show/$1');
$routes->get('why-polyfix', 'Site\Pages::show/why-polyfix');
$routes->get('about', 'Site\Pages::show/about');
$routes->get('privacy', 'Site\Pages::show/privacy');
$routes->get('terms', 'Site\Pages::show/terms');
$routes->get('warranty', 'Site\Warranty::index');
// Printed QR labels in the field open /warranty/verify?q=<token>. Keep this URL.
$routes->get('warranty/verify', 'Site\Warranty::verify', ['filter' => ['throttle:verify', 'noindex']]);
$routes->get('dealers', 'Site\Dealers::index');
$routes->post('dealers/apply', 'Site\Dealers::apply', ['filter' => 'throttle:form']);
$routes->get('contact', 'Site\Contact::index');
$routes->post('contact', 'Site\Contact::submit', ['filter' => 'throttle:form']);
$routes->get('sitemap.xml', 'Site\Seo::sitemap');
$routes->get('robots.txt', 'Site\Seo::robots');

// Old addresses keep working: search engines and bookmarks follow a 301.
$routes->addRedirect('why-colifees', 'why-polyfix', 301);

// =============================================================================
// Sign-in and account — private, never indexed
// =============================================================================
$routes->group('', ['filter' => 'noindex'], static function (RouteCollection $routes): void {
    $routes->get('admin/login', 'Auth::login/admin');
    $routes->post('admin/login', 'Auth::attempt/admin', ['filter' => 'throttle:login']);
    $routes->get('dealer/login', 'Auth::login/dealer');
    $routes->post('dealer/login', 'Auth::attempt/dealer', ['filter' => 'throttle:login']);
    $routes->addRedirect('login', 'admin/login');
    $routes->get('login/verify', 'Auth::secondFactor');
    $routes->post('login/verify', 'Auth::verifySecondFactor', ['filter' => 'throttle:login']);
    $routes->get('forgot-password', 'Auth::forgot');
    $routes->post('forgot-password', 'Auth::sendReset', ['filter' => 'throttle:reset']);
    $routes->get('reset-password', 'Auth::reset');
    $routes->post('reset-password', 'Auth::doReset', ['filter' => 'throttle:reset']);
    $routes->post('logout', 'Auth::logout');

    $routes->group('account', ['filter' => 'auth'], static function (RouteCollection $routes): void {
        $routes->get('security', 'Account::security');
        $routes->post('password', 'Account::changePassword');
        $routes->post('mfa/start', 'Account::startMfa');
        $routes->post('mfa/confirm', 'Account::confirmMfa');
        $routes->post('mfa/disable', 'Account::disableMfa');
        $routes->post('sessions/revoke-others', 'Account::revokeOthers');
    });

    // Claim photos and videos: authorised per request, never a public URL.
    $routes->get('media/(:uuid)', 'Media::show/$1', ['filter' => 'auth']);
});

// =============================================================================
// Admin console — staff only
// =============================================================================
$routes->group('admin', ['filter' => ['auth', 'staff', 'noindex'], 'namespace' => 'App\Controllers\Admin'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Dashboard::index', ['filter' => 'can:dashboard.view']);

    // --- operations
    $routes->get('mattresses', 'Mattresses::index', ['filter' => 'can:mattress.read']);
    $routes->get('mattresses/(:uuid)', 'Mattresses::show/$1', ['filter' => 'can:mattress.read']);
    $routes->get('mattresses/(:uuid)/label', 'Mattresses::label/$1', ['filter' => 'can:mattress.read']);
    $routes->post('mattresses/(:uuid)/delete', 'Mattresses::delete/$1', ['filter' => 'can:mattress.delete']);
    $routes->get('lookup', 'Mattresses::lookup', ['filter' => 'can:mattress.read']);
    $routes->get('products', 'Products::index', ['filter' => 'can:product.read']);
    $routes->get('products/new', 'Products::new', ['filter' => 'can:product.write']);
    $routes->post('products', 'Products::create', ['filter' => 'can:product.write']);
    $routes->get('products/(:uuid)', 'Products::show/$1', ['filter' => 'can:product.read']);
    $routes->post('products/(:uuid)', 'Products::update/$1', ['filter' => 'can:product.write']);
    $routes->post('products/(:uuid)/status', 'Products::status/$1', ['filter' => 'can:product.publish']);
    $routes->post('products/(:uuid)/variants', 'Products::saveVariant/$1', ['filter' => 'can:product.write']);
    $routes->post('products/(:uuid)/delete', 'Products::delete/$1', ['filter' => 'can:product.delete']);
    $routes->get('batches', 'Batches::index', ['filter' => 'can:batch.read']);
    $routes->post('batches', 'Batches::create', ['filter' => 'can:batch.write']);
    $routes->get('batches/(:uuid)', 'Batches::show/$1', ['filter' => 'can:batch.read']);
    $routes->post('batches/(:uuid)/produce', 'Batches::produce/$1', ['filter' => 'can:mattress.create']);
    $routes->get('dispatches', 'Dispatches::index', ['filter' => 'can:dispatch.read']);
    $routes->get('dispatches/new', 'Dispatches::new', ['filter' => 'can:dispatch.create']);
    $routes->post('dispatches', 'Dispatches::create', ['filter' => 'can:dispatch.create']);
    $routes->get('dispatches/(:uuid)', 'Dispatches::show/$1', ['filter' => 'can:dispatch.read']);
    $routes->post('dispatches/(:uuid)/send', 'Dispatches::send/$1', ['filter' => 'can:dispatch.update']);
    $routes->post('dispatches/(:uuid)/cancel', 'Dispatches::cancel/$1', ['filter' => 'can:dispatch.cancel']);

    // --- warranty
    $routes->get('claims', 'Claims::index', ['filter' => 'can:claim.read']);
    $routes->get('claims/(:uuid)', 'Claims::show/$1', ['filter' => 'can:claim.read']);
    $routes->post('claims/(:uuid)/note', 'Claims::note/$1', ['filter' => 'can:claim.review']);
    $routes->post('claims/(:uuid)/request-information', 'Claims::requestInformation/$1', ['filter' => 'can:claim.review']);
    $routes->post('claims/(:uuid)/inspection', 'Claims::inspection/$1', ['filter' => 'can:claim.review']);
    $routes->post('claims/(:uuid)/media', 'Claims::media/$1', ['filter' => 'can:claim.review']);
    $routes->post('claims/(:uuid)/decision', 'Claims::decide/$1', ['filter' => 'can:claim.decide']);
    $routes->post('claims/(:uuid)/close', 'Claims::close/$1', ['filter' => 'can:claim.decide']);
    $routes->post('claims/(:uuid)/replacement', 'Claims::replacement/$1', ['filter' => 'can:claim.replace']);
    $routes->post('claims/(:uuid)/recompute-risk', 'Claims::recomputeRisk/$1', ['filter' => 'can:risk.view']);
    $routes->get('warranties', 'Warranties::index', ['filter' => 'can:warranty.read']);
    $routes->get('warranties/(:uuid)', 'Warranties::show/$1', ['filter' => 'can:warranty.read']);
    $routes->post('warranties/(:uuid)/void', 'Warranties::void/$1', ['filter' => 'can:warranty.void']);
    $routes->post('warranties/(:uuid)/override', 'Warranties::override/$1', ['filter' => 'can:warranty.void']);
    $routes->get('replacements', 'Warranties::replacements', ['filter' => 'can:claim.read']);

    // --- network and sales
    $routes->get('dealers', 'Dealers::index', ['filter' => 'can:dealer.read']);
    $routes->get('dealers/new', 'Dealers::new', ['filter' => 'can:dealer.write']);
    $routes->post('dealers', 'Dealers::create', ['filter' => 'can:dealer.write']);
    $routes->get('dealers/(:uuid)', 'Dealers::show/$1', ['filter' => 'can:dealer.read']);
    $routes->post('dealers/(:uuid)', 'Dealers::update/$1', ['filter' => 'can:dealer.write']);
    $routes->post('dealers/(:uuid)/status', 'Dealers::status/$1', ['filter' => 'can:dealer.suspend']);
    $routes->get('applications', 'Applications::index', ['filter' => 'can:dealer_application.read']);
    $routes->get('applications/(:uuid)', 'Applications::show/$1', ['filter' => 'can:dealer_application.read']);
    $routes->post('applications/(:uuid)/review', 'Applications::review/$1', ['filter' => 'can:dealer_application.review']);
    $routes->get('sales', 'Sales::index', ['filter' => 'can:sale.read']);
    $routes->get('customers', 'Sales::customers', ['filter' => 'can:customer.read']);
    $routes->get('leads', 'Leads::index', ['filter' => 'can:lead.read']);
    $routes->post('leads/(:uuid)', 'Leads::update/$1', ['filter' => 'can:lead.update']);

    // --- reports
    $routes->get('reports', 'Reports::index', ['filter' => 'can:report.view']);
    $routes->get('reports/(:slug)', 'Reports::show/$1', ['filter' => 'can:report.view']);
    $routes->get('reports/(:slug)/export/(:segment)', 'Reports::export/$1/$2', ['filter' => 'can:report.export']);

    // --- website
    $routes->get('content', 'Content::index', ['filter' => 'can:cms.read']);
    $routes->get('content/products/(:uuid)', 'Content::product/$1', ['filter' => 'can:cms.read']);
    $routes->post('content/products/(:uuid)', 'Content::saveProduct/$1', ['filter' => 'can:cms.write']);
    $routes->get('content/pages/(:slug)', 'Content::page/$1', ['filter' => 'can:cms.read']);
    $routes->post('content/pages/(:slug)', 'Content::savePage/$1', ['filter' => 'can:cms.write']);
    $routes->post('content/faqs', 'Content::saveFaq', ['filter' => 'can:cms.write']);
    $routes->get('seo', 'Seo::index', ['filter' => 'can:seo.read']);
    $routes->post('seo/(:uuid)', 'Seo::save/$1', ['filter' => 'can:seo.write']);

    // --- administration
    $routes->get('users', 'Users::index', ['filter' => 'can:user.read']);
    $routes->get('users/new', 'Users::new', ['filter' => 'can:user.write']);
    $routes->post('users', 'Users::create', ['filter' => 'can:user.write']);
    $routes->get('users/(:uuid)', 'Users::show/$1', ['filter' => 'can:user.read']);
    $routes->post('users/(:uuid)', 'Users::update/$1', ['filter' => 'can:user.write']);
    $routes->post('users/(:uuid)/roles', 'Users::roles/$1', ['filter' => 'can:user.role.assign']);
    $routes->post('users/(:uuid)/force-password-reset', 'Users::forceReset/$1', ['filter' => 'can:user.reset_password']);
    $routes->post('users/(:uuid)/reset-mfa', 'Users::resetMfa/$1', ['filter' => 'can:user.reset_password']);
    $routes->get('roles', 'Users::roleMatrix', ['filter' => 'can:user.read']);
    $routes->get('notifications', 'Notifications::index', ['filter' => 'can:dashboard.view']);
    $routes->post('notifications/(:uuid)/read', 'Notifications::read/$1', ['filter' => 'can:dashboard.view']);
    $routes->get('audit', 'Audit::index', ['filter' => 'can:audit.read']);
    $routes->get('audit/verify', 'Audit::verify', ['filter' => 'can:audit.read']);
    $routes->get('system', 'System::index', ['filter' => 'can:system.health']);
    $routes->get('settings', 'System::settings', ['filter' => 'can:system.settings.read']);
    $routes->post('settings/(:segment)', 'System::saveSetting/$1', ['filter' => 'can:system.settings.write']);
});

// =============================================================================
// Dealer portal — dealership accounts only, built for a phone
// =============================================================================
$routes->group('dealer', ['filter' => ['auth', 'dealer', 'noindex'], 'namespace' => 'App\Controllers\Dealer'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Home::index', ['filter' => 'can:dashboard.view']);
    $routes->get('scan', 'Stock::scan', ['filter' => 'can:mattress.read']);
    $routes->get('inventory', 'Stock::inventory', ['filter' => 'can:mattress.read']);
    $routes->get('incoming', 'Stock::incoming', ['filter' => 'can:dispatch.read']);
    $routes->get('incoming/(:uuid)', 'Stock::consignment/$1', ['filter' => 'can:dispatch.read']);
    $routes->post('incoming/(:uuid)/receive', 'Stock::receive/$1', ['filter' => 'can:receipt.create']);
    $routes->get('sell', 'Sales::new', ['filter' => 'can:sale.create']);
    $routes->post('sell', 'Sales::create', ['filter' => 'can:sale.create']);
    $routes->get('sales', 'Sales::index', ['filter' => 'can:sale.read']);
    $routes->get('claims', 'Claims::index', ['filter' => 'can:claim.read']);
    $routes->get('claims/new', 'Claims::new', ['filter' => 'can:claim.create']);
    $routes->post('claims', 'Claims::create', ['filter' => 'can:claim.create']);
    $routes->get('claims/(:uuid)', 'Claims::show/$1', ['filter' => 'can:claim.read']);
    $routes->post('claims/(:uuid)/media', 'Claims::media/$1', ['filter' => 'can:claim.create']);
    $routes->post('claims/(:uuid)/reply', 'Claims::reply/$1', ['filter' => 'can:claim.create']);
    $routes->get('notifications', 'Home::notifications', ['filter' => 'can:dashboard.view']);
    $routes->post('notifications/(:uuid)/read', 'Home::read/$1', ['filter' => 'can:dashboard.view']);
});
