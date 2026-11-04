<?php

namespace Modules\WordPressChat\Exceptions;

/**
 * The browser already has an identity on its latest conversation (or none
 * to attach one to). The API answers 409 already_identified.
 */
class AlreadyIdentifiedException extends \RuntimeException
{
}
