<?php

namespace App\Submission;

use RuntimeException;

/** A submission step was refused by deterministic rules (permission, state, autopilot level). */
class SubmissionRefused extends RuntimeException {}
