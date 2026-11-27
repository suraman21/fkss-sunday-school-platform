<?php
/**
 * Members app — request path resolution under different deployment mounts.
 *
 * WHAT THIS PROTECTS
 *   The app is served from a folder, /members, and its own .htaccess rewrites
 *   every URL to public/index.php. Apache then sets SCRIPT_NAME to the path
 *   AFTER that rewrite: /members/public/index.php.
 *
 *   The first implementation took dirname(SCRIPT_NAME) as the mount prefix,
 *   which yields /members/public — a prefix "/members/home" does not start
 *   with. Nothing was stripped, the router (which only knows "/home") matched
 *   nothing, and every page 404'd at the one URL the app advertises.
 *
 *   That bug was invisible to every static check in the repository, because
 *   nothing here ran PHP. These tests do.
 *
 * MEASURED, BEFORE THE FIX (php-wasm, PHP 8.4.1):
 *   REQUEST_URI=/members/home  SCRIPT_NAME=/members/public/index.php
 *     -> resolved path "/members/home"   (expected "/home")
 *
 * Each test below is a deployment shape that must keep working. Adding one is
 * cheap; losing one is a site-wide 404.
 */

declare(strict_types=1);

namespace SSMS\Members\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SSMS\Members\Http\Request;

final class RequestPathTest extends TestCase
{
    /** Resolve a path through the real fromGlobals(), as the server would. */
    private function resolve(string $uri, string $scriptName, array $get = []): string
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => $uri,
            'SCRIPT_NAME'    => $scriptName,
        ];
        $_GET = $get;

        return Request::fromGlobals()->path();
    }

    // -----------------------------------------------------------------
    // The advertised mount: /members, via members/.htaccess
    // -----------------------------------------------------------------

    /**
     * THE REGRESSION. This exact pair of values produced "/members/home"
     * before the fix and returned 404 on every page.
     */
    public function test_folder_mount_with_rewritten_script_name(): void
    {
        $this->assertSame(
            '/home',
            $this->resolve('/members/home', '/members/public/index.php')
        );
    }

    public function test_folder_mount_root_lands_on_the_welcome_page(): void
    {
        // "/members" and "/members/" both mean the route "/".
        $this->assertSame('/', $this->resolve('/members/', '/members/public/index.php'));
        $this->assertSame('/', $this->resolve('/members', '/members/public/index.php'));
    }

    public function test_folder_mount_nested_routes_survive(): void
    {
        $this->assertSame('/lessons', $this->resolve('/members/lessons', '/members/public/index.php'));
        $this->assertSame('/lesson/7', $this->resolve('/members/lesson/7', '/members/public/index.php'));
        $this->assertSame('/grades', $this->resolve('/members/grades', '/members/public/index.php'));
    }

    public function test_folder_mount_with_index_php_in_the_url(): void
    {
        $this->assertSame('/home', $this->resolve('/members/index.php/home', '/members/public/index.php'));
    }

    public function test_query_string_is_not_part_of_the_path(): void
    {
        $this->assertSame('/home', $this->resolve('/members/home?x=1', '/members/public/index.php'));
    }

    // -----------------------------------------------------------------
    // Other shapes that must not break
    // -----------------------------------------------------------------

    public function test_script_name_equal_to_the_request_path(): void
    {
        // Some configurations leave SCRIPT_NAME as the requested path.
        $this->assertSame('/home', $this->resolve('/members/home', '/members/home'));
    }

    public function test_document_root_is_members_public(): void
    {
        $this->assertSame('/home', $this->resolve('/home', '/index.php'));
        $this->assertSame('/', $this->resolve('/', '/index.php'));
    }

    public function test_document_root_is_the_members_folder(): void
    {
        $this->assertSame('/home', $this->resolve('/home', '/members/index.php'));
    }

    public function test_dev_server_with_an_explicit_router(): void
    {
        // php -S -t . members/public/index.php: SCRIPT_NAME is the router file.
        $this->assertSame(
            '/home',
            $this->resolve('/members/home', '/members/public/index.php')
        );
    }

    // -----------------------------------------------------------------
    // Prefix matching must respect segment boundaries
    // -----------------------------------------------------------------

    /**
     * A naive str_starts_with("/members") would turn "/membership" into "ship"
     * and serve the wrong route — or 404 confusingly. The trim only happens on
     * a "/" boundary.
     */
    public function test_lookalike_prefixes_are_not_trimmed(): void
    {
        $this->assertSame('/membership', $this->resolve('/membership', '/members/public/index.php'));
        $this->assertSame(
            '/membersfoo/home',
            $this->resolve('/membersfoo/home', '/members/public/index.php')
        );
    }

    /**
     * A traversal attempt must not resolve to a real route. It must reach the
     * router as a literal path and 404 there, rather than being normalised
     * into "/admin/dashboard.php" by anything in this class.
     */
    public function test_traversal_is_not_normalised_into_a_route(): void
    {
        $path = $this->resolve('/members/../admin/dashboard.php', '/members/public/index.php');
        $this->assertNotSame('/admin/dashboard.php', $path);
        $this->assertStringContainsString('..', $path);
    }

    public function test_route_query_parameter_still_wins(): void
    {
        // The ?route= fallback predates this work and must keep working.
        $this->assertSame('/home', $this->resolve('/members/home', '/members/public/index.php', ['route' => '/home']));
    }

    public function test_an_empty_script_name_changes_nothing(): void
    {
        $this->assertSame('/members/home', $this->resolve('/members/home', ''));
    }
}
