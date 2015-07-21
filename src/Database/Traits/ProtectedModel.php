<?php
namespace Eloquence\Database\Traits;

trait ProtectedModel
{
    /**
     * Defines the fields that should be protected from being set directly. This is useful
     * for protecting invariants in your domain, or simply locking down an attribute from being
     * changed.
     *
     * @return array
     */
	abstract function protects();
}