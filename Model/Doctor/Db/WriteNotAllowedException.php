<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Db;

/**
 * Thrown when a doctor check tries to run a statement that is not read-only
 */
class WriteNotAllowedException extends \LogicException
{
}
