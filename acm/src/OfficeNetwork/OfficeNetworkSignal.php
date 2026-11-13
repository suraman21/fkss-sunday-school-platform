<?php
/**
 * ACM S3 — office-network signal (design §D3.7).
 * ------------------------------------------------------------
 * A coarse environmental signal for code redemption: is this
 * request coming from a known office network? It CANNOT
 * establish physical presence (presence = in-person officer +
 * session + OTP); it only lets a deployment fail closed when a
 * redemption arrives from an unexpected network.
 *
 * - Enforcement flag `acm.office_network_enforced`, default OFF,
 *   until CIDRs are recorded AND tested.
 * - Allowlist: super_admin-managed `acm_office_networks` CIDRs.
 * - Proxy-safe client-IP resolution (Cloudflare in front): the
 *   client IP is accepted ONLY from the agreed header
 *   (CF-Connecting-IP preferred; no blind X-Forwarded-For
 *   trust) AND only when the TCP peer is in the trusted-proxy
 *   set (`acm.trusted_proxies`). Direct-connection deployments
 *   use the peer address. Anything else → IP unknown.
 * - Unknown IP + enforcement ON → deny (fail closed).
 * - Scope: redemption by default; issuance only when the
 *   deploy-time `acm.office_network_issue` policy is '1'
 *   (issuance already requires an authed office session).
 * IPv4 and IPv6 CIDR matching; invalid CIDRs rejected at entry.
 */

namespace SSMS\Acm\OfficeNetwork;

use PDO;

class OfficeNetworkSignal
{
    private PDO $pdo;

    /** Cached allowlist per instance (redemptions are rare). */
    private ?array $networks = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Resolve the client IP from server variables. Pure given the
     * trusted-proxy CSV: unit-testable without a database.
     *
     * @param array $server  $_SERVER (or a test double).
     * @param string $trustedProxiesCsv  Comma/space separated IPs/CIDRs.
     * @return string|null  Client IP, or null when unknown.
     */
    public static function resolveClientIp(array $server, string $trustedProxiesCsv): ?string
    {
        $peer = trim((string)($server['REMOTE_ADDR'] ?? ''));
        if ($peer === '' || filter_var($peer, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $proxies = preg_split('/[\s,]+/', trim($trustedProxiesCsv));
        $proxies = is_array($proxies) ? array_filter($proxies) : [];
        if ($proxies === []) {
            // Direct-connection deployment: the peer IS the client.
            return $peer;
        }
        $peerIsProxy = false;
        foreach ($proxies as $proxy) {
            if (self::ipInCidr($peer, $proxy)) {
                $peerIsProxy = true;
                break;
            }
        }
        if (!$peerIsProxy) {
            // Untrusted peer behind a proxied deployment: the peer
            // claims nothing, and no header is honored.
            return null;
        }
        $fromCf = trim((string)($server['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($fromCf !== '' && filter_var($fromCf, FILTER_VALIDATE_IP) !== false) {
            return $fromCf;
        }
        // No blind X-Forwarded-For trust: without the agreed header
        // the client address is unknown, never guessed.
        return null;
    }

    /**
     * True when $ip falls inside $cidr (plain IPs match exactly).
     * Invalid input matches nothing (fail closed). v4 + v6.
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $cidr === '') {
            return false;
        }
        if (strpos($cidr, '/') === false) {
            // Plain IP: compare packed bytes so equivalent IPv6
            // spellings (compressed vs full) still match.
            if (filter_var($cidr, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            $a = inet_pton($ip);
            $b = inet_pton($cidr);
            return $a !== false && $a === $b;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        if (filter_var($net, FILTER_VALIDATE_IP) === false || !ctype_digit((string)$bits)) {
            return false;
        }
        $bits = (int)$bits;
        $isV6 = strpos($ip, ':') !== false;
        if ($isV6 !== (strpos($net, ':') !== false)) {
            return false;
        }
        $max = $isV6 ? 128 : 32;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($net);
        if ($ipBin === false || $netBin === false) {
            return false;
        }
        $full = intdiv($bits, 8);
        $rest = $bits % 8;
        if (substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = 0xFF & (0xFF << (8 - $rest));
        return ((ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask));
    }

    /** Enforcement flag; missing/anything-but-'1' = OFF. */
    public function isEnforced(): bool
    {
        return $this->setting('acm.office_network_enforced') === '1';
    }

    /** Deploy-time policy: also gate issuance (default OFF). */
    public function gatesIssuance(): bool
    {
        return $this->setting('acm.office_network_issue') === '1';
    }

    public function trustedProxies(): string
    {
        return (string)$this->setting('acm.trusted_proxies');
    }

    /**
     * Redemption/issuance gate. Enforcement OFF → always true.
     * Enforcement ON → true only for a known IP inside an active
     * allowlisted CIDR. Unknown IP → false (fail closed).
     */
    public function allows(?string $clientIp): bool
    {
        if (!$this->isEnforced()) {
            return true;
        }
        if ($clientIp === null || $clientIp === '') {
            return false;
        }
        foreach ($this->activeNetworks() as $cidr) {
            if (self::ipInCidr($clientIp, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] Active allowlist CIDRs. */
    public function activeNetworks(): array
    {
        if ($this->networks !== null) {
            return $this->networks;
        }
        $this->networks = [];
        try {
            $stmt = $this->pdo->query(
                "SELECT cidr FROM acm_office_networks WHERE active = 1 ORDER BY id ASC LIMIT 500"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cidr) {
                $cidr = trim((string)$cidr);
                if ($cidr !== '') {
                    $this->networks[] = $cidr;
                }
            }
        } catch (\PDOException $e) {
            // Pre-082 database: no allowlist table yet → empty list
            // (fail closed when enforcement is on). Any other
            // database error propagates (500, fail closed).
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
            $this->networks = [];
        }
        return $this->networks;
    }

    private function setting(string $key): ?string
    {
        // No catch: a missing key returns false (flag OFF, the
        // legitimate pre-seed state); a genuine database error must
        // propagate (500, fail closed), never read as OFF.
        $stmt = $this->pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }
}
