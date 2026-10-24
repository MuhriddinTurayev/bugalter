<?php

namespace App\Submission\Gateway;

/**
 * A gateway whose delivery is carried out later by the company's desktop agent, in the accountant's own browser,
 * signed by the official E-IMZO app with the password the accountant types there. SubmissionOrchestrator hands the
 * approved report to App\Agent\AgentTasks instead of signing anything itself.
 */
interface DeliversThroughAgent {}
