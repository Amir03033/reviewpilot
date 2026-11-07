<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandVoiceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DraftFeedbackController;
use App\Http\Controllers\GoogleIntegrationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationSettingsController;
use App\Http\Controllers\ReviewActionController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\Webhooks\GooglePubSubController;
use App\Http\Controllers\Webhooks\MollieWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('legal/{document}', [LegalController::class, 'show'])->whereIn('document', ['privacy', 'terms', 'dpa'])->name('legal.show');
// Short URLs used in emails, the Google consent screen and the footer.
Route::redirect('privacy', '/legal/privacy');
Route::redirect('terms', '/legal/terms');
Route::redirect('voorwaarden', '/legal/terms');
Route::redirect('privacybeleid', '/legal/privacy');

Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale.update');

// --- Webhooks (no session, no CSRF; authenticated by signature / API lookup) ---
Route::post('webhooks/google/pubsub', GooglePubSubController::class)->middleware('throttle:600,1')->name('webhooks.google');
Route::post('webhooks/mollie', MollieWebhookController::class)->middleware('throttle:300,1')->name('webhooks.mollie');

// --- Invitations (viewable logged out; accepting requires login) ---
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'accept'])->middleware(['auth', 'verified'])->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('organizations/{organization}/switch', [OnboardingController::class, 'switch'])->name('organizations.switch');

    Route::middleware('org')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::post('dashboard/checklist/dismiss', [DashboardController::class, 'dismissChecklist'])->name('dashboard.checklist.dismiss');

        // Reviews & approvals
        Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::get('reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
        Route::get('approvals', [ReviewController::class, 'approvals'])->name('approvals.index');
        Route::post('drafts/{draft}/approve', [ReviewActionController::class, 'approve'])->middleware('plan')->name('drafts.approve');
        Route::post('drafts/{draft}/feedback', [DraftFeedbackController::class, 'store'])->middleware('throttle:60,1')->name('drafts.feedback');
        Route::post('reviews/{review}/reply', [ReviewActionController::class, 'manual'])->middleware('plan')->name('reviews.reply');
        Route::post('reviews/{review}/regenerate', [ReviewActionController::class, 'regenerate'])->middleware(['plan', 'throttle:regenerate'])->name('reviews.regenerate');
        Route::post('reviews/{review}/reject', [ReviewActionController::class, 'reject'])->name('reviews.reject');
        Route::post('reviews/{review}/cancel-scheduled', [ReviewActionController::class, 'cancelScheduled'])->name('reviews.cancel-scheduled');
        Route::post('reviews/{review}/retry', [ReviewActionController::class, 'retry'])->middleware('plan')->name('reviews.retry');

        // Locations
        Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
        Route::get('locations/{location}', [LocationController::class, 'show'])->name('locations.show');
        Route::patch('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::put('locations/{location}/policy', [LocationController::class, 'updatePolicy'])->name('locations.policy');
        Route::put('locations/{location}/facts', [LocationController::class, 'updateFacts'])->name('locations.facts');
        Route::put('locations/{location}/voice', [BrandVoiceController::class, 'updateLocation'])->name('locations.voice');
        Route::post('locations/{location}/insights', [LocationController::class, 'refreshInsight'])->middleware(['plan', 'throttle:3,60'])->name('locations.insights');
        Route::post('locations/{location}/sync', [LocationController::class, 'sync'])->name('locations.sync');
        Route::delete('locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');

        // Brand voice
        Route::get('voice', [BrandVoiceController::class, 'edit'])->name('voice.edit');
        Route::put('voice', [BrandVoiceController::class, 'update'])->name('voice.update');

        // Google
        Route::get('integrations', [GoogleIntegrationController::class, 'index'])->name('integrations.index');
        Route::post('integrations/google/connect', [GoogleIntegrationController::class, 'redirect'])->name('integrations.google.connect');
        Route::get('integrations/google/callback', [GoogleIntegrationController::class, 'callback'])->name('integrations.google.callback');
        Route::post('integrations/google/import', [GoogleIntegrationController::class, 'import'])->middleware('plan')->name('integrations.google.import');
        Route::post('integrations/google/{connection}/refresh', [GoogleIntegrationController::class, 'refresh'])->name('integrations.google.refresh');
        Route::delete('integrations/google/{connection}', [GoogleIntegrationController::class, 'disconnect'])->name('integrations.google.disconnect');

        // Team
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team/invitations', [TeamController::class, 'invite'])->middleware('throttle:invitations')->name('team.invite');
        Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');
        Route::put('team/{membership}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('team/{membership}', [TeamController::class, 'destroy'])->name('team.destroy');

        // Billing (Mollie)
        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        Route::post('billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::get('billing/return/{subscription}', [BillingController::class, 'return'])->name('billing.return');
        Route::get('billing/invoices/{invoice}', [InvoiceController::class, 'download'])->name('billing.invoices.download');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');

        // Organisation
        Route::get('organization', [OrganizationSettingsController::class, 'edit'])->name('organization.edit');
        Route::put('organization', [OrganizationSettingsController::class, 'update'])->name('organization.update');
        Route::post('organization/accept-terms', [OrganizationSettingsController::class, 'acceptTerms'])->name('organization.accept-terms');
        Route::put('organization/kill-switch', [OrganizationSettingsController::class, 'killSwitch'])->name('organization.kill-switch');
        Route::delete('organization', [OrganizationSettingsController::class, 'destroy'])->name('organization.destroy');
        Route::post('organization/export', [DataExportController::class, 'store'])->middleware('throttle:5,60')->name('organization.export');
        Route::get('organization/export/{export}', [DataExportController::class, 'download'])->middleware('signed')->name('organization.export.download');
        Route::get('organization/audit', [OrganizationSettingsController::class, 'audit'])->name('organization.audit');
    });
});

require __DIR__.'/settings.php';
