<?php
/**
 * Language pill — the prototype's top-right selector on the welcome/login
 * screens (frontend/login.html .simple-header, fk-theme.css:623-634).
 *
 * Behaviour, copied from the prototype: one pill showing the CURRENT language,
 * a menu of the two options, closes on outside click. The difference is that
 * the prototype only relabelled itself and wrote to sessionStorage — it never
 * changed the language of the page. Here choosing a language sets the
 * `members_lang` cookie and reloads, so the server renders the other language.
 * Same control, same place, but it actually does what it looks like it does.
 *
 * @var bool $isAm
 */
declare(strict_types=1);

$isAm = $isAm ?? true;
?>
<div class="fk-simple-header">
    <div class="fk-lang-pill-wrap" style="position:absolute;right:14px;top:max(10px, env(safe-area-inset-top))">
        <button type="button"
                class="fk-lang-pill"
                id="lang-pill"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="lang-menu">
            <span aria-hidden="true">🌐</span>
            <span><?= $isAm ? 'አማ' : 'EN' ?></span>
        </button>

        <div class="fk-lang-menu" id="lang-menu" role="menu">
            <button type="button" role="menuitem" data-lang-choice="am"
                    aria-current="<?= $isAm ? 'true' : 'false' ?>">🇪🇹 አማርኛ</button>
            <button type="button" role="menuitem" data-lang-choice="en"
                    aria-current="<?= $isAm ? 'false' : 'true' ?>">🇬🇧 English</button>
        </div>
    </div>
</div>

<script>
/* Inline and tiny on purpose: this must work on the login screen, which is
   reachable with no session and must not depend on the API client having
   loaded. No data, no fetch. */
(function () {
    "use strict";
    var pill = document.getElementById("lang-pill");
    var menu = document.getElementById("lang-menu");
    if (!pill || !menu) { return; }

    pill.addEventListener("click", function (event) {
        event.stopPropagation();
        var open = menu.getAttribute("data-open") === "1";
        menu.setAttribute("data-open", open ? "0" : "1");
        pill.setAttribute("aria-expanded", open ? "false" : "true");
    });

    menu.addEventListener("click", function (event) { event.stopPropagation(); });

    document.addEventListener("click", function () {
        menu.setAttribute("data-open", "0");
        pill.setAttribute("aria-expanded", "false");
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            menu.setAttribute("data-open", "0");
            pill.setAttribute("aria-expanded", "false");
            pill.focus();
        }
    });

    Array.prototype.forEach.call(menu.querySelectorAll("[data-lang-choice]"), function (button) {
        button.addEventListener("click", function () {
            var lang = button.getAttribute("data-lang-choice") === "en" ? "en" : "am";
            /* 1 year, SameSite=Lax, path=/ so every /members page and the API
               see the same choice. Not Secure-only, because the dev server is
               plain HTTP; production is HTTPS and the cookie is not a secret. */
            document.cookie = "members_lang=" + lang + "; path=/; max-age=31536000; SameSite=Lax";
            window.location.reload();
        });
    });
})();
</script>
