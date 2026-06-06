<?php

use App\Http\Controllers\Settings\ApplicationSettingsController;
use App\Http\Controllers\Settings\EmailTemplateController;
use App\Http\Controllers\Settings\Integrations\HikvisionIntegrationController;
use App\Http\Controllers\Settings\Integrations\WhatsAppIntegrationController;
use App\Http\Controllers\Settings\MasterData\ApprovalLocationController;
use App\Http\Controllers\Settings\MasterData\BankController;
use App\Http\Controllers\Settings\MasterData\ClientController;
use App\Http\Controllers\Settings\MasterData\CompanyVisaTypeController;
use App\Http\Controllers\Settings\MasterData\CountryController;
use App\Http\Controllers\Settings\MasterData\CourseController;
use App\Http\Controllers\Settings\MasterData\CurrencyController;
use App\Http\Controllers\Settings\MasterData\DocumentTypeController;
use App\Http\Controllers\Settings\MasterData\GenderController;
use App\Http\Controllers\Settings\MasterData\RankController;
use App\Http\Controllers\Settings\MasterData\ReligionController;
use App\Http\Controllers\Settings\MasterData\SssaOptionController;
use App\Http\Controllers\Settings\MasterData\VesselTypeController;
use App\Http\Controllers\Settings\MasterData\VisaTypeController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SettingsHubController;
use App\Http\Controllers\Settings\WhatsAppTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings', SettingsHubController::class)->name('settings.index');
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware('can:settings.security.view')
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->middleware('can:settings.security.update')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')
        ->middleware('can:settings.appearance.view')
        ->name('appearance.edit');

    Route::get('settings/application', [ApplicationSettingsController::class, 'edit'])
        ->name('application.edit');

    Route::put('settings/application/general', [ApplicationSettingsController::class, 'updateGeneral'])
        ->middleware('can:settings.application.update')
        ->name('application.general.update');

    Route::post('settings/application/branding', [ApplicationSettingsController::class, 'updateBranding'])
        ->middleware('can:settings.application.update')
        ->name('application.branding.update');

    Route::delete('settings/application/branding/{asset}', [ApplicationSettingsController::class, 'removeBranding'])
        ->middleware('can:settings.application.update')
        ->where('asset', '[a-z_]+')
        ->name('application.branding.remove');

    Route::post('settings/application/smtp', [ApplicationSettingsController::class, 'updateSmtp'])
        ->middleware('can:settings.application.update')
        ->name('application.smtp.update');

    Route::post('settings/application/smtp/test', [ApplicationSettingsController::class, 'sendTestMail'])
        ->middleware('can:settings.application.update')
        ->name('application.smtp.test');

    Route::redirect('settings/integrations/whatsapp', '/settings/application?tab=whatsapp')
        ->name('integrations.whatsapp.edit');

    Route::put('settings/application/whatsapp', [WhatsAppIntegrationController::class, 'update'])
        ->middleware('can:settings.integrations.whatsapp.update')
        ->name('application.whatsapp.update');

    Route::post('settings/application/whatsapp/test', [WhatsAppIntegrationController::class, 'testConnection'])
        ->middleware('can:settings.integrations.whatsapp.update')
        ->name('application.whatsapp.test');

    Route::post('settings/application/whatsapp/send-test-text', [WhatsAppIntegrationController::class, 'sendTestText'])
        ->middleware('can:settings.integrations.whatsapp.update')
        ->name('application.whatsapp.send-test-text');

    Route::post('settings/application/whatsapp/send-test-document', [WhatsAppIntegrationController::class, 'sendTestDocument'])
        ->middleware('can:settings.integrations.whatsapp.update')
        ->name('application.whatsapp.send-test-document');

    Route::post('settings/application/whatsapp/send-test-template', [WhatsAppIntegrationController::class, 'sendTestTemplate'])
        ->middleware('can:settings.integrations.whatsapp.update')
        ->name('application.whatsapp.send-test-template');

    Route::post('settings/application/whatsapp/send-test-document-template', [WhatsAppIntegrationController::class, 'sendTestDocumentTemplate'])
        ->middleware('can:settings.integrations.whatsapp.update')
        ->name('application.whatsapp.send-test-document-template');

    Route::put('settings/application/hikvision', [HikvisionIntegrationController::class, 'update'])
        ->middleware('can:settings.integrations.hikvision.update')
        ->name('application.hikvision.update');

    Route::post('settings/application/hikvision/test', [HikvisionIntegrationController::class, 'testConnection'])
        ->middleware('can:settings.integrations.hikvision.update')
        ->name('application.hikvision.test');

    Route::post('settings/application/hikvision/webhook/register', [HikvisionIntegrationController::class, 'registerWebhook'])
        ->middleware('can:hikvision.webhook.manage')
        ->name('application.hikvision.webhook.register');

    Route::get('settings/application/whatsapp-templates', [WhatsAppTemplateController::class, 'index'])
        ->middleware('can:settings.integrations.whatsapp-templates.view')
        ->name('application.whatsapp-templates.index');

    Route::post('settings/application/whatsapp-templates', [WhatsAppTemplateController::class, 'store'])
        ->middleware('can:settings.integrations.whatsapp-templates.create')
        ->name('application.whatsapp-templates.store');

    Route::put('settings/application/whatsapp-templates/{whatsapp_template}', [WhatsAppTemplateController::class, 'update'])
        ->middleware('can:settings.integrations.whatsapp-templates.update')
        ->name('application.whatsapp-templates.update');

    Route::delete('settings/application/whatsapp-templates/{whatsapp_template}', [WhatsAppTemplateController::class, 'destroy'])
        ->middleware('can:settings.integrations.whatsapp-templates.delete')
        ->name('application.whatsapp-templates.destroy');

    Route::get('settings/application/email-templates', [EmailTemplateController::class, 'index'])
        ->middleware('can:settings.integrations.email-templates.view')
        ->name('application.email-templates.index');

    Route::post('settings/application/email-templates', [EmailTemplateController::class, 'store'])
        ->middleware('can:settings.integrations.email-templates.create')
        ->name('application.email-templates.store');

    Route::put('settings/application/email-templates/{email_template}', [EmailTemplateController::class, 'update'])
        ->middleware('can:settings.integrations.email-templates.update')
        ->name('application.email-templates.update');

    Route::delete('settings/application/email-templates/{email_template}', [EmailTemplateController::class, 'destroy'])
        ->middleware('can:settings.integrations.email-templates.delete')
        ->name('application.email-templates.destroy');

    Route::prefix('settings/master-data')->name('settings.master-data.')->group(function () {
        Route::get('countries', [CountryController::class, 'index'])
            ->middleware('can:settings.master-data.countries.view')
            ->name('countries.index');
        Route::post('countries', [CountryController::class, 'store'])
            ->middleware('can:settings.master-data.countries.create')
            ->name('countries.store');
        Route::put('countries/{country}', [CountryController::class, 'update'])
            ->middleware('can:settings.master-data.countries.update')
            ->name('countries.update');
        Route::delete('countries/{country}', [CountryController::class, 'destroy'])
            ->middleware('can:settings.master-data.countries.delete')
            ->name('countries.destroy');

        Route::get('currencies', [CurrencyController::class, 'index'])
            ->middleware('can:settings.master-data.currencies.view')
            ->name('currencies.index');
        Route::post('currencies', [CurrencyController::class, 'store'])
            ->middleware('can:settings.master-data.currencies.create')
            ->name('currencies.store');
        Route::put('currencies/{currency}', [CurrencyController::class, 'update'])
            ->middleware('can:settings.master-data.currencies.update')
            ->name('currencies.update');
        Route::delete('currencies/{currency}', [CurrencyController::class, 'destroy'])
            ->middleware('can:settings.master-data.currencies.delete')
            ->name('currencies.destroy');

        Route::get('visa-types', [VisaTypeController::class, 'index'])
            ->middleware('can:settings.master-data.visa-types.view')
            ->name('visa-types.index');
        Route::post('visa-types', [VisaTypeController::class, 'store'])
            ->middleware('can:settings.master-data.visa-types.create')
            ->name('visa-types.store');
        Route::put('visa-types/{visa_type}', [VisaTypeController::class, 'update'])
            ->middleware('can:settings.master-data.visa-types.update')
            ->name('visa-types.update');
        Route::delete('visa-types/{visa_type}', [VisaTypeController::class, 'destroy'])
            ->middleware('can:settings.master-data.visa-types.delete')
            ->name('visa-types.destroy');

        Route::get('company-visa-types', [CompanyVisaTypeController::class, 'index'])
            ->middleware('can:settings.master-data.company-visa-types.view')
            ->name('company-visa-types.index');
        Route::post('company-visa-types', [CompanyVisaTypeController::class, 'store'])
            ->middleware('can:settings.master-data.company-visa-types.create')
            ->name('company-visa-types.store');
        Route::put('company-visa-types/{company_visa_type}', [CompanyVisaTypeController::class, 'update'])
            ->middleware('can:settings.master-data.company-visa-types.update')
            ->name('company-visa-types.update');
        Route::delete('company-visa-types/{company_visa_type}', [CompanyVisaTypeController::class, 'destroy'])
            ->middleware('can:settings.master-data.company-visa-types.delete')
            ->name('company-visa-types.destroy');

        Route::get('approval-locations', [ApprovalLocationController::class, 'index'])
            ->middleware('can:settings.master-data.approval-locations.view')
            ->name('approval-locations.index');
        Route::post('approval-locations', [ApprovalLocationController::class, 'store'])
            ->middleware('can:settings.master-data.approval-locations.create')
            ->name('approval-locations.store');
        Route::put('approval-locations/{approval_location}', [ApprovalLocationController::class, 'update'])
            ->middleware('can:settings.master-data.approval-locations.update')
            ->name('approval-locations.update');
        Route::delete('approval-locations/{approval_location}', [ApprovalLocationController::class, 'destroy'])
            ->middleware('can:settings.master-data.approval-locations.delete')
            ->name('approval-locations.destroy');

        Route::get('sssa-options', [SssaOptionController::class, 'index'])
            ->middleware('can:settings.master-data.sssa-options.view')
            ->name('sssa-options.index');
        Route::post('sssa-options', [SssaOptionController::class, 'store'])
            ->middleware('can:settings.master-data.sssa-options.create')
            ->name('sssa-options.store');
        Route::put('sssa-options/{sssa_option}', [SssaOptionController::class, 'update'])
            ->middleware('can:settings.master-data.sssa-options.update')
            ->name('sssa-options.update');
        Route::delete('sssa-options/{sssa_option}', [SssaOptionController::class, 'destroy'])
            ->middleware('can:settings.master-data.sssa-options.delete')
            ->name('sssa-options.destroy');

        Route::get('religions', [ReligionController::class, 'index'])
            ->middleware('can:settings.master-data.religions.view')
            ->name('religions.index');
        Route::post('religions', [ReligionController::class, 'store'])
            ->middleware('can:settings.master-data.religions.create')
            ->name('religions.store');
        Route::put('religions/{religion}', [ReligionController::class, 'update'])
            ->middleware('can:settings.master-data.religions.update')
            ->name('religions.update');
        Route::delete('religions/{religion}', [ReligionController::class, 'destroy'])
            ->middleware('can:settings.master-data.religions.delete')
            ->name('religions.destroy');

        Route::get('genders', [GenderController::class, 'index'])
            ->middleware('can:settings.master-data.genders.view')
            ->name('genders.index');
        Route::post('genders', [GenderController::class, 'store'])
            ->middleware('can:settings.master-data.genders.create')
            ->name('genders.store');
        Route::put('genders/{gender}', [GenderController::class, 'update'])
            ->middleware('can:settings.master-data.genders.update')
            ->name('genders.update');
        Route::delete('genders/{gender}', [GenderController::class, 'destroy'])
            ->middleware('can:settings.master-data.genders.delete')
            ->name('genders.destroy');

        Route::get('courses/import/template', [CourseController::class, 'importTemplate'])
            ->middleware('can:settings.master-data.courses.view')
            ->name('courses.import.template');
        Route::post('courses/import', [CourseController::class, 'import'])
            ->middleware('can:settings.master-data.courses.create')
            ->name('courses.import');
        Route::get('courses', [CourseController::class, 'index'])
            ->middleware('can:settings.master-data.courses.view')
            ->name('courses.index');
        Route::post('courses', [CourseController::class, 'store'])
            ->middleware('can:settings.master-data.courses.create')
            ->name('courses.store');
        Route::put('courses/{course}', [CourseController::class, 'update'])
            ->middleware('can:settings.master-data.courses.update')
            ->name('courses.update');
        Route::delete('courses/{course}', [CourseController::class, 'destroy'])
            ->middleware('can:settings.master-data.courses.delete')
            ->name('courses.destroy');

        Route::get('banks', [BankController::class, 'index'])
            ->middleware('can:settings.master-data.banks.view')
            ->name('banks.index');
        Route::post('banks', [BankController::class, 'store'])
            ->middleware('can:settings.master-data.banks.create')
            ->name('banks.store');
        Route::put('banks/{bank}', [BankController::class, 'update'])
            ->middleware('can:settings.master-data.banks.update')
            ->name('banks.update');
        Route::delete('banks/{bank}', [BankController::class, 'destroy'])
            ->middleware('can:settings.master-data.banks.delete')
            ->name('banks.destroy');

        Route::get('vessel-types/import/template', [VesselTypeController::class, 'importTemplate'])
            ->middleware('can:settings.master-data.vessel-types.view')
            ->name('vessel-types.import.template');
        Route::post('vessel-types/import', [VesselTypeController::class, 'import'])
            ->middleware('can:settings.master-data.vessel-types.create')
            ->name('vessel-types.import');
        Route::get('vessel-types', [VesselTypeController::class, 'index'])
            ->middleware('can:settings.master-data.vessel-types.view')
            ->name('vessel-types.index');
        Route::post('vessel-types', [VesselTypeController::class, 'store'])
            ->middleware('can:settings.master-data.vessel-types.create')
            ->name('vessel-types.store');
        Route::put('vessel-types/{vessel_type}', [VesselTypeController::class, 'update'])
            ->middleware('can:settings.master-data.vessel-types.update')
            ->name('vessel-types.update');
        Route::delete('vessel-types/{vessel_type}', [VesselTypeController::class, 'destroy'])
            ->middleware('can:settings.master-data.vessel-types.delete')
            ->name('vessel-types.destroy');

        Route::get('ranks/import/template', [RankController::class, 'importTemplate'])
            ->middleware('can:settings.master-data.ranks.view')
            ->name('ranks.import.template');
        Route::post('ranks/import', [RankController::class, 'import'])
            ->middleware('can:settings.master-data.ranks.create')
            ->name('ranks.import');
        Route::get('ranks', [RankController::class, 'index'])
            ->middleware('can:settings.master-data.ranks.view')
            ->name('ranks.index');
        Route::post('ranks', [RankController::class, 'store'])
            ->middleware('can:settings.master-data.ranks.create')
            ->name('ranks.store');
        Route::put('ranks/{rank}', [RankController::class, 'update'])
            ->middleware('can:settings.master-data.ranks.update')
            ->name('ranks.update');
        Route::delete('ranks/{rank}', [RankController::class, 'destroy'])
            ->middleware('can:settings.master-data.ranks.delete')
            ->name('ranks.destroy');

        Route::get('clients/import/template', [ClientController::class, 'importTemplate'])
            ->middleware('can:settings.master-data.clients.view')
            ->name('clients.import.template');
        Route::post('clients/import', [ClientController::class, 'import'])
            ->middleware('can:settings.master-data.clients.create')
            ->name('clients.import');
        Route::get('clients', [ClientController::class, 'index'])
            ->middleware('can:settings.master-data.clients.view')
            ->name('clients.index');
        Route::post('clients', [ClientController::class, 'store'])
            ->middleware('can:settings.master-data.clients.create')
            ->name('clients.store');
        Route::put('clients/{client}', [ClientController::class, 'update'])
            ->middleware('can:settings.master-data.clients.update')
            ->name('clients.update');
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])
            ->middleware('can:settings.master-data.clients.delete')
            ->name('clients.destroy');

        Route::get('document-types/import/template', [DocumentTypeController::class, 'importTemplate'])
            ->middleware('can:settings.master-data.document-types.view')
            ->name('document-types.import.template');
        Route::post('document-types/import', [DocumentTypeController::class, 'import'])
            ->middleware('can:settings.master-data.document-types.create')
            ->name('document-types.import');
        Route::get('document-types', [DocumentTypeController::class, 'index'])
            ->middleware('can:settings.master-data.document-types.view')
            ->name('document-types.index');
        Route::post('document-types', [DocumentTypeController::class, 'store'])
            ->middleware('can:settings.master-data.document-types.create')
            ->name('document-types.store');
        Route::put('document-types/{document_type}', [DocumentTypeController::class, 'update'])
            ->middleware('can:settings.master-data.document-types.update')
            ->name('document-types.update');
        Route::delete('document-types/{document_type}', [DocumentTypeController::class, 'destroy'])
            ->middleware('can:settings.master-data.document-types.delete')
            ->name('document-types.destroy');
    });
});
