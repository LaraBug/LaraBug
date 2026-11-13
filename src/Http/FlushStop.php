<?php

namespace LaraBug\Http;

/**
 * Why a flush of the retry buffer ended before the buffer was empty.
 */
enum FlushStop: string
{
    /** Another process is flushing this buffer right now. */
    case Locked = 'locked';

    /** The server asked, with Retry-After, not to be sent anything yet. */
    case Paused = 'paused';

    /** The server could not be reached. */
    case Unreachable = 'unreachable';

    /** The server answered with a 5xx again. */
    case ServerError = 'server_error';

    /** The server answered 429, so the rest waits for the rate limit window. */
    case RateLimited = 'rate_limited';

    /** The flush sent as many events as it was allowed to in one go. */
    case Limit = 'limit';
}
