<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

use RuntimeException;

/**
 * Base class of every exception the package throws, so a host can catch them
 * all in one place. Messages are developer-facing English; user-facing text
 * travels as translated violation messages.
 */
abstract class OpeningHoursException extends RuntimeException {}
