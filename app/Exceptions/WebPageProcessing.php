<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A cited page is being read in the background (a scanned PDF going through
 * OCR). Not a failure: the reader asks again shortly.
 */
class WebPageProcessing extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 5)
    {
        parent::__construct('This scanned PDF is being read with text recognition. It can take a minute.');
    }
}
