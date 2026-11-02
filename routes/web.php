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
use App\Http\Controllers\Registry\ContactController;
use App\Http\Controllers\Registry\LocationController;
use App\Http\Controllers\Registry\OrganizationController;
use Illuminate\Support\Facades\Route;

/*
| Authentication routes (login, register, password reset, email verification,
| two factor authentication, profile information and password update) are
| registered by Laravel Fortify: see config/fortify.php.
*/

Route::redirect('/', '/dashboard');

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
