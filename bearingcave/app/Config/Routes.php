<?php

use CodeIgniter\Router\RouteCollection;

/**
 * BearingCave routes.
 *
 * Areas:
 *   /              public website & marketplace          (App\Controllers\Web)
 *   /buyer/...     buyer dashboard   (filter company:buyer)
 *   /supplier/...  supplier dashboard (filter company:supplier)
 *   /admin/...     enterprise admin  (filter staff + Shield permissions)
 *   /api/v1/...    REST API (Shield access tokens for private endpoints)
 *
 * @var RouteCollection $routes
 */
$routes->setAutoRoute(false);

// --------------------------------------------------------------------
// Authentication (Shield login/logout/magic-link; registration is ours)
// --------------------------------------------------------------------
service('auth')->routes($routes, ['except' => ['register']]);

$routes->group('register', ['namespace' => 'App\Controllers\Web'], static function ($routes) {
    $routes->get('/', 'Register::choose', ['as' => 'register']);
    $routes->get('buyer', 'Register::form/buyer');
    $routes->post('buyer', 'Register::store/buyer');
    $routes->get('supplier', 'Register::form/supplier');
    $routes->post('supplier', 'Register::store/supplier');
});

// --------------------------------------------------------------------
// Public website
// --------------------------------------------------------------------
$routes->group('', ['namespace' => 'App\Controllers\Web'], static function ($routes) {
    $routes->get('/', 'Home::index');
    $routes->get('marketplace', 'Catalog::index');
    $routes->get('category/(:segment)', 'Catalog::category/$1');
    $routes->get('brand/(:segment)', 'Catalog::brand/$1');
    $routes->get('product/(:segment)', 'Product::show/$1');
    $routes->get('search/suggest', 'Catalog::suggest');
    $routes->get('compare', 'Compare::index');
    $routes->post('compare/add/(:num)', 'Compare::add/$1');
    $routes->post('compare/remove/(:num)', 'Compare::remove/$1');
    $routes->post('compare/clear', 'Compare::clear');
    $routes->get('suppliers', 'Suppliers::index');
    $routes->get('suppliers/(:segment)', 'Suppliers::show/$1');
    $routes->get('membership', 'Pages::membership');
    $routes->get('how-it-works', 'Pages::show/how-it-works');
    $routes->get('for-buyers', 'Pages::show/for-buyers');
    $routes->get('for-suppliers', 'Pages::show/for-suppliers');
    $routes->get('services/inspection', 'Pages::show/inspection');
    $routes->get('services/logistics', 'Pages::show/logistics');
    $routes->get('trust-and-verification', 'Pages::show/trust');
    $routes->get('about', 'Pages::show/about');
    $routes->get('page/(:segment)', 'Pages::cms/$1');
    $routes->get('contact', 'Contact::index');
    $routes->post('contact', 'Contact::send');
    $routes->get('sitemap.xml', 'Seo::sitemap');
    $routes->get('robots.txt', 'Seo::robots');
});

// --------------------------------------------------------------------
// Shared signed-in area
// --------------------------------------------------------------------
$routes->group('', ['namespace' => 'App\Controllers\Web', 'filter' => 'session'], static function ($routes) {
    $routes->get('dashboard', 'Dashboard::index');
    $routes->get('documents/(:segment)', 'Documents::download/$1');
    $routes->get('notifications', 'Notifications::index');
    $routes->get('notifications/count', 'Notifications::count');
    $routes->post('notifications/(:num)/read', 'Notifications::read/$1');
    $routes->post('notifications/read-all', 'Notifications::readAll');
    $routes->get('account', 'Account::index');
    $routes->post('account/password', 'Account::password');
    $routes->post('account/tokens', 'Account::createToken');
    $routes->post('account/tokens/(:num)/revoke', 'Account::revokeToken/$1');
    $routes->post('product/(:num)/enquire', 'Product::enquire/$1', ['filter' => 'company:buyer']);
    $routes->post('product/(:num)/save', 'Product::toggleSave/$1', ['filter' => 'company:buyer']);
});

