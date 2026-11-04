<?php

use App\Http\Controllers\Webhooks\OpenapiSdiWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Callbacks from external services. Loaded without the "web" middleware group (no session, no CSRF):
| every controller authenticates the caller itself.
*/

Route::post('webhooks/openapi/sdi', OpenapiSdiWebhookController::class)->name('webhooks.openapi.sdi');
