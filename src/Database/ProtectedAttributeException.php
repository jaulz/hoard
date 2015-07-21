<?php
namespace Eloquence\Database;

class ProtectedAttributeException
{
    public function __construct($key)
    {
        $this->message = "The attribute [$key] is protected from being set directly. Use
            the associated methods on your model to set the attribute's value.";
	}
}