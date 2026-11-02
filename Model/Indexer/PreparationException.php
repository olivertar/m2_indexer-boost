<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer;

/**
 * Failure before an Indexer Boost implementation writes any index row (snapshot or helper tables)
 *
 * Only this failure falls back to the core implementation: nothing of the index has been touched yet.
 */
class PreparationException extends \RuntimeException
{
}
