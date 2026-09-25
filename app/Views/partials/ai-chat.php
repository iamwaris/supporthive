<?php

/**
 * AI chat widget: a floating launcher + slide-out panel available on every
 * authenticated page, mirroring the mobile-nav off-canvas pattern already
 * used in layouts/app.php (fixed overlay + x-show + Escape to close).
 *
 * Gated the same simple way sidebar.php gates its own items (a direct
 * boolean check, not threaded through every controller's view data) — this
 * is the "AiAvailability::enabledForCurrentBranch() called straight from the
 * partial" option, since there is no existing shared view-global-data hook
 * to attach it to.
 *
 * History lives only in Alpine's in-memory state for this page load — never
 * persisted, never sent anywhere but back to /ai/chat on the next question.
 */

declare(strict_types=1);

use App\Core\Csrf;
use App\Services\AiAvailability;

if (!AiAvailability::enabledForCurrentBranch()) {
    return;
}
?>
<div x-data="aiChatWidget" x-cloak
     data-chat-url="<?= e(url('/ai/chat')) ?>"
     data-csrf-token="<?= e(Csrf::token()) ?>">
    <button type="button" x-on:click="open = true" x-show="!open"
            class="fixed bottom-5 right-5 z-30 flex h-13 w-13 items-center justify-center rounded-full bg-brand-500 text-ink shadow-lg hover:bg-brand-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
            aria-haspopup="dialog" aria-label="Ask the AI assistant about your finances or the app">
        <svg class="h-5.5 w-5.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M8 10.5h.01"></path><path d="M12 10.5h.01"></path><path d="M16 10.5h.01"></path>
            <path d="M4 5h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H9l-4.5 4V16H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"></path>
        </svg>
    </button>

    <div x-show="open" class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="AI assistant">
        <div class="fixed inset-0 bg-ink/50" x-on:click="open = false" aria-hidden="true"></div>

        <div class="relative z-10 flex h-full w-full max-w-lg flex-col bg-white shadow-xl sm:m-3 sm:h-[calc(100%-1.5rem)] sm:rounded-2xl"
             x-on:keydown.escape.window="open = false">

            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-3.5">
                <h2 class="font-display text-sm font-semibold text-ink">Ask about your finances or the app</h2>
                <button type="button" x-on:click="open = false" aria-label="Close"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12"></path><path d="M18 6L6 18"></path>
                    </svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4" x-ref="scrollArea">
                <div aria-live="polite" class="flex flex-col gap-4">
                    <template x-if="history.length === 0">
                        <p class="text-sm text-slate-500">
                            Ask things like &ldquo;What was our net profit last month?&rdquo; or
                            &ldquo;How do I record a partner contribution?&rdquo;
                        </p>
                    </template>

                    <template x-for="(pair, index) in history" x-bind:key="index">
                        <div class="flex flex-col gap-2">
                            <!-- User's own typed question: arbitrary user input, never rendered as HTML. -->
                            <p class="self-end max-w-[85%] rounded-2xl rounded-br-sm bg-brand-500 px-3.5 py-2 text-sm text-ink" x-text="pair.question"></p>
                            <!--
                                x-html here is safe only because formatAnswer() (app.js) HTML-escapes
                                the entire raw model answer before applying its narrow markdown-style
                                allowlist (bold/lists/paragraphs) — see aiChatFormatAnswer's doc comment
                                in app.js. This is the one place in the app allowed to bind raw HTML,
                                and only because that guarantee holds.
                            -->
                            <div class="self-start max-w-[85%] rounded-2xl rounded-bl-sm bg-slate-100 px-3.5 py-2 text-sm text-ink" x-html="formatAnswer(pair.answer)"></div>
                        </div>
                    </template>

                    <p x-show="loading" class="self-start flex items-center gap-2 rounded-2xl rounded-bl-sm bg-slate-100 px-3.5 py-2 text-sm text-slate-500">
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M21 12a9 9 0 1 1-9-9"></path>
                        </svg>
                        Thinking&hellip;
                    </p>
                </div>
            </div>

            <div class="shrink-0 border-t border-slate-200 px-4 py-3">
                <p x-show="error" x-text="error" role="alert" class="mb-2 text-xs text-bad-text"></p>
                <form x-on:submit.prevent="ask()" class="flex items-end gap-2">
                    <div class="flex-1">
                        <label for="ai_chat_question" class="sr-only">Your question</label>
                        <textarea id="ai_chat_question" x-model="question" rows="1" maxlength="500"
                                  x-on:keydown.enter.prevent="ask()"
                                  x-bind:disabled="loading"
                                  placeholder="Ask a question&hellip;"
                                  class="input resize-none"></textarea>
                    </div>
                    <button type="submit" x-bind:disabled="loading || question.trim().length === 0"
                            class="btn btn-primary shrink-0" aria-label="Send question">
                        Ask
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
