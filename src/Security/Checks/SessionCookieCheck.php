<?php

namespace LaraBug\Security\Checks;

/**
 * The flags the session cookie is sent with. The cookie's name, domain and
 * path stay here.
 */
class SessionCookieCheck implements PostureCheck
{
    /** @var array<int, string> */
    protected array $sameSiteValues = ['lax', 'strict', 'none'];

    /**
     * @return array{secure: bool, http_only: bool, same_site: ?string}
     */
    public function collect(): array
    {
        return [
            // Laravel sends the cookie without the flag unless it is set, and
            // SESSION_SECURE_COOKIE is unset in a new application.
            'secure' => (bool) config('session.secure', false),
            'http_only' => (bool) config('session.http_only', true),
            'same_site' => $this->sameSite(),
        ];
    }

    /**
     * One of the values a browser understands, `invalid` for anything else
     * and null when none is set.
     */
    protected function sameSite(): ?string
    {
        $sameSite = config('session.same_site');

        if ($sameSite === null || $sameSite === '') {
            return null;
        }

        if (! is_string($sameSite)) {
            return 'invalid';
        }

        $sameSite = strtolower($sameSite);

        return in_array($sameSite, $this->sameSiteValues, true) ? $sameSite : 'invalid';
    }
}
