<?php

use App\Http\Controllers\App\AdminController;
use App\Http\Controllers\App\AgentController;
use App\Http\Controllers\App\AuthController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\CrmController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DatasetController;
use App\Http\Controllers\App\ExportController;
use App\Http\Controllers\App\HealthController;
use App\Http\Controllers\App\ImportController;
use App\Http\Controllers\App\LocaleController;
use App\Http\Controllers\App\PageController;
use App\Http\Controllers\App\PaletteSearchController;
use App\Http\Controllers\App\PrivacyController;
use App\Http\Controllers\App\ProfileController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\SlackController;
use App\Http\Controllers\App\SocialAuthController;
use App\Http\Controllers\App\TelegramController;
use App\Http\Controllers\App\WebhookController;
use App\Http\Controllers\App\WhatsAppController;
use App\Http\Controllers\App\WidgetController;
use App\Http\Controllers\App\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// ---------- Guests ----------
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('forgot-password', [AuthController::class, 'sendReset'])->name('password.email')->middleware('throttle:5,1');
    Route::get('reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('reset-password', [AuthController::class, 'reset'])->name('password.update')->middleware('throttle:10,1');
});

Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('sso.redirect')->middleware('throttle:30,1');
Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('sso.callback')->middleware('throttle:30,1');
Route::post('channels/slack/{uuid}', SlackController::class)->name('channels.slack')->middleware('throttle:600,1');
Route::get('health', HealthController::class)->name('health');
Route::get('two-factor', [AuthController::class, 'showChallenge'])->name('two-factor.challenge')->middleware('guest');
Route::post('two-factor', [AuthController::class, 'challenge'])->middleware(['guest', 'throttle:10,1']);

Route::post('locale', [LocaleController::class, 'update'])->name('locale.update')->middleware('throttle:30,1');

Route::get('invitations/{token}', [WorkspaceController::class, 'accept'])->name('invitations.accept');

// ---------- Public channels ----------
Route::get('widget.js', [WidgetController::class, 'script'])->name('widget.script');
Route::options('widget/{key}/{any?}', [WidgetController::class, 'preflight']);
Route::get('widget/{key}/config', [WidgetController::class, 'config'])->middleware('throttle:60,1');
Route::post('widget/{key}/chat', [WidgetController::class, 'chat'])->middleware('throttle:widget');
Route::post('channels/telegram/{uuid}', TelegramController::class)->name('channels.telegram')->middleware('throttle:600,1');
Route::get('channels/whatsapp/{uuid}', [WhatsAppController::class, 'verify'])->name('channels.whatsapp');
Route::post('channels/whatsapp/{uuid}', [WhatsAppController::class, 'receive'])->middleware('throttle:600,1');

