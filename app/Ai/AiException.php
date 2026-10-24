<?php

namespace App\Ai;

use RuntimeException;

/** A provider call failed. The message is safe to show to the user (no keys, no raw bodies). */
class AiException extends RuntimeException {}
