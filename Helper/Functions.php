<?php

namespace Ampersand\PatchHelper\Helper;

use Ampersand\PatchHelper\Helper\Functions;
/**
 * Class Functions
 * @author Alexandros Weigl
 */
class Functions
{
    /**
    * Returns true only if $string contains $contains
    *
    * @param $string
    * @param $contains
    * @return bool
    */
    public static function str_contains($string, $contains)
    {
        return strpos($string, $contains) !== false;
    }

    /**
    * Returns true only if $string starts with $startsWith
    *
    * @param $string
    * @param $startsWith
    * @return bool
    */
    public static function str_starts_with($string, $startsWith)
    {
        return substr($string, 0, strlen($startsWith)) === $startsWith;
    }

    /**
    * Returns true only if $string ends with $endsWith
    *
    * @param $string
    * @param $endsWith
    * @return bool
    */
    public static function str_ends_with($string, $endsWith)
    {
        return strlen($endsWith) == 0 || substr($string, -strlen($endsWith)) === $endsWith;
    }
}
