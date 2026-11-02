<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SendPasswordResetLinkController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Catalog\CatalogSettingsController;
use App\Http\Controllers\Catalog\CustomerPriceController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Profile\AvatarController;
use App\Http\Controllers\Profile\BrowserSessionController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\PublicArea\PublicQuoteController;
use App\Http\Controllers\Registry\ContactController;
use App\Http\Controllers\Registry\LocationController;
use App\Http\Controllers\Registry\OrganizationController;
use App\Http\Controllers\Sales\QuoteController;
use App\Http\Controllers\Sales\QuoteDocumentController;
use App\Http\Controllers\Sales\QuoteLookupController;
use App\Http\Controllers\Settings\PaymentTermController;
use App\Http\Controllers\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| Authentication routes (login, register, password reset, email verification,
| two factor authentication, profile information and password update) are
| registered by Laravel Fortify: see config/fortify.php.
*/

Route::redirect('/', '/dashboard');

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
        Route::resource('organizations', OrganizationController::class)->except('show');
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
        });

        Route::resource('payment-terms', PaymentTermController::class)->only(['store', 'update', 'destroy']);
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
