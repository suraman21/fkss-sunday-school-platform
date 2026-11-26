<?php
declare(strict_types=1);

namespace MembersPortal\Support;

use MembersPortal\Auth\RoleGate;
use MembersPortal\Auth\Session;
use MembersPortal\Http\Response;
use MembersPortal\Http\Route;

/**
 * Runs one page: gate, render, and turning a failure into a page.
 *
 * An object rather than free functions so a test can build one around a
 * session it chose, without touching $_SESSION, and so there is exactly one
 * place where an exception becomes an HTTP response.
 */
final class Bootstrap
{
    /** @var array{logged_in:bool,id:int,username:string,role:string,full_name:string} */
    private array $session;

    private function __construct(array $session)
    {
        $this->session = $session;
    }

    /** Real entry point: snapshot the platform session. Read-only. */
    public static function create(): self
    {
        return new self(Session::current());
    }

    /**
     * Build a bootstrap around a session that was supplied, not read.
     *
     * For tests, and for the sign-in flow once it exists: it lets a caller
     * render any page for any role without a session store, which is the
     * only way to exercise "a teacher opens a member page" deterministically.
     *
     * @param array<string,mixed> $session any array Session::fromArray accepts
     */
    public static function withSession(array $session): self
    {
        return new self(Session::fromArray($session));
    }

    /**
     * The normal path for a page.
     *
     * An entry file calls this with the slug it was written for. If the
     * visitor is not signed in, they see the sign-in page; if they are signed
     * in with a role this portal does not serve (staff), they are told so;
     * anything else about the session is refused by RoleGate.
     */
    public function run(string $slug): void
    {
        try {
            $page = $this->route($slug);
            RoleGate::assertAllowed($page, $this->session);
        } catch (HttpException $error) {
            $this->renderError($error);
            return;
        }

        $this->render($page);
    }

    /** @throws HttpException */
    private function route(string $slug): Route
    {
        return \MembersPortal\Http\Router::route($slug);
    }

    private function render(Route $page): void
    {
        Response::page($page->view(), [
            'session'      => $this->session,
            'current_slug' => $page->slug(),
        ], $page->title());
    }

    /**
     * Render a page by name without a gate. Entry files must NOT use this:
     * it exists for the two pages a refusal can land on.
     */
    public function renderSignIn(?string $notice = null): void
    {
        Response::page('sign-in', [
            'session'      => $this->session,
            'current_slug' => '/login',
            'notice'       => $notice,
        ], 'Sign in');
    }

    /** Turn a refusal into the right page for that status. */
    public function renderError(HttpException $error): void
    {
        $status = $error->status();

        // 401 is "sign in first", not "you may not". The gate never decides
        // where to send anyone; that decision belongs here, once.
        if ($status === 401) {
            $this->renderSignIn($error->getMessage());
            return;
        }

        Response::page('error', [
            'session' => $this->session,
            'status'  => $status,
            'message' => $error->getMessage(),
        ], 'Members', $status);
    }
}
