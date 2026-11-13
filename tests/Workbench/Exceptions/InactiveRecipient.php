<?php

namespace Workbench\App\Exceptions;

use RuntimeException;

/**
 * A mail provider refusing an address it has marked inactive: the kind of
 * failure an application excludes from reporting, since retrying never
 * helps and there is nothing in its own code to fix.
 */
class InactiveRecipient extends RuntimeException
{
}
