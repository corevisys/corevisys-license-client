<?php

namespace CoreVisys\License\Events;

use CoreVisys\License\DTOs\LicenseStatus;

class LicenseChecked
{
    /**
     * @param LicenseStatus|null $status Current license snapshot, when available.
     * @param array $meta Extra context (e.g. ['reason' => ..., 'exception' => ...]).
     */
    public function __construct(
        public readonly ?LicenseStatus $status = null,
        public readonly array $meta = [],
    ) {
    }
}
