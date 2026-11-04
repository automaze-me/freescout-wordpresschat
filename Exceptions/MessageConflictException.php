<?php

namespace Modules\WordPressChat\Exceptions;

/**
 * A visitor re-sent a client_message_id that is already stored for their
 * session, but with a different text. The API answers 409.
 */
class MessageConflictException extends \RuntimeException
{
}
