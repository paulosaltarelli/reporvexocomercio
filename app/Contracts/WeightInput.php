<?php

namespace App\Contracts;

/** Validates a weight measurement before it enters a sale. Device acquisition stays outside checkout. */
interface WeightInput
{
    public function quantity(string $measurement): string;
}
