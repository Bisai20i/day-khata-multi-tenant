<?php

namespace App\Support\Permissions;

use RuntimeException;

/**
 * A refused ownership change (OwnershipTransfer). The message is written for
 * the person who asked for the change, so callers can show it as is (the
 * users page maps it to a validation error; the central owner reassignment
 * can do the same).
 */
class OwnershipTransferException extends RuntimeException {}
