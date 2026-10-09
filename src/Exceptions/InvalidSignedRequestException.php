<?php

namespace Pr4w\SocialTokens\Exceptions;

use InvalidArgumentException;

/**
 * A Meta signed_request that does not verify: malformed, signed with another
 * algorithm, or not signed with the app secret. Never act on its payload.
 */
class InvalidSignedRequestException extends InvalidArgumentException {}
