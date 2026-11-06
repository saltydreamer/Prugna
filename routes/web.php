<?php

use App\Enums\Permission;
use App\Http\Controllers\Accounting\AccountingSettingsController;
use App\Http\Controllers\Accounting\CashController;
use App\Http\Controllers\Accounting\ProfitAndLossController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SendPasswordResetLinkController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Catalog\CatalogSettingsController;
use App\Http\Controllers\Catalog\CustomerPriceController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentImportController;
use App\Http\Controllers\Field\FieldController;
use App\Http\Controllers\Interventions\InterventionController;
use App\Http\Controllers\Interventions\InterventionDocumentController;
use App\Http\Controllers\Interventions\InterventionInvoicedOutsideController;
use App\Http\Controllers\Interventions\InterventionSettingsController;
use App\Http\Controllers\Interventions\ServiceContractController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Profile\AvatarController;
use App\Http\Controllers\Profile\BrowserSessionController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\PushSubscriptionController;
use App\Http\Controllers\PublicArea\PublicQuoteController;
use App\Http\Controllers\Purchases\ExpenseController;
use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Http\Controllers\Purchases\PurchaseOrderDocumentController;
use App\Http\Controllers\Purchases\ReceivedInvoiceController;
use App\Http\Controllers\Pwa\PwaController;
use App\Http\Controllers\Registry\ContactController;
use App\Http\Controllers\Registry\LocationController;
use App\Http\Controllers\Registry\OrganizationController;
use App\Http\Controllers\Sales\EstimateController;
use App\Http\Controllers\Sales\InterventionInvoiceController;
use App\Http\Controllers\Sales\InvoiceController;
use App\Http\Controllers\Sales\InvoiceDocumentController;
use App\Http\Controllers\Sales\InvoiceSdiController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Sales\OrderDocumentController;
use App\Http\Controllers\Sales\QuoteController;
use App\Http\Controllers\Sales\QuoteDocumentController;
use App\Http\Controllers\Sales\QuoteLookupController;
use App\Http\Controllers\Sales\SubscriptionController;
use App\Http\Controllers\Settings\DolibarrImportController;
use App\Http\Controllers\Settings\ElectronicInvoicingController;
use App\Http\Controllers\Settings\PaymentTermController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

/*
| Authentication routes (login, register, password reset, email verification,
| two factor authentication, profile information and password update) are
| registered by Laravel Fortify: see config/fortify.php.
*/

Route::redirect('/', '/dashboard');

// Installable app: manifest and service worker (public, no session needed).
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');

// Quote shared with the customer: no login, the unguessable token is the key.
Route::prefix('q/{token}')->name('quotes.public.')->middleware('throttle:public-quote')->controller(PublicQuoteController::class)->group(function () {
    Route::get('/', 'show')->name('show');
    Route::get('pdf', 'pdf')->name('pdf');
    Route::post('accept', 'accept')->middleware('throttle:public-quote-answer')->name('accept');
    Route::post('reject', 'reject')->middleware('throttle:public-quote-answer')->name('reject');
});

