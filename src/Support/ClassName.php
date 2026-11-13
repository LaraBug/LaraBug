<?php

namespace LaraBug\Support;

class ClassName
{
    /**
     * An on-the-fly notification or mailable is an anonymous class, and
     * get_class returns its defining file and line after a null byte; the part
     * before it is the only stable name it has.
     *
     * Not cosmetic: the panel groups mail on md5 of this string and
     * notifications on md5 of it and the channel, so the untrimmed version puts
     * a server path and a line number into a rollup key — one group per
     * definition site, and it moves the moment the file does.
     */
    public static function normalised(string $class): string
    {
        $nul = strpos($class, "\0");

        return $nul === false ? $class : substr($class, 0, $nul);
    }
}
