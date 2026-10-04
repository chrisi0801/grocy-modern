<?php

namespace Grocy\Services;

// Thrown when a budget may be seen but not changed by the current user - the
// API turns it into a 403 rather than a generic 400
class BudgetPermissionException extends \Exception
{
}
