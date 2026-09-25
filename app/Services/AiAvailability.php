<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Whether the AI assistant is usable for the current branch: gated on both
 * the per-branch on/off switch and an API key actually being stored. Neither
 * flag alone is enough — a branch can have `ai_enabled` on with no key saved
 * yet (the seed migration ships every branch that way), and a key can be
 * present while the feature is deliberately switched off. Callers that would
 * otherwise reach for `App\Core\Crypto::decrypt()` and call the Anthropic API
 * should check this first.
 */
final class AiAvailability
{
    public static function enabledForCurrentBranch(): bool
    {
        return Settings::get('ai_enabled', false) === true
            && Settings::string('ai_anthropic_api_key_encrypted', '') !== '';
    }
}
