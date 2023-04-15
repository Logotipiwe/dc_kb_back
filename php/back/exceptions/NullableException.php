<?php
/**
 * Created by PhpStorm.
 * User: germa
 * Date: 19.11.2019
 * Time: 22:56
 */

class NullableException extends Exception
{
    public function __construct(string $message = "", int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}