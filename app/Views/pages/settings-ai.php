<?php

/**
 * AI settings — enable/disable AI features and store the Anthropic API key
 * used to reach them. The key itself is never rendered here, only whether
 * one is configured.
 *
 * @var bool                       $aiEnabled
 * @var bool                       $hasApiKey
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Core\Csrf;

$errors = $errors ?? [];
$aiEnabled = $aiEnabled ?? false;
$hasApiKey = $hasApiKey ?? false;
?>
<div class="max-w-2xl"
     x-data="{
         testing: false,
         result: null,
         async testConnection() {
             this.testing = true;
             this.result = null;
             try {
                 const response = await fetch('<?= e(url('/settings/ai/test')) ?>', {
                     method: 'POST',
                     headers: {
                         'X-CSRF-Token': '<?= e(Csrf::token()) ?>',
                         'Accept': 'application/json',
                     },
                 });
                 const data = await response.json();
                 this.result = data.ok
                     ? { ok: true, message: 'Connection succeeded.' }
                     : { ok: false, message: 'Connection failed. Check the API key and try again.' };
             } catch (e) {
                 this.result = { ok: false, message: 'Connection failed. Check the API key and try again.' };
             } finally {
                 this.testing = false;
             }
         }
     }">

    <div class="card p-6">
        <h2 class="font-display text-base font-semibold tracking-tight text-ink">Anthropic API key</h2>
        <p class="mt-1 text-xs text-slate-500">Used to power AI features for this branch. Stored encrypted.</p>

        <div class="mt-4">
            <?php if ($hasApiKey) : ?>
                <p class="text-sm text-slate-700">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull; (configured)</p>
            <?php else : ?>
                <p class="text-sm text-slate-500">No API key configured.</p>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="<?= e(url('/settings/ai')) ?>" class="mt-5" novalidate>
        <?= csrf_field() ?>

        <div class="card p-6">
            <div>
                <label for="api_key" class="label">API key</label>
                <input id="api_key" name="api_key" type="password" maxlength="200" autocomplete="off"
                       placeholder="Enter a new key to replace the current one, or leave blank to keep it"
                       class="<?= isset($errors['api_key']) ? 'input-error' : 'input' ?>"
                       <?= isset($errors['api_key']) ? 'aria-invalid="true" aria-describedby="api_key-error"' : '' ?>>
                <?php if (isset($errors['api_key'][0])) : ?>
                    <p id="api_key-error" class="error"><?= e($errors['api_key'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-5 flex items-center gap-2.5">
                <input id="enabled" name="enabled" type="checkbox" value="1"
                       <?= $aiEnabled ? 'checked' : '' ?>
                       class="h-4 w-4 rounded border-slate-300 text-brand-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                <label for="enabled" class="text-sm font-medium text-ink">Enable AI features for this branch</label>
            </div>
        </div>

        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
            <p class="flex-1 text-xs text-slate-500">Changes are recorded against your account.</p>
            <button type="button" x-on:click="testConnection()" x-bind:disabled="testing" class="btn-secondary">
                <span x-show="!testing">Test connection</span>
                <span x-show="testing" x-cloak>Testing&hellip;</span>
            </button>
            <a href="<?= e(url('/settings')) ?>" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary">Save AI settings</button>
        </div>

        <p class="mt-3 text-sm" aria-live="polite"
           x-show="result !== null"
           x-bind:class="result && result.ok ? 'text-emerald-600' : 'text-red-600'"
           x-text="result ? result.message : ''"
           x-cloak></p>
    </form>
</div>
