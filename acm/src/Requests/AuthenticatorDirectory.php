<?php
/**
 * ACM — login identifiers beyond `users.username`.
 * ------------------------------------------------------------
 * Design §D3: a member may choose a username at activation; if they
 * choose none, their member code (the "ID number") logs them in. Both
 * must work, and the password remains the only secret.
 *
 * THE PROBLEM THIS CLASS EXISTS TO SOLVE
 *   Two identifier namespaces (usernames, member codes) that both map
 *   to `users.id` is an account-takeover vector if they can ever
 *   collide. `users.username` has a UNIQUE key; member codes do not
 *   live in `users` at all. A member code like "M0001" could equal
 *   another user's username, and a naive resolver that tries the
 *   username table first would let one person log in as another.
 *
 * THE SOLUTION
 *   One table (migration 101), one UNIQUE key on a hash of the
 *   normalized identifier, covering EVERY kind. Registering an
 *   identifier that already exists — in any namespace — fails. The
 *   database owns the invariant; this class only reports it.
 *
 * WHAT THIS CLASS DOES NOT DO
 *   It does not verify passwords and it does not issue tokens. It
 *   answers one question: "which user, if any, owns this identifier?"
 *   The login route keeps its existing password + device logic and
 *   calls this only after the username lookup misses.
 *
 * SECURITY NOTES
 *   - The identifier is hashed before storage, so this table is not a
 *     login directory if it leaks.
 *   - Lookups are constant-work: one indexed hash comparison, no
 *     LIKE, no partial match. A timing difference between "no such
 *     identifier" and "wrong password" is the only oracle, and the
 *     login route already answers both with the same generic error.
 *   - Registration is idempotent per user: re-registering the same
 *     identifier for the same user is a no-op, so a retried activation
 *     does not fail.
 */

declare(strict_types=1);

namespace SSMS\Acm\Requests;

use InvalidArgumentException;
use PDO;

final class AuthenticatorDirectory
{
    public const KIND_USERNAME    = 'username';
    public const KIND_MEMBER_CODE = 'member_code';
    public const KIND_GUARDIAN    = 'guardian_ref';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Normalize an identifier for hashing. Case-insensitive and
     * separator-insensitive so "M-0001", "m0001" and "M 0001" are the
     * same login, which is what a student typing on a phone expects.
     */
    public static function normalize(string $identifier): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', trim($identifier)));
    }

    public static function hash(string $identifier): string
    {
        return hash('sha256', self::normalize($identifier));
    }

    /**
     * Bind an identifier to a user. Throws when another user already
     * owns it — including across namespaces. Idempotent for the same
     * user.
     *
     * @throws InvalidArgumentException when the identifier is taken
     */
    public function register(int $userId, string $identifier, string $kind): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('A user is required.');
        }
        if (!in_array($kind, [self::KIND_USERNAME, self::KIND_MEMBER_CODE, self::KIND_GUARDIAN], true)) {
            throw new InvalidArgumentException('Unknown identifier kind.');
        }
        $normalized = self::normalize($identifier);
        if ($normalized === '' || strlen($normalized) > 64) {
            throw new InvalidArgumentException('That identifier cannot be used to sign in.');
        }
        $hash = self::hash($normalized);

        // INSERT-first: the UNIQUE key is the arbiter, exactly as with
        // the request dedupe. A pre-check would leave the race open
        // between two activations finishing in the same millisecond.
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO acm_authenticators (user_id, identifier_hash, kind)'
                . ' VALUES (:uid, :h, :k)'
            );
            $stmt->execute([':uid' => $userId, ':h' => $hash, ':k' => $kind]);
            return;
        } catch (\PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
        }

        // Someone owns it. Same user + same kind = idempotent replay.
        $owner = $this->ownerOf($normalized);
        if ($owner !== null && (int)$owner['user_id'] === $userId) {
            return;
        }
        throw new InvalidArgumentException('That identifier is already in use. Choose another.');
    }

    /**
     * Which user owns this identifier, in ANY namespace?
     *
     * @return array{user_id:int,kind:string}|null
     */
    public function ownerOf(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, kind FROM acm_authenticators WHERE identifier_hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => self::hash($identifier)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return ['user_id' => (int)$row['user_id'], 'kind' => (string)$row['kind']];
    }

    /**
     * Resolve a login identifier to a user id, or null.
     *
     * Deliberately returns null rather than throwing: the login route
     * must not distinguish "unknown identifier" from "known identifier,
     * wrong password".
     */
    public function resolveUserId(string $identifier): ?int
    {
        $normalized = self::normalize($identifier);
        if ($normalized === '') {
            return null;
        }
        $owner = $this->ownerOf($normalized);
        return $owner !== null ? (int)$owner['user_id'] : null;
    }

    /**
     * Every identifier kind a user has, so the profile screen can show
     * "you can sign in with: m0001".
     *
     * @return array<int,string>
     */
    public function kindsFor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT kind FROM acm_authenticators WHERE user_id = :uid ORDER BY kind ASC'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /**
     * Attach a chosen username to an EXISTING account, refusing one
     * another user owns. Used by the activation credentials step when
     * the member supplies a username (design D3), and by the profile
     * screen's one-time username change.
     *
     * The caller is responsible for also updating users.username; this
     * method only owns the cross-namespace collision authority.
     *
     * @throws InvalidArgumentException when taken
     */
    public function registerUsername(int $userId, string $username): void
    {
        $this->register($userId, $username, self::KIND_USERNAME);
    }

    /**
     * Make the member code a login identifier for this user. Called at
     * activation when the member chooses NOT to set a username.
     *
     * Silently tolerates the case where the code is already bound to
     * this same user (retried activation), and refuses when bound to
     * someone else — which would mean the member record was reused.
     *
     * @throws InvalidArgumentException when another user owns the code
     */
    public function registerMemberCode(int $userId, string $memberCode): void
    {
        $this->register($userId, $memberCode, self::KIND_MEMBER_CODE);
    }
}
