<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\SchemaId;

/**
 * A request-body source class pinned with `#[SchemaId]`, so its recovered request-body component
 * carries a rename-stable diff identity — exercised by RecoveredRequestTest to prove the request
 * side honours the pin exactly as the response side does (`<pinned-id>#request`, never `<FQCN>#request`).
 */
#[SchemaId('thing.v1')]
final class PinnedRequestClass {}
