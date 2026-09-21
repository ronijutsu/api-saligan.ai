<?php

namespace App\Exceptions;

/**
 * A cited PDF that is only page images. It has no text to read until it has
 * been through OCR.
 */
class ScannedPdf extends UnreadableWebPage
{
    public function __construct()
    {
        parent::__construct(self::scanned()->getMessage());
    }
}
