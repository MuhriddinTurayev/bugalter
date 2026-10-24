<?php

namespace App\Portal;

use RuntimeException;

/** A portal step failed. The message is localised and safe to show; it never carries secrets. */
class PortalException extends RuntimeException {}
