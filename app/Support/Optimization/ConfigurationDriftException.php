<?php

namespace App\Support\Optimization;

use RuntimeException;

/**
 * Raised when a managed file changed on the server between a plan being drawn up
 * and applied.
 *
 * The plan's proposals were reasoned about the file as it was; applying them now
 * would discard whatever was done in the meantime without anyone seeing it.
 */
class ConfigurationDriftException extends RuntimeException {}
