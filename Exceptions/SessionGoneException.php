<?php

namespace Modules\WordPressChat\Exceptions;

/**
 * The visitor's session row was deleted while a request was using it: by
 * the orphan cleanup (VisitorAuth::purgeOrphans()) or with its website
 * (Site::purgeVisitorData()). Thrown inside a transaction when the session
 * lock comes back empty, so nothing is written. The API answers 401
 * unauthorized, as for a token that names no session, and the widget drops
 * the token.
 */
class SessionGoneException extends \RuntimeException
{
}
