# Boilerplate conventions

## Stack
- Laravel 13 + Fortify (headless auth) + Spatie Permission + Inertia v3 + Vue 3 (`<script setup>`, plain JS) + Vuetify 4 + Ziggy. Database: MariaDB (SQLite in-memory for tests).
- UI copy is Italian. Server-side strings go through `__()`; validation messages live in `lang/it`.

## Authorization
- Permissions are defined ONLY in `App\Enums\Permission`; system roles in `App\Enums\Role`. After adding a case run `php artisan permissions:sync` (also called by the seeders).
- `super-admin` owns every permission through `Gate::before` in `AppServiceProvider`, but policies (`UserPolicy`, `RolePolicy`) still apply their structural rules (no self-delete, system roles not deletable, last super admin protected).
- Authorize in controllers with `$this->authorize()` / Form Request `authorize()`; the frontend uses `usePermissions().can()` only to hide UI.

## Notifications
- Every in-app notification extends `App\Notifications\AppNotification` and implements `title()` / `message()` (optionally `icon()`, `color()`, `actionUrl()`, `actionLabel()`). It is stored in the database and also mailed when the user enabled `email_notifications`.
- Notifications are queued (`ShouldQueue`): a worker must run (`composer dev` starts one).

## Frontend
- Pages: `resources/js/pages/**` (lowercase directory, e.g. `admin/users/Index`). Layout is chosen automatically in `resources/js/app.js` (`auth/*` → `AuthLayout`, else `AppLayout`).
- Reusable components: `resources/js/components`; composables: `resources/js/composables`.
- Links: use `useInertiaLink().link(route('name'))` with `v-bind` on Vuetify components (`v-btn`, `v-list-item`...).
- Toasts: server side `Inertia::flash('toast', ['type' => 'success', 'message' => '...'])`; client side `useToast()`.
- Confirmations: `await useConfirm().confirm({...})`.
- Shared props: `auth` (user, roles, permissions), `inbox` (unread_count, latest), `app`, `status` (Fortify).
- Component defaults (variants, density...) are set once in `resources/js/plugins/vuetify.js`.

## Quality
- `php artisan test` (feature tests per area in `tests/Feature`), `vendor/bin/pint`, `npm run lint`, `npm run format`.
