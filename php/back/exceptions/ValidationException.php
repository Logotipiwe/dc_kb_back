<?php
/**
 * Created by PhpStorm.
 * User: germa
 * Date: 16.11.2019
 * Time: 23:45
 */

class ValidationException extends Exception
{
    protected $_options;

    public function __construct($options,string $message = "", int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->_options = $options;
    }

    /**
     * @param string $option
     * @return array
     */
    public function getOptions($option)
    {
        if(isset($this->_options[$option])){
            return $this->_options[$option];
        } else {
            return null;
        }
    }
}