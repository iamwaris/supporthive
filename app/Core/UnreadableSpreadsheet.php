<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * An uploaded spreadsheet could not be read. The message is written for the
 * person who uploaded it ("save it as .csv and try again") and never carries
 * anything internal, so a controller may show it as it is.
 */
final class UnreadableSpreadsheet extends RuntimeException
{
}
