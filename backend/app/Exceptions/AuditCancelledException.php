<?php

namespace App\Exceptions;

/**
 * Thrown when a report's `cancel_requested_at` is set while a Google Ads fetch is in progress.
 */
class AuditCancelledException extends \RuntimeException {}
