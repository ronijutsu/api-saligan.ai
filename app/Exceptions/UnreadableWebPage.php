<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A cited web page that could not be turned into readable text, with a
 * message that is safe and useful to show the reader.
 */
class UnreadableWebPage extends RuntimeException
{
    public static function unreachable(): self
    {
        return new self('This page could not be reached from here. Use Go to link to open the original.');
    }

    public static function unsupported(): self
    {
        return new self('This link is not a web page or PDF that can be read here. Use Go to link to open the original.');
    }

    public static function scanned(): self
    {
        return new self('This PDF is a scan with no selectable text, so it cannot be read or digested here. Use Go to link to open the original.');
    }

    public static function tooLong(): self
    {
        return new self('This scanned PDF is too long to read here. Use Go to link to open the original.');
    }

    public static function unrecognised(): self
    {
        return new self('This scanned PDF could not be read, even with text recognition. Use Go to link to open the original.');
    }

    public static function empty(): self
    {
        return new self('This page has no readable text, usually because it is built with scripts. Use Go to link to open the original.');
    }
}