// --------------------------------------------------------------------
// Company area shared by buyers & suppliers (profile, team, verification,
// documents, billing, disputes). Mounted under /buyer and /supplier.
// --------------------------------------------------------------------
foreach (['buyer', 'supplier'] as $area) {
    $routes->group($area, ['namespace' => 'App\Controllers\Account', 'filter' => 'company:' . $area], static function ($routes) {
        $routes->get('company', 'Profile::edit');
        $routes->post('company', 'Profile::update', ['filter' => 'member:company.profile']);
        $routes->get('team', 'Team::index');
        $routes->post('team', 'Team::store', ['filter' => 'member:company.members']);
        $routes->post('team/(:num)', 'Team::update/$1', ['filter' => 'member:company.members']);
        $routes->get('verification', 'Verification::index');
        $routes->post('verification/kyc', 'Verification::saveKyc', ['filter' => 'member:company.verification']);
        $routes->post('verification/directors', 'Verification::addDirector', ['filter' => 'member:company.verification']);
        $routes->post('verification/directors/(:num)/delete', 'Verification::deleteDirector/$1', ['filter' => 'member:company.verification']);
        $routes->post('verification/certifications', 'Verification::addCertification', ['filter' => 'member:company.verification']);
        $routes->post('verification/financial', 'Verification::saveFinancial', ['filter' => 'member:company.verification']);
        $routes->post('verification/quality', 'Verification::saveQuality', ['filter' => 'member:company.verification']);
        $routes->post('verification/documents', 'Verification::uploadDocument', ['filter' => 'member:company.verification']);
        $routes->post('verification/submit', 'Verification::submit', ['filter' => 'member:company.verification']);
        $routes->get('documents', 'Documents::index');
        $routes->get('billing', 'Billing::index', ['filter' => 'member:company.billing']);
        $routes->get('billing/invoices/(:num)', 'Billing::invoice/$1', ['filter' => 'member:company.billing']);
        $routes->post('billing/invoices/(:num)/pay', 'Billing::pay/$1', ['filter' => 'member:company.billing']);
        $routes->post('billing/payments/(:num)/simulate', 'Billing::simulate/$1', ['filter' => 'member:company.billing']);
        $routes->get('disputes', 'Disputes::index');
        $routes->get('disputes/new/(:num)', 'Disputes::new/$1', ['filter' => 'member:disputes.manage']);
        $routes->post('disputes/new/(:num)', 'Disputes::create/$1', ['filter' => 'member:disputes.manage']);
        $routes->get('disputes/(:num)', 'Disputes::show/$1');
        $routes->post('disputes/(:num)/reply', 'Disputes::reply/$1', ['filter' => 'member:disputes.manage']);
        $routes->post('disputes/(:num)/documents', 'Disputes::upload/$1', ['filter' => 'member:disputes.manage']);
        $routes->get('inspections', 'Inspections::index');
        $routes->get('inspections/new', 'Inspections::new', ['filter' => 'member:services.manage']);
        $routes->post('inspections', 'Inspections::create', ['filter' => 'member:services.manage']);
        $routes->get('inspections/(:num)', 'Inspections::show/$1');
        $routes->post('inspections/(:num)/respond', 'Inspections::respond/$1', ['filter' => 'member:services.manage']);
        $routes->get('logistics', 'Logistics::index');
        $routes->get('logistics/new', 'Logistics::new', ['filter' => 'member:services.manage']);
        $routes->post('logistics', 'Logistics::create', ['filter' => 'member:services.manage']);
        $routes->get('logistics/(:num)', 'Logistics::show/$1');
        $routes->post('logistics/(:num)/respond', 'Logistics::respond/$1', ['filter' => 'member:services.manage']);
        $routes->post('logistics/(:num)/book', 'Logistics::book/$1', ['filter' => 'member:services.manage']);
        $routes->post('shipments/(:num)/update', 'Logistics::updateShipment/$1', ['filter' => 'member:services.manage']);
    });
}

