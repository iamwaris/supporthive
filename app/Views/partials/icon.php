<?php

/**
 * Inline stroke icons. Inline rather than an icon font or sprite so they take
 * currentColor and need no extra request; drawn in the Lucide style to match
 * the vendored set used elsewhere.
 *
 * @var array{icon:string} $item
 */

declare(strict_types=1);

$paths = [
    'grid'    => '<rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect>',
    'receipt' => '<path d="M4 4h12l4 4v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"></path><path d="M8 12h8"></path><path d="M8 16h5"></path>',
    'trend'   => '<path d="M3 17l6-6 4 4 7-7"></path><path d="M14 8h6v6"></path>',
    'swap'    => '<path d="M7 7h13l-3-3"></path><path d="M17 17H4l3 3"></path>',
    'list'    => '<path d="M4 6h16"></path><path d="M4 12h16"></path><path d="M4 18h10"></path>',
    'card'    => '<rect x="3" y="6" width="18" height="13" rx="2"></rect><path d="M3 10h18"></path><path d="M16 15h2"></path>',
    'bars'    => '<path d="M12 20V10"></path><path d="M6 20v-6"></path><path d="M18 20V4"></path>',
    'users'   => '<circle cx="9" cy="8" r="3.2"></circle><path d="M3 20a6 6 0 0 1 12 0"></path><path d="M16 5.5a3 3 0 0 1 0 5.6"></path><path d="M18 20a5.6 5.6 0 0 0-2-4.3"></path>',
    'coins'   => '<path d="M12 3v18"></path><path d="M8 7h5.5a2.5 2.5 0 0 1 0 5H10a2.5 2.5 0 0 0 0 5H16"></path>',
    'doc'     => '<path d="M5 3h9l5 5v13H5z"></path><path d="M14 3v5h5"></path>',
    'sliders' => '<path d="M4 7h10"></path><path d="M18 7h2"></path><path d="M4 17h4"></path><path d="M12 17h8"></path><circle cx="16" cy="7" r="2"></circle><circle cx="10" cy="17" r="2"></circle>',
    'repeat'  => '<path d="M17 2l4 4-4 4"></path><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><path d="M7 22l-4-4 4-4"></path><path d="M21 13v2a4 4 0 0 1-4 4H3"></path>',
];

$key = (string) ($item['icon'] ?? '');
?>
<svg class="h-4.5 w-4.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <?= $paths[$key] ?? $paths['grid'] /* Fixed literal markup, not user input. */ ?>
</svg>
