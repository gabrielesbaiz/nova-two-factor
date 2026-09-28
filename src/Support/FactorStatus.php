<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "Does this user hold a confirmed factor?", asked once per request.
 *
 * The predicate itself is one cheap `exists` query. What is not cheap is how
 * often a single request asks it: `RequireTwoFactor` asks before challenging,
 * then `RequireTwoFactorEnrollment` asks again through `blocks()`, and once
 * more through whichever of `shouldRemind()` / `shouldWarn()` runs after it.
 * Three identical queries, and both middlewares are registered on
 * `nova.api_middleware` as well as `nova.middleware` — so a Nova page that
 * fires a dozen XHRs pays for all of them a dozen times over.
 *
 * Request-scoped rather than cached across requests, deliberately. Enrollment
 * state is a security decision: a stale "yes" is a user who should have been
 * challenged and was not, and no eviction policy is worth that. Within a single
 * request the answer cannot go stale without the package itself changing it,
 * and the events that change it flush this.
 */
class FactorStatus
{
    /** @var array<string, bool> */
    protected array $enabled = [];

    /**
     * Memoized against the callback the trait would otherwise have run.
     *
     * @param  callable(): bool  $resolver
     */
    public function enabled(Authenticatable $user, callable $resolver): bool
    {
        $key = $this->key($user);

        return $this->enabled[$key] ??= $resolver();
    }

    /**
     * Drop one user's answer. Called when the package changes their factors,
     * so the rest of the request sees what it just did.
     */
    public function forget(?Authenticatable $user): void
    {
        if ($user === null) {
            $this->flush();

            return;
        }

        unset($this->enabled[$this->key($user)]);
    }

    public function flush(): void
    {
        $this->enabled = [];
    }

    /**
     * Morph class as well as key: two models can hold the same identifier, and
     * on a multi-model panel they do.
     */
    protected function key(Authenticatable $user): string
    {
        $id = $user->getAuthIdentifier();

        return $user->getMorphClass().'|'.(is_scalar($id) ? (string) $id : spl_object_hash($user));
    }
}