Route::middleware('auth')->group(function () {
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('preferences', [ProfileController::class, 'updatePreferences'])->name('preferences.update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
        Route::post('avatar', [AvatarController::class, 'update'])->name('avatar.update');
        Route::delete('avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');
        Route::delete('sessions', [BrowserSessionController::class, 'destroy'])->name('sessions.destroy');
        Route::get('push/key', [PushSubscriptionController::class, 'key'])->name('push.key');
        Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
        Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
        Route::post('push/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:5,1')->name('push.test');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('read-all', 'markAllAsRead')->name('read-all');
        Route::delete('read', 'destroyRead')->name('destroy-read');
        Route::patch('{notification}/read', 'markAsRead')->name('read');
        Route::patch('{notification}/unread', 'markAsUnread')->name('unread');
        Route::delete('{notification}', 'destroy')->name('destroy');
    });

    Route::prefix('registry')->name('registry.')->group(function () {
        // Customers and suppliers are separate registries sharing the organization pages.
        Route::get('customers', [OrganizationController::class, 'index'])->defaults('role', 'customer')->name('customers.index');
        Route::get('customers/create', [OrganizationController::class, 'create'])->defaults('role', 'customer')->name('customers.create');
        Route::get('suppliers', [OrganizationController::class, 'index'])->defaults('role', 'supplier')->name('suppliers.index');
        Route::get('suppliers/create', [OrganizationController::class, 'create'])->defaults('role', 'supplier')->name('suppliers.create');
        Route::get('organizations', RedirectController::class)->defaults('destination', '/registry/customers')->defaults('status', 302);

        Route::post('organizations/quick', [OrganizationController::class, 'quickStore'])->name('organizations.quick-store');
        Route::resource('organizations', OrganizationController::class)->except(['index', 'create', 'show']);
        // Archived organizations stay viewable (read-only) and restorable.
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])
            ->name('organizations.show')
            ->withTrashed();
        Route::patch('organizations/{organization}/restore', [OrganizationController::class, 'restore'])
            ->name('organizations.restore')
            ->withTrashed();

        // Scoped bindings: a location/contact resolves only under its own organization.
        Route::scopeBindings()->group(function () {
            Route::resource('organizations.locations', LocationController::class)->only(['store', 'update', 'destroy']);
            Route::resource('organizations.contacts', ContactController::class)->only(['store', 'update', 'destroy']);
        });

        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    });

    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::resource('products', ProductController::class);
        Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');

        Route::post('customer-prices', [CustomerPriceController::class, 'store'])->name('customer-prices.store');
        Route::put('customer-prices/{customerPrice}', [CustomerPriceController::class, 'update'])->name('customer-prices.update');
        Route::delete('customer-prices/{customerPrice}', [CustomerPriceController::class, 'destroy'])->name('customer-prices.destroy');

        Route::controller(CatalogSettingsController::class)->prefix('settings')->name('settings.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('categories', 'storeCategory')->name('categories.store');
            Route::put('categories/{category}', 'updateCategory')->name('categories.update');
            Route::delete('categories/{category}', 'destroyCategory')->name('categories.destroy');
            Route::post('vat-rates', 'storeVatRate')->name('vat-rates.store');
            Route::put('vat-rates/{vatRate}', 'updateVatRate')->name('vat-rates.update');
            Route::delete('vat-rates/{vatRate}', 'destroyVatRate')->name('vat-rates.destroy');
        });
    });

    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('quotes/lookup/organizations/{organization}', [QuoteLookupController::class, 'organization'])->name('quotes.lookup.organization');
        Route::get('quotes/lookup/products', [QuoteLookupController::class, 'products'])->name('quotes.lookup.products');

        Route::resource('quotes', QuoteController::class);
        Route::controller(QuoteController::class)->prefix('quotes/{quote}')->name('quotes.')->group(function () {
            Route::post('duplicate', 'duplicate')->name('duplicate');
            Route::post('reopen', 'reopen')->name('reopen');
            Route::post('mark-sent', 'markSent')->name('mark-sent');
            Route::post('decide', 'decide')->name('decide');
        });
        Route::controller(QuoteDocumentController::class)->prefix('quotes/{quote}')->name('quotes.')->group(function () {
            Route::get('pdf', 'pdf')->name('pdf');
            Route::get('compose', 'compose')->name('compose');
            Route::post('send', 'send')->middleware('throttle:10,1')->name('send');
        });

        Route::post('quotes/{quote}/order', [OrderController::class, 'storeFromQuote'])->name('quotes.order');
        Route::post('quotes/{quote}/invoice', [InvoiceController::class, 'storeFromQuote'])->name('quotes.invoice');

        Route::resource('orders', OrderController::class);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
        Route::get('orders/{order}/pdf', [OrderDocumentController::class, 'pdf'])->name('orders.pdf');
        Route::post('orders/{order}/invoice', [InvoiceController::class, 'storeFromOrder'])->name('orders.invoice');

        Route::post('estimates/read-link', [EstimateController::class, 'readLink'])->middleware('throttle:20,1')->name('estimates.read-link');
        Route::post('estimates/{estimate}/advantages', [EstimateController::class, 'advantages'])->middleware('throttle:10,1')->name('estimates.advantages');
        Route::get('estimates/{estimate}/pdf', [EstimateController::class, 'pdf'])->name('estimates.pdf');
        Route::post('estimates/{estimate}/accept', [EstimateController::class, 'accept'])->name('estimates.accept');
        Route::resource('estimates', EstimateController::class)->except(['edit']);

        Route::get('subscriptions/due', [SubscriptionController::class, 'due'])->name('subscriptions.due');
        Route::post('subscriptions/invoices', [SubscriptionController::class, 'generate'])->name('subscriptions.generate');
        Route::resource('subscriptions', SubscriptionController::class);

        Route::get('invoices/lookup/costs', [InvoiceController::class, 'costs'])->name('invoices.lookup.costs');
        Route::post('invoices/lookup/cost-lines', [InvoiceController::class, 'costLines'])->name('invoices.lookup.cost-lines');
        Route::post('invoices/from-costs', [InvoiceController::class, 'storeFromCosts'])->name('invoices.from-costs');
        Route::get('invoices/lookup/interventions', [InterventionInvoiceController::class, 'index'])->name('invoices.lookup.interventions');
        Route::post('invoices/lookup/intervention-lines', [InterventionInvoiceController::class, 'lines'])->name('invoices.lookup.intervention-lines');
        Route::post('invoices/from-interventions', [InterventionInvoiceController::class, 'store'])->name('invoices.from-interventions');
        Route::resource('invoices', InvoiceController::class);
        Route::controller(InvoiceController::class)->prefix('invoices/{invoice}')->name('invoices.')->group(function () {
            Route::post('issue', 'issue')->name('issue');
            Route::post('credit-note', 'creditNote')->name('credit-note');
        });
        Route::controller(InvoiceSdiController::class)->prefix('invoices/{invoice}/sdi')->name('invoices.sdi.')->group(function () {
            Route::post('send', 'send')->middleware('throttle:20,1')->name('send');
            Route::post('refresh', 'refresh')->middleware('throttle:20,1')->name('refresh');
            Route::post('simulate', 'simulate')->middleware('throttle:20,1')->name('simulate');
            Route::put('external', 'external')->name('external');
        });
        Route::controller(InvoiceDocumentController::class)->prefix('invoices/{invoice}')->name('invoices.')->group(function () {
            Route::get('pdf', 'pdf')->name('pdf');
            Route::get('xml', 'xml')->name('xml');
        });
    });

    Route::post('documents/read', [DocumentImportController::class, 'store'])->middleware('throttle:20,1')->name('documents.read');
    Route::get('documents/{extraction}/file', [DocumentImportController::class, 'file'])->name('documents.file');

    Route::prefix('purchases')->name('purchases.')->group(function () {
        Route::get('received-invoices', [ReceivedInvoiceController::class, 'index'])->name('received.index');
        Route::post('received-invoices/import', [ReceivedInvoiceController::class, 'import'])->name('received.import');
        Route::post('received-invoices/sync', [ReceivedInvoiceController::class, 'sync'])->middleware('throttle:10,1')->name('received.sync');
        Route::post('received-invoices/simulate', [ReceivedInvoiceController::class, 'simulate'])->middleware('throttle:10,1')->name('received.simulate');
        Route::post('received-invoices/{receivedInvoice}/record', [ReceivedInvoiceController::class, 'record'])->name('received.record');
        Route::patch('received-invoices/{receivedInvoice}/status', [ReceivedInvoiceController::class, 'updateStatus'])->name('received.status');
        Route::get('received-invoices/{receivedInvoice}/file', [ReceivedInvoiceController::class, 'file'])->name('received.file');

        Route::get('expenses/link-options', [ExpenseController::class, 'linkOptions'])->name('expenses.link-options');
        Route::patch('expenses/link', [ExpenseController::class, 'link'])->name('expenses.link');
        Route::resource('expenses', ExpenseController::class);
        Route::patch('expenses/{expense}/payment', [ExpenseController::class, 'payment'])->name('expenses.payment');
        Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])->name('expenses.attachment');

        Route::resource('orders', PurchaseOrderController::class)->parameters(['orders' => 'purchase_order']);
        Route::patch('orders/{purchase_order}/status', [PurchaseOrderController::class, 'updateStatus'])->name('orders.status');
        Route::controller(PurchaseOrderDocumentController::class)->prefix('orders/{purchase_order}')->name('orders.')->group(function () {
            Route::get('pdf', 'pdf')->name('pdf');
            Route::get('compose', 'compose')->name('compose');
            Route::post('send', 'send')->middleware('throttle:10,1')->name('send');
        });
    });

    Route::prefix('interventions')->name('interventions.')->group(function () {
        Route::controller(InterventionSettingsController::class)->prefix('settings')->name('settings.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::put('/', 'update')->name('update');
            Route::post('categories', 'storeCategory')->name('categories.store');
            Route::put('categories/{category}', 'updateCategory')->name('categories.update');
            Route::delete('categories/{category}', 'destroyCategory')->name('categories.destroy');
        });

        Route::get('invoiced-outside', [InterventionInvoicedOutsideController::class, 'index'])->name('invoiced-outside.index');
        Route::post('invoiced-outside', [InterventionInvoicedOutsideController::class, 'store'])->name('invoiced-outside.store');
        Route::delete('{intervention}/invoiced-outside', [InterventionInvoicedOutsideController::class, 'destroy'])->name('invoiced-outside.destroy');
        Route::get('start-options', [InterventionController::class, 'startOptions'])->name('start-options');
        Route::post('start', [InterventionController::class, 'start'])->name('start');
        Route::get('customers/{organization}', [InterventionController::class, 'customer'])->name('customer');
        Route::post('{intervention}/stop', [InterventionController::class, 'stop'])->name('stop');
        Route::post('{intervention}/split', [InterventionController::class, 'split'])->name('split');

        Route::controller(InterventionDocumentController::class)->prefix('{intervention}')->group(function () {
            Route::get('pdf', 'pdf')->name('pdf');
            Route::get('compose', 'compose')->name('compose');
            Route::post('send', 'send')->middleware('throttle:10,1')->name('send');
            Route::post('signature', 'sign')->middleware('throttle:20,1')->name('sign');
            Route::delete('signature', 'unsign')->name('unsign');
            Route::get('signature', 'signature')->name('signature');
        });

        Route::resource('contracts', ServiceContractController::class);
        Route::post('contracts/{contract}/invoice', [ServiceContractController::class, 'invoice'])->name('contracts.invoice');
    });
    Route::resource('interventions', InterventionController::class);

    // Phone page that works offline: interventions recorded on the device and synced.
    Route::get('field', [FieldController::class, 'index'])->name('field.index');
    Route::post('field/sync', [FieldController::class, 'sync'])->middleware('throttle:120,1')->name('field.sync');

    Route::get('statistics', StatisticsController::class)->name('statistics.index');

    Route::prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/', ProfitAndLossController::class)->name('index');

        Route::controller(CashController::class)->group(function () {
            Route::get('cash', 'index')->name('cash.index');
            Route::post('accounts', 'storeAccount')->name('accounts.store');
            Route::put('accounts/{account}', 'updateAccount')->name('accounts.update');
            Route::delete('accounts/{account}', 'destroyAccount')->name('accounts.destroy');
            Route::post('movements', 'storeMovement')->name('movements.store');
            Route::put('movements/{movement}', 'updateMovement')->name('movements.update');
            Route::delete('movements/{movement}', 'destroyMovement')->name('movements.destroy');
            Route::post('transfers', 'transfer')->name('transfers.store');
            Route::post('invoices/{invoice}/collections', 'collect')->name('collections.store');
            Route::post('dolibarr-collections', 'importDolibarr')->name('dolibarr-collections.store');
        });

        Route::controller(AccountingSettingsController::class)->prefix('settings')->name('settings.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('categories', 'storeCategory')->name('categories.store');
            Route::put('categories/{category}', 'updateCategory')->name('categories.update');
            Route::delete('categories/{category}', 'destroyCategory')->name('categories.destroy');
            Route::post('lines', 'storeLine')->name('lines.store');
            Route::put('lines/{line}', 'updateLine')->name('lines.update');
            Route::delete('lines/{line}', 'destroyLine')->name('lines.destroy');
            Route::put('tax', 'updateTax')->name('tax.update');
        });
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::controller(SettingsController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::put('company', 'updateCompany')->name('company.update');
            Route::post('company/logo', 'updateLogo')->name('company.logo.update');
            Route::delete('company/logo', 'destroyLogo')->name('company.logo.destroy');
            Route::put('mail', 'updateMail')->name('mail.update');
            Route::post('mail/test', 'testMail')->middleware('throttle:5,1')->name('mail.test');
            Route::put('quotes', 'updateQuotes')->name('quotes.update');
            Route::put('fiscal', 'updateFiscal')->name('fiscal.update');
            Route::put('ai', 'updateAi')->name('ai.update');
            Route::get('ai/models', 'aiModels')->name('ai.models');
            Route::post('ai/test', 'testAi')->middleware('throttle:10,1')->name('ai.test');
        });

        Route::controller(ElectronicInvoicingController::class)->prefix('einvoicing')->name('einvoicing.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::put('/', 'update')->name('update');
            Route::post('connect', 'connect')->middleware('throttle:10,1')->name('connect');
            Route::put('prices', 'updatePrices')->name('prices');
            Route::get('costs', 'costs')->name('costs');
            Route::get('counters', 'counters')->middleware('throttle:20,1')->name('counters');
        });

        Route::resource('payment-terms', PaymentTermController::class)->only(['store', 'update', 'destroy']);

        Route::controller(DolibarrImportController::class)->prefix('dolibarr')->name('dolibarr.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
            Route::post('{dolibarrImport}/simulate', 'simulate')->name('simulate');
            Route::post('{dolibarrImport}/import', 'import')->name('import');
            Route::delete('{dolibarrImport}', 'destroy')->name('destroy');
        });
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::post('users/{user}/password-reset', SendPasswordResetLinkController::class)
            ->middleware('throttle:6,1')
            ->name('users.password-reset');

        Route::resource('roles', RoleController::class)->except('show');

        Route::middleware('can:'.Permission::NotificationsSend->value)->group(function () {
            Route::get('announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
            Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        });
    });
});