// ---------- Signed-in ----------
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('workspaces/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::middleware(['workspace', 'security.policy'])->group(function () {
        Route::prefix('profile')->name('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::patch('/', [ProfileController::class, 'update'])->name('.update');
            Route::put('password', [ProfileController::class, 'password'])->name('.password')->middleware('throttle:6,1');
            Route::get('security', [ProfileController::class, 'security'])->name('.security');
            Route::post('security/2fa', [ProfileController::class, 'enable2fa'])->name('.2fa.enable');
            Route::post('security/2fa/confirm', [ProfileController::class, 'confirm2fa'])->name('.2fa.confirm')->middleware('throttle:10,1');
            Route::delete('security/2fa', [ProfileController::class, 'disable2fa'])->name('.2fa.disable');
            Route::get('export', [ProfileController::class, 'export'])->name('.export');
            Route::delete('/', [ProfileController::class, 'destroy'])->name('.destroy');
        });

        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('chat', [PageController::class, 'chat'])->name('chat');
        Route::get('knowledge', [PageController::class, 'knowledge'])->name('knowledge');
        Route::get('approvals', [PageController::class, 'approvals'])->name('approvals');

        // JSON endpoints used by the app UI (same controllers as the REST API)
        Route::prefix('app/api')->middleware('throttle:brain')->group(base_path('routes/brain_shared.php'));

        Route::resource('agents', AgentController::class)->except('show');

        Route::get('search/palette', PaletteSearchController::class)->name('palette.search')->middleware('throttle:60,1');
        Route::get('leads', [CrmController::class, 'leads'])->name('leads.index');
        Route::post('leads', [CrmController::class, 'storeLead'])->name('leads.store');
        Route::patch('leads/{lead}', [CrmController::class, 'updateLead'])->name('leads.update');
        Route::delete('leads/{lead}', [CrmController::class, 'destroyLead'])->name('leads.destroy');
        Route::get('tasks', [CrmController::class, 'tasks'])->name('tasks.index');
        Route::post('tasks', [CrmController::class, 'storeTask'])->name('tasks.store');
        Route::post('tasks/{task}/toggle', [CrmController::class, 'toggleTask'])->name('tasks.toggle');
        Route::delete('tasks/{task}', [CrmController::class, 'destroyTask'])->name('tasks.destroy');

        Route::get('datasets', [DatasetController::class, 'index'])->name('datasets.index');
        Route::get('datasets/{dataset}', [DatasetController::class, 'show'])->name('datasets.show');
        Route::patch('datasets/{dataset}', [DatasetController::class, 'update'])->name('datasets.update');
        Route::delete('datasets/{dataset}', [DatasetController::class, 'destroy'])->name('datasets.destroy');

        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::get('imports/template/{target}', [ImportController::class, 'template'])->name('imports.template');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{import}/map', [ImportController::class, 'map'])->name('imports.map');
        Route::post('imports/{import}/map', [ImportController::class, 'saveMap'])->name('imports.saveMap');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{import}/rerun', [ImportController::class, 'rerun'])->name('imports.rerun');
        Route::delete('imports/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');

        Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
        Route::post('exports', [ExportController::class, 'store'])->name('exports.store');
        Route::get('exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');

        Route::middleware('role:admin')->group(function () {
            Route::get('settings', [SettingsController::class, 'general'])->name('settings.general');
            Route::post('settings', [SettingsController::class, 'saveGeneral']);
            Route::get('settings/providers', [SettingsController::class, 'providers'])->name('settings.providers');
            Route::post('settings/providers', [SettingsController::class, 'saveProvider']);
            Route::post('settings/providers/test', [SettingsController::class, 'testProvider'])->name('settings.providers.test');
            Route::get('settings/api', [SettingsController::class, 'api'])->name('settings.api');
            Route::post('settings/api', [SettingsController::class, 'createToken']);
            Route::delete('settings/api/{id}', [SettingsController::class, 'revokeToken'])->name('settings.api.revoke');
            Route::get('settings/channels', [SettingsController::class, 'channels'])->name('settings.channels');
            Route::post('settings/channels/briefing', [SettingsController::class, 'saveBriefing'])->name('settings.briefing');
            Route::post('settings/channels/briefing/send', [SettingsController::class, 'sendBriefingNow'])->name('settings.briefing.send');
            Route::post('settings/channels/telegram', [SettingsController::class, 'saveTelegram'])->name('settings.telegram');
            Route::post('settings/sso', [SettingsController::class, 'saveSso'])->name('settings.sso');
            Route::post('settings/channels/slack', [SettingsController::class, 'saveSlack'])->name('settings.slack');
            Route::post('settings/channels/whatsapp', [SettingsController::class, 'saveWhatsApp'])->name('settings.whatsapp');
            Route::get('settings/privacy', [PrivacyController::class, 'index'])->name('settings.privacy');
            Route::post('settings/privacy', [PrivacyController::class, 'saveSettings']);
            Route::post('settings/privacy/export', [PrivacyController::class, 'export'])->name('settings.privacy.export');
            Route::post('settings/privacy/erase', [PrivacyController::class, 'erase'])->name('settings.privacy.erase');
            Route::get('settings/webhooks', [WebhookController::class, 'index'])->name('settings.webhooks');
            Route::post('settings/webhooks', [WebhookController::class, 'store']);
            Route::post('settings/webhooks/ping', [WebhookController::class, 'ping'])->name('settings.webhooks.ping');
            Route::post('settings/webhooks/{endpoint}/toggle', [WebhookController::class, 'toggle'])->name('settings.webhooks.toggle');
            Route::delete('settings/webhooks/{endpoint}', [WebhookController::class, 'destroy'])->name('settings.webhooks.destroy');
            Route::post('settings/webhooks/deliveries/{delivery}/retry', [WebhookController::class, 'retry'])->name('settings.webhooks.retry');
            Route::post('settings/channels/widget/rotate', [SettingsController::class, 'rotateWidgetKey'])->name('settings.widget.rotate');
            Route::get('settings/team', [SettingsController::class, 'team'])->name('settings.team');
            Route::post('settings/team/invite', [SettingsController::class, 'invite'])->name('settings.team.invite');
            Route::delete('settings/team/invite/{id}', [SettingsController::class, 'cancelInvite'])->name('settings.team.cancel');
            Route::patch('settings/team/{userId}', [SettingsController::class, 'updateMember'])->name('settings.team.update');
            Route::get('settings/data', [SettingsController::class, 'data'])->name('settings.data');
            Route::get('settings/data/backup', [SettingsController::class, 'backup'])->name('settings.backup');
            Route::post('settings/data/restore', [SettingsController::class, 'restore'])->name('settings.restore');

            Route::get('billing', [BillingController::class, 'index'])->name('billing');
            Route::get('billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
            Route::get('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
        });

        Route::delete('settings/workspace', [SettingsController::class, 'destroyWorkspace'])->middleware('role:owner')->name('settings.destroy');

        Route::middleware('superadmin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/', [AdminController::class, 'index'])->name('index');
            Route::patch('workspaces/{workspace}', [AdminController::class, 'update'])->name('update');
            Route::post('workspaces/{workspace}/enter', [AdminController::class, 'enter'])->name('enter');
        });
    });
});
