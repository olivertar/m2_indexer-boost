<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Fixture;

use Magento\Framework\Lock\Backend\Database;

/**
 * The real database lock backend.
 *
 * The integration framework replaces Magento\Framework\Lock\Backend\Database with a DummyLocker whose isLocked()
 * is always false; preferences apply to the exact class, so this subclass keeps the real GET_LOCK/IS_USED_LOCK.
 */
class DatabaseLock extends Database
{
}
