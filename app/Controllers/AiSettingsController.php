<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Crypto;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AnthropicClient;
use App\Services\Settings;
use RuntimeException;

/**
 * Branch-level AI configuration: whether AI features are enabled and the
 * Anthropic API key used to reach them. Gated by the same ability as the
 * rest of /settings (can:administer) — this is a branch admin setting, not
 * something scoped to a single user.
 *
 * The stored key is encrypted at rest (Crypto) and never leaves this layer
 * in the clear: edit() never decrypts it, update() never flashes the raw
 * submitted value back into session, and test() never surfaces the
 * underlying AnthropicClient exception message to the browser.
 */
final class AiSettingsController extends Controller
{
    public function edit(): void
    {
        $this->view('pages/settings-ai', [
            'title' => 'AI Settings',
            'nav' => 'settings',
            'pageTitle' => 'AI settings',
            'pageMeta' => 'Configure the Anthropic API key used for AI features',
            'aiEnabled' => Settings::get('ai_enabled', false) === true,
            'hasApiKey' => Settings::string('ai_anthropic_api_key_encrypted', '') !== '',
        ]);
    }

    public function update(): void
    {
        // Handled manually rather than via Controller::validate(): that
        // helper flashes the raw $_POST body (minus password fields) back
        // into session on failure, and api_key must never be retained there.
        $validator = Validator::make($_POST, [
            'api_key' => 'nullable|max:200',
        ]);

        if ($validator->fails()) {
            Session::flash('errors', $validator->errors());
            Session::flash('error', 'Please correct the highlighted fields.');
            Http::redirect('/settings/ai');
        }

        $apiKey = (string) ($validator->validated()['api_key'] ?? '');
        $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOL);

        if ($apiKey !== '') {
            Settings::set('ai_anthropic_api_key_encrypted', Crypto::encrypt($apiKey));
        }

        Settings::set('ai_enabled', $enabled);

        Logger::security('AI settings updated', [
            'branch_id' => Auth::branchId(),
            'enabled' => $enabled,
        ]);

        Session::flash('success', 'AI settings saved.');
        Http::redirect('/settings/ai');
    }

    public function test(): void
    {
        try {
            AnthropicClient::forCurrentBranch()->createMessage([
                ['role' => 'user', 'content' => 'Reply with one word: OK'],
            ], '', [], 8);
        } catch (RuntimeException) {
            // Never leak the underlying exception message to the browser.
            $this->json(['ok' => false], 200);
        }

        $this->json(['ok' => true]);
    }
}
