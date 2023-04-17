<?php
/**
 * Created by PhpStorm.
 * User: germa
 * Date: 17.11.2019
 * Time: 16:06
 */

class AuthException extends Exception
{
    public function __construct(string $message = "", int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}