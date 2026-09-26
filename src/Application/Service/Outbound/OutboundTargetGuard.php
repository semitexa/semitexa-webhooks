<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

/**
 * Decides whether a webhook target URL may be called, and pins the address
 * that was checked.
 *
 * A target URL is configuration, but it names where signed requests go: one
 * pointing at loopback, a private network or a cloud metadata address
 * (169.254.169.254) turns the delivery worker into a request forger inside
 * the perimeter. Every address the host resolves to must be public, and the
 * one returned is the one cURL connects to (CURLOPT_RESOLVE), so DNS cannot
 * answer differently between this check and the connect.
 *
 * Private targets stay possible on purpose — a receiver on the same network
 * in development — through WEBHOOK_ALLOW_PRIVATE_TARGETS.
 */
final class OutboundTargetGuard
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /** @param (\Closure(string): list<string>)|null $resolver test seam: host → IP addresses */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveHost(...);
    }

    /**
     * @return array{host: string, port: int, ip: string} the address to pin
     * @throws BlockedTargetException
     */
    public function check(string $url, bool $allowPrivate): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BlockedTargetException('Webhook target must be an http(s) URL with a host.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $literal = trim($host, '[]');
        $ips = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : ($this->resolver)($host);
        if ($ips === []) {
            throw new BlockedTargetException(sprintf('Webhook target host "%s" does not resolve.', $host), retryable: true);
        }

        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (!self::isPublic($ip)) {
                    throw new BlockedTargetException(sprintf(
                        'Webhook target host "%s" resolves to non-public address %s; set WEBHOOK_ALLOW_PRIVATE_TARGETS=true to allow it.',
                        $host,
                        $ip,
                    ));
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        // Multicast is "global" to the filter but never a webhook receiver.
        $packed = inet_pton($ip);
        if ($packed === false
            || (strlen($packed) === 4 && (ord($packed[0]) & 0xF0) === 0xE0)
            || (strlen($packed) === 16 && ord($packed[0]) === 0xFF)
        ) {
            return false;
        }

        // An IPv4-compatible literal (::a.b.c.d) passes the filter when written
        // in hex (::7f00:1) although it embeds 127.0.0.1: judge the embedded
        // address instead. :: and ::1 never reach here — the filter rejects them.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 12)) {
            $embedded = inet_ntop(substr($packed, 12));

            return $embedded !== false && self::isPublic($embedded);
        }

        return true;
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