// --------------------------------------------------------------------
// Buyer dashboard
// --------------------------------------------------------------------
$routes->group('buyer', ['namespace' => 'App\Controllers\Buyer', 'filter' => 'company:buyer'], static function ($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('dashboard', 'Dashboard::index');
    $routes->get('saved', 'Saved::index');
    $routes->get('enquiries', 'Enquiries::index');
    $routes->get('rfqs', 'Rfqs::index');
    $routes->get('rfqs/new', 'Rfqs::new', ['filter' => 'member:rfq.manage']);
    $routes->post('rfqs', 'Rfqs::create', ['filter' => 'member:rfq.manage']);
    $routes->get('rfqs/(:num)', 'Rfqs::show/$1');
    $routes->post('rfqs/(:num)/cancel', 'Rfqs::cancel/$1', ['filter' => 'member:rfq.manage']);
    $routes->post('quotations/(:num)/shortlist', 'Rfqs::shortlist/$1', ['filter' => 'member:rfq.manage']);
    $routes->post('quotations/(:num)/reject', 'Rfqs::rejectQuote/$1', ['filter' => 'member:rfq.manage']);
    $routes->post('quotations/(:num)/accept', 'Rfqs::acceptQuote/$1', ['filter' => 'member:orders.manage']);
    $routes->post('quotations/(:num)/message', 'Rfqs::message/$1', ['filter' => 'member:rfq.manage']);
    $routes->get('cart', 'Cart::index');
    $routes->post('cart/add/(:num)', 'Cart::add/$1');
    $routes->post('cart/items/(:num)', 'Cart::update/$1');
    $routes->post('cart/items/(:num)/remove', 'Cart::remove/$1');
    $routes->post('cart/rfq', 'Cart::toRfq', ['filter' => 'member:rfq.manage']);
    $routes->get('cart/checkout', 'Cart::checkout', ['filter' => 'member:orders.manage']);
    $routes->post('cart/checkout', 'Cart::placeOrder', ['filter' => 'member:orders.manage']);
    $routes->get('orders', 'Orders::index');
    $routes->get('orders/(:num)', 'Orders::show/$1');
    $routes->post('orders/(:num)/status', 'Orders::status/$1', ['filter' => 'member:orders.manage']);
});

// --------------------------------------------------------------------
// Supplier dashboard
// --------------------------------------------------------------------
$routes->group('supplier', ['namespace' => 'App\Controllers\Supplier', 'filter' => 'company:supplier'], static function ($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('dashboard', 'Dashboard::index');
    $routes->get('membership', 'Membership::index');
    $routes->post('membership/subscribe', 'Membership::subscribe', ['filter' => 'member:company.billing']);
    $routes->post('membership/renew', 'Membership::renew', ['filter' => 'member:company.billing']);
    $routes->get('products', 'Products::index');
    $routes->get('products/export', 'Products::export');
    $routes->get('products/new', 'Products::new', ['filter' => 'member:inventory.manage']);
    $routes->post('products', 'Products::create', ['filter' => 'member:inventory.manage']);
    $routes->get('products/(:num)/edit', 'Products::edit/$1');
    $routes->post('products/(:num)', 'Products::update/$1', ['filter' => 'member:inventory.manage']);
    $routes->post('products/(:num)/submit', 'Products::submit/$1', ['filter' => 'member:inventory.manage']);
    $routes->post('products/(:num)/status', 'Products::status/$1', ['filter' => 'member:inventory.manage']);
    $routes->post('products/(:num)/images', 'Products::uploadImage/$1', ['filter' => 'member:inventory.manage']);
    $routes->post('products/(:num)/images/(:num)/delete', 'Products::deleteImage/$1/$2', ['filter' => 'member:inventory.manage']);
    $routes->post('products/(:num)/documents', 'Products::uploadDocument/$1', ['filter' => 'member:inventory.manage']);
    $routes->post('products/(:num)/lots', 'Products::receive/$1', ['filter' => 'member:inventory.manage']);
    $routes->post('products/(:num)/lots/(:num)/adjust', 'Products::adjust/$1/$2', ['filter' => 'member:inventory.manage']);
    $routes->get('imports', 'Imports::index', ['filter' => 'entitled:bulk_import']);
    $routes->get('imports/template', 'Imports::template');
    $routes->post('imports', 'Imports::upload', ['filter' => ['entitled:bulk_import', 'member:inventory.manage']]);
    $routes->get('imports/(:segment)', 'Imports::show/$1', ['filter' => 'entitled:bulk_import']);
    $routes->post('imports/(:segment)/map', 'Imports::map/$1', ['filter' => ['entitled:bulk_import', 'member:inventory.manage']]);
    $routes->post('imports/(:segment)/confirm', 'Imports::confirm/$1', ['filter' => ['entitled:bulk_import', 'member:inventory.manage']]);
    $routes->get('enquiries', 'Enquiries::index');
    $routes->post('enquiries/(:num)/respond', 'Enquiries::respond/$1', ['filter' => 'member:rfq.manage']);
    $routes->get('rfqs', 'Rfqs::index');
    $routes->get('rfqs/(:num)', 'Rfqs::show/$1');
    $routes->post('rfqs/(:num)/quote', 'Rfqs::quote/$1', ['filter' => ['entitled:supplier_bidding', 'member:rfq.manage']]);
    $routes->post('rfqs/(:num)/decline', 'Rfqs::decline/$1', ['filter' => 'member:rfq.manage']);
    $routes->post('rfqs/(:num)/message', 'Rfqs::message/$1', ['filter' => 'member:rfq.manage']);
    $routes->get('quotations', 'Rfqs::quotations');
    $routes->get('orders', 'Orders::index');
    $routes->get('orders/(:num)', 'Orders::show/$1');
    $routes->post('orders/(:num)/status', 'Orders::status/$1', ['filter' => 'member:orders.manage']);
    $routes->get('analytics', 'Analytics::index');
    $routes->get('visibility', 'Visibility::index');
    $routes->post('visibility', 'Visibility::save', ['filter' => ['entitled:country_visibility_control', 'member:inventory.manage']]);
    $routes->get('account-manager', 'Dashboard::accountManager');
});

