<?php
declare(strict_types=1);

namespace MembersPortal\Http;

/**
 * One addressable page. A value object — no behaviour beyond accessors.
 *
 * $roles is the COMPLETE list of roles allowed here. There is no deny-list
 * and there must never be one: the audit (§9.1) found that the two routes
 * which leaked to member/parent tokens were precisely the two that either
 * had no gate or named the roles to refuse. An allow-list fails closed for a
 * role nobody has invented yet. A deny-list does not.
 */
final class Route
{
    private string $slug;
    private string $view;
    private string $title;
    private array $roles;
    private bool $requiresSession;

    /**
     * @param string   $slug            clean URL below /members: '' or '/login'
     * @param string   $view            template name below templates/pages/
     * @param string   $title           <title> text, already human-readable
     * @param string[] $roles           the ONLY roles allowed in
     * @param bool     $requiresSession false only for public pages
     */
    public function __construct(
        string $slug,
        string $view,
        string $title,
        array $roles,
        bool $requiresSession
    ) {
        $this->slug = $slug;
        $this->view = $view;
        $this->title = $title;
        $this->roles = $roles;
        $this->requiresSession = $requiresSession;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function view(): string
    {
        return $this->view;
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return string[] */
    public function roles(): array
    {
        return $this->roles;
    }

    public function requiresSession(): bool
    {
        return $this->requiresSession;
    }
}
