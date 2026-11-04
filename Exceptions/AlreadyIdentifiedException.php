<?php

namespace Modules\WordPressChat\Exceptions;

/**
 * The browser already has an identity on its latest conversation or,
 * without a visible conversation, on its session. The API answers 409
 * already_identified.
 */
class AlreadyIdentifiedException extends \RuntimeException
{
}
