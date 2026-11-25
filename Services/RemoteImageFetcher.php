<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Descarga segura de un fichero remoto (imágenes de un CSV importado).
 *
 * Defensas (el URL lo escribe quien sube el CSV, no es de confianza):
 *  - solo https y puerto 443, sin credenciales en el URL;
 *  - el host debe resolver SOLO a IP públicas (anti-SSRF: sin loopback, redes
 *    privadas ni rangos reservados) y la conexión se ancla a esa IP
 *    (CURLOPT_RESOLVE) para evitar el rebinding de DNS entre comprobar y usar;
 *  - redirecciones limitadas y revalidadas una a una;
 *  - tope de tamaño aplicado mientras se descarga, y tiempos máximos.
 *
 * El contenido NO se valida aquí (lo hace el almacén por MIME real).
 */
class RemoteImageFetcher
{
    public const MAX_BYTES = 5242880; // 5 MB
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT_SECONDS = 15;
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Normaliza y valida sintácticamente el URL. Devuelve el URL listo para
     * pedir (ruta y query con percent-encoding) o null con `$error`.
     */
    public static function normalizeUrl(string $url, ?string &$error = null): ?string
    {
        $error = null;
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            $error = 'URL vacío o con caracteres no válidos';

            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            $error = 'URL no válido';

            return null;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            $error = 'Solo se admiten URL https';

            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $error = 'El URL no puede incluir credenciales';

            return null;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $error = 'Solo se admite el puerto 443';

            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        $path = (string) ($parts['path'] ?? '/');
        $segments = array_map(
            static fn (string $s): string => rawurlencode(rawurldecode($s)),
            explode('/', $path)
        );
        $path = implode('/', $segments);
        if ($path === '') {
            $path = '/';
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $query = '?' . preg_replace_callback(
                '/[^\x21-\x7E]|[\x22<>\\\\^`{|}]/',
                static fn (array $m): string => rawurlencode($m[0]),
                $parts['query']
            );
        }

        return 'https://' . $host . $path . $query;
    }

    /**
     * Una IP es pública si no es privada, loopback, link-local ni reservada.
     */
    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Resuelve el host a sus IP; seam sobreescribible en tests.
     *
     * @return array<int, string>
     */
    protected function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Descarga el URL. Devuelve el cuerpo o null con `$error`.
     */
    public function fetch(string $url, ?string &$error = null): ?string
    {
        $error = null;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $normalizado = self::normalizeUrl($url, $error);
            if ($normalizado === null) {
                return null;
            }

            $host = (string) parse_url($normalizado, PHP_URL_HOST);
            $ips = $this->resolveHost($host);
            if ($ips === []) {
                $error = 'No se pudo resolver el host ' . $host;

                return null;
            }

            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    $error = 'El host ' . $host . ' no resuelve a una dirección pública';

                    return null;
                }
            }

            $result = $this->request($normalizado, $host, $ips[0], $error);
            if ($result === null) {
                return null;
            }

            if ($result['location'] === null) {
                return $result['body'];
            }

            $url = $this->absoluteLocation($normalizado, $result['location']);
        }

        $error = 'Demasiadas redirecciones';

        return null;
    }

    private function absoluteLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $host = (string) parse_url($base, PHP_URL_HOST);
        if (strpos($location, '/') === 0) {
            return 'https://' . $host . $location;
        }

        $path = (string) parse_url($base, PHP_URL_PATH);
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return 'https://' . $host . $dir . $location;
    }

    /**
     * Una petición HTTP anclada a `$ip`.
     *
     * Seam sobreescribible en tests (la red no se toca en la suite).
     *
     * @return array{body: ?string, location: ?string}|null
     */
    protected function request(string $url, string $host, string $ip, ?string &$error): ?array
    {
        if (!function_exists('curl_init')) {
            $error = 'La extensión cURL no está disponible';

            return null;
        }

        $body = '';
        $tooBig = false;
        $location = null;
        $ipLiteral = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':443:' . $ipLiteral],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'FSFramework-OpcionalesImport/1.0',
            CURLOPT_HTTPHEADER => ['Accept: image/*'],
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, &$tooBig): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooBig = true;

                    return 0; // aborta la transferencia
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($tooBig) {
            $error = 'La imagen supera el tamaño máximo (' . (int) (self::MAX_BYTES / 1048576) . ' MB)';

            return null;
        }

        if ($status >= 300 && $status < 400 && $location !== null && $location !== '') {
            return ['body' => null, 'location' => $location];
        }

        if ($ok === false || $status !== 200) {
            $error = $curlError !== '' ? $curlError : 'HTTP ' . $status;

            return null;
        }

        return ['body' => $body, 'location' => null];
    }
}