// --------------------------------------------------------------------
// Admin
// --------------------------------------------------------------------
$routes->group('admin', ['namespace' => 'App\Controllers\Admin', 'filter' => 'staff'], static function ($routes) {
    $routes->get('/', 'Dashboard::index');

    $routes->group('', ['filter' => 'permission:users.view'], static function ($routes) {
        $routes->get('users', 'Users::index');
        $routes->get('users/(:num)', 'Users::show/$1');
    });
    $routes->post('users/(:num)/toggle', 'Users::toggle/$1', ['filter' => 'permission:users.manage']);
    $routes->post('users/(:num)/temporary-password', 'Users::temporaryPassword/$1', ['filter' => 'permission:users.manage']);
    $routes->get('staff', 'Staff::index', ['filter' => 'permission:staff.manage']);
    $routes->post('staff', 'Staff::store', ['filter' => 'permission:staff.manage']);
    $routes->post('staff/(:num)/groups', 'Staff::groups/$1', ['filter' => 'permission:staff.manage']);

    $routes->get('companies', 'Companies::index', ['filter' => 'permission:companies.view']);
    $routes->get('companies/(:num)', 'Companies::show/$1', ['filter' => 'permission:companies.view']);
    $routes->post('companies/(:num)/suspend', 'Companies::suspend/$1', ['filter' => 'permission:companies.manage']);
    $routes->post('companies/(:num)/reactivate', 'Companies::reactivate/$1', ['filter' => 'permission:companies.manage']);
    $routes->post('companies/(:num)/manager', 'Companies::assignManager/$1', ['filter' => 'permission:companies.manage']);
    $routes->post('companies/(:num)/buyer-access', 'Companies::buyerAccess/$1', ['filter' => 'permission:verification.approve']);
    $routes->post('companies/(:num)/scores', 'Companies::recomputeScores/$1', ['filter' => 'permission:performance.manage']);

    $routes->get('verifications', 'Verifications::index', ['filter' => 'permission:verification.view']);
    $routes->get('verifications/(:num)', 'Verifications::show/$1', ['filter' => 'permission:verification.view']);
    $routes->group('verifications/(:num)', ['filter' => 'permission:verification.review'], static function ($routes) {
        $routes->post('assign', 'Verifications::assign/$1');
        $routes->post('stage', 'Verifications::stage/$1');
        $routes->post('kyc', 'Verifications::kyc/$1');
        $routes->post('financial', 'Verifications::financial/$1');
        $routes->post('risk', 'Verifications::risk/$1');
        $routes->post('quality', 'Verifications::quality/$1');
        $routes->post('audit', 'Verifications::siteAudit/$1');
        $routes->post('sanctions', 'Verifications::sanctions/$1');
        $routes->post('documents/(:num)', 'Verifications::reviewDocument/$1/$2');
        $routes->post('certifications/(:num)', 'Verifications::reviewCertification/$1/$2');
    });
    $routes->post('verifications/(:num)/approve', 'Verifications::approve/$1', ['filter' => 'permission:verification.approve']);
    $routes->post('verifications/(:num)/reject', 'Verifications::reject/$1', ['filter' => 'permission:verification.approve']);

    $routes->get('avl', 'Avl::index', ['filter' => 'permission:avl.manage']);
    $routes->post('avl', 'Avl::store', ['filter' => 'permission:avl.manage']);
    $routes->post('avl/(:num)', 'Avl::update/$1', ['filter' => 'permission:avl.manage']);

    $routes->group('', ['filter' => 'permission:catalog.manage'], static function ($routes) {
        $routes->get('categories', 'Categories::index');
        $routes->post('categories', 'Categories::save');
        $routes->post('categories/(:num)', 'Categories::save/$1');
        $routes->get('brands', 'Brands::index');
        $routes->post('brands', 'Brands::save');
        $routes->post('brands/(:num)', 'Brands::save/$1');
    });

    $routes->get('products', 'Products::index', ['filter' => 'permission:products.review']);
    $routes->get('products/(:num)', 'Products::show/$1', ['filter' => 'permission:products.review']);
    $routes->post('products/(:num)/review', 'Products::review/$1', ['filter' => 'permission:products.review']);
    $routes->post('products/(:num)/status', 'Products::status/$1', ['filter' => 'permission:products.review']);
    $routes->post('products/(:num)/xref/(:num)/verify', 'Products::verifyXref/$1/$2', ['filter' => 'permission:products.review']);
    $routes->get('inventory', 'Inventory::index', ['filter' => 'permission:inventory.manage']);

    $routes->group('imports', ['filter' => 'permission:imports.manage'], static function ($routes) {
        $routes->get('/', 'Imports::index');
        $routes->get('template', 'Imports::template');
        $routes->post('/', 'Imports::upload');
        $routes->get('(:segment)', 'Imports::show/$1');
        $routes->post('(:segment)/map', 'Imports::map/$1');
        $routes->post('(:segment)/confirm', 'Imports::confirm/$1');
    });
    $routes->group('supplier-master', ['filter' => 'permission:imports.manage'], static function ($routes) {
        $routes->get('/', 'SupplierMaster::index');
        $routes->get('template', 'SupplierMaster::template');
        $routes->post('fields/(:num)', 'SupplierMaster::updateField/$1');
        $routes->post('upload', 'SupplierMaster::upload');
        $routes->get('(:segment)', 'SupplierMaster::show/$1');
        $routes->post('(:segment)/map', 'SupplierMaster::map/$1');
        $routes->post('(:segment)/confirm', 'SupplierMaster::confirm/$1');
    });

    $routes->get('rfqs', 'Rfqs::index', ['filter' => 'permission:rfq.view']);
    $routes->get('rfqs/(:num)', 'Rfqs::show/$1', ['filter' => 'permission:rfq.view']);
    $routes->post('rfqs/(:num)/review', 'Rfqs::review/$1', ['filter' => 'permission:rfq.manage']);
    $routes->post('rfqs/(:num)/match', 'Rfqs::match/$1', ['filter' => 'permission:rfq.manage']);
    $routes->post('rfqs/(:num)/distribute', 'Rfqs::distribute/$1', ['filter' => 'permission:rfq.manage']);
    $routes->post('rfqs/(:num)/deadline', 'Rfqs::deadline/$1', ['filter' => 'permission:rfq.manage']);
    $routes->get('quotations', 'Rfqs::quotations', ['filter' => 'permission:rfq.view']);

    $routes->get('orders', 'Orders::index', ['filter' => 'permission:orders.view']);
    $routes->get('orders/(:num)', 'Orders::show/$1', ['filter' => 'permission:orders.view']);
    $routes->post('orders/(:num)/status', 'Orders::status/$1', ['filter' => 'permission:orders.manage']);

    $routes->group('inspections', ['filter' => 'permission:inspection.manage'], static function ($routes) {
        $routes->get('/', 'Inspections::index');
        $routes->get('(:num)', 'Inspections::show/$1');
        $routes->post('(:num)/quote', 'Inspections::quote/$1');
        $routes->post('(:num)/assign', 'Inspections::assign/$1');
        $routes->post('(:num)/start', 'Inspections::start/$1');
        $routes->post('(:num)/report', 'Inspections::report/$1');
        $routes->post('(:num)/complete', 'Inspections::complete/$1');
        $routes->post('(:num)/cancel', 'Inspections::cancel/$1');
    });
    $routes->group('logistics', ['filter' => 'permission:logistics.manage'], static function ($routes) {
        $routes->get('/', 'Logistics::index');
        $routes->get('(:num)', 'Logistics::show/$1');
        $routes->post('(:num)/quote', 'Logistics::quote/$1');
        $routes->post('(:num)/book', 'Logistics::book/$1');
        $routes->post('shipments/(:num)', 'Logistics::updateShipment/$1');
    });

    $routes->get('payments', 'Payments::index', ['filter' => 'permission:payments.view']);
    $routes->get('invoices', 'Payments::invoices', ['filter' => 'permission:payments.view']);
    $routes->get('invoices/(:num)', 'Payments::invoice/$1', ['filter' => 'permission:payments.view']);
    $routes->post('invoices/(:num)/record', 'Payments::record/$1', ['filter' => 'permission:payments.record']);
    $routes->post('invoices/(:num)/void', 'Payments::void/$1', ['filter' => 'permission:payments.record']);
    $routes->get('webhooks', 'Payments::webhooks', ['filter' => 'permission:payments.view']);

    $routes->get('memberships', 'Memberships::index', ['filter' => 'permission:memberships.manage']);
    $routes->get('memberships/(:num)', 'Memberships::edit/$1', ['filter' => 'permission:memberships.manage']);
    $routes->post('memberships/(:num)', 'Memberships::update/$1', ['filter' => 'permission:memberships.manage']);
    $routes->get('subscriptions', 'Memberships::subscriptions', ['filter' => 'permission:subscriptions.manage']);
    $routes->post('subscriptions/run', 'Memberships::runDaily', ['filter' => 'permission:subscriptions.manage']);
    $routes->post('subscriptions/(:num)/cancel', 'Memberships::cancelSubscription/$1', ['filter' => 'permission:subscriptions.manage']);

    $routes->get('performance', 'Performance::index', ['filter' => 'permission:reports.view']);
    $routes->post('performance/recompute', 'Performance::recompute', ['filter' => 'permission:performance.manage']);

    $routes->get('disputes', 'Disputes::index', ['filter' => 'permission:disputes.view']);
    $routes->get('disputes/(:num)', 'Disputes::show/$1', ['filter' => 'permission:disputes.view']);
    $routes->post('disputes/(:num)/assign', 'Disputes::assign/$1', ['filter' => 'permission:disputes.manage']);
    $routes->post('disputes/(:num)/reply', 'Disputes::reply/$1', ['filter' => 'permission:disputes.manage']);
    $routes->post('disputes/(:num)/resolve', 'Disputes::resolve/$1', ['filter' => 'permission:disputes.manage']);

    $routes->get('reports', 'Reports::index', ['filter' => 'permission:reports.view']);
    $routes->get('reports/(:segment)', 'Reports::show/$1', ['filter' => 'permission:reports.view']);
    $routes->get('reports/(:segment)/export', 'Reports::export/$1', ['filter' => 'permission:reports.export']);

    $routes->get('cms', 'Cms::index', ['filter' => 'permission:cms.manage']);
    $routes->get('cms/new', 'Cms::edit', ['filter' => 'permission:cms.manage']);
    $routes->get('cms/(:num)', 'Cms::edit/$1', ['filter' => 'permission:cms.manage']);
    $routes->post('cms', 'Cms::save', ['filter' => 'permission:cms.manage']);
    $routes->post('cms/(:num)', 'Cms::save/$1', ['filter' => 'permission:cms.manage']);

    $routes->get('notifications', 'Outbox::index', ['filter' => 'permission:notifications.manage']);
    $routes->post('notifications/(:num)/retry', 'Outbox::retry/$1', ['filter' => 'permission:notifications.manage']);
    $routes->get('settings', 'Settings::index', ['filter' => 'permission:settings.manage']);
    $routes->post('settings', 'Settings::update', ['filter' => 'permission:settings.manage']);
    $routes->get('seo', 'Settings::seo', ['filter' => 'permission:seo.manage']);
    $routes->post('seo', 'Settings::updateSeo', ['filter' => 'permission:seo.manage']);
    $routes->get('audit', 'Audit::index', ['filter' => 'permission:audit.view']);
    $routes->get('leads', 'Leads::index', ['filter' => 'permission:leads.view']);
    $routes->post('leads/(:num)', 'Leads::update/$1', ['filter' => 'permission:leads.view']);
});

// --------------------------------------------------------------------
// REST API v1
// --------------------------------------------------------------------
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function ($routes) {
    $routes->get('products', 'Products::index');
    $routes->get('products/(:segment)', 'Products::show/$1');
    $routes->get('categories', 'Catalog::categories');
    $routes->get('brands', 'Catalog::brands');
    $routes->get('suggest', 'Catalog::suggest');
    $routes->group('', ['filter' => 'tokens'], static function ($routes) {
        $routes->get('me', 'Account::me');
        $routes->get('rfqs', 'Rfqs::index');
        $routes->get('rfqs/(:num)', 'Rfqs::show/$1');
        $routes->post('rfqs', 'Rfqs::create');
        $routes->get('orders', 'Orders::index');
        $routes->get('notifications', 'Account::notifications');
    });
});
$routes->post('webhooks/payments/(:segment)', 'Api\Webhooks::payments/$1');
