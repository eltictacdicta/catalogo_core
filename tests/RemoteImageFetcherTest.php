<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\OpcionalImagenService;
use FSFramework\Plugins\catalogo_core\Services\RemoteImageFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteImageFetcher::class)]
#[CoversClass(OpcionalImagenService::class)]
final class RemoteImageFetcherTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'http' => ['http://example.com/a.jpg'],
            'ftp' => ['ftp://example.com/a.jpg'],
            'file' => ['file:///etc/passwd'],
            'credenciales' => ['https://user:pass@example.com/a.jpg'],
            'puerto' => ['https://example.com:8443/a.jpg'],
            'vacio' => [''],
            'sin host' => ['https:///a.jpg'],
            'salto de linea' => ["https://example.com/a\n.jpg"],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function testNormalizeUrlRejects(string $url): void
    {
        $this->assertNull(RemoteImageFetcher::normalizeUrl($url, $error));
        $this->assertNotEmpty($error);
    }

    public function testNormalizeUrlEncodesNonAsciiPathLikeTheReferenceFile(): void
    {
        $this->assertSame(
            'https://infrico.com/im%C3%A1genes/kitcajones.jpg',
            RemoteImageFetcher::normalizeUrl('https://infrico.com/imágenes/kitcajones.jpg')
        );
        // Idempotente: un URL ya codificado no se codifica dos veces.
        $this->assertSame(
            'https://infrico.com/im%C3%A1genes/kit%20cajones.jpg',
            RemoteImageFetcher::normalizeUrl('https://infrico.com/im%C3%A1genes/kit%20cajones.jpg')
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function ips(): array
    {
        return [
            'publica' => ['93.184.216.34', true],
            'loopback' => ['127.0.0.1', false],
            'privada 10' => ['10.0.0.5', false],
            'privada 192' => ['192.168.1.1', false],
            'privada 172' => ['172.16.0.1', false],
            'link-local / metadata' => ['169.254.169.254', false],
            'reservada' => ['0.0.0.0', false],
            'ipv6 loopback' => ['::1', false],
            'ipv6 unique local' => ['fd00::1', false],
        ];
    }

    #[DataProvider('ips')]
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        $this->assertSame($expected, RemoteImageFetcher::isPublicIp($ip));
    }

    public function testFetchRefusesHostsResolvingToPrivateAddresses(): void
    {
        $fetcher = new class extends RemoteImageFetcher {
            public int $requests = 0;

            protected function resolveHost(string $host): array
            {
                return ['93.184.216.34', '10.0.0.7']; // una sola privada basta
            }

            protected function request(string $url, string $host, string $ip, ?string &$error): ?array
            {
                $this->requests++;

                return ['body' => 'x', 'location' => null];
            }
        };

        $this->assertNull($fetcher->fetch('https://evil.example/a.jpg', $error));
        $this->assertStringContainsString('pública', (string) $error);
        $this->assertSame(0, $fetcher->requests, 'no se llega a conectar');
    }

    public function testFetchRevalidatesEveryRedirect(): void
    {
        $fetcher = new class extends RemoteImageFetcher {
            /** @var list<string> */
            public array $seen = [];

            protected function resolveHost(string $host): array
            {
                return $host === 'internal.example' ? ['127.0.0.1'] : ['93.184.216.34'];
            }

            protected function request(string $url, string $host, string $ip, ?string &$error): ?array
            {
                $this->seen[] = $url;

                return ['body' => null, 'location' => 'https://internal.example/secret'];
            }
        };

        $this->assertNull($fetcher->fetch('https://ok.example/a.jpg', $error));
        $this->assertSame(['https://ok.example/a.jpg'], $fetcher->seen);
        $this->assertStringContainsString('pública', (string) $error);
    }

    public function testFetchGivesUpAfterTooManyRedirects(): void
    {
        $fetcher = new class extends RemoteImageFetcher {
            protected function resolveHost(string $host): array
            {
                return ['93.184.216.34'];
            }

            protected function request(string $url, string $host, string $ip, ?string &$error): ?array
            {
                return ['body' => null, 'location' => '/otra'];
            }
        };

        $this->assertNull($fetcher->fetch('https://ok.example/a.jpg', $error));
        $this->assertStringContainsString('redirecciones', (string) $error);
    }

    public function testFetchResolvesRelativeRedirect(): void
    {
        $fetcher = new class extends RemoteImageFetcher {
            public int $n = 0;
            /** @var list<string> */
            public array $seen = [];

            protected function resolveHost(string $host): array
            {
                return ['93.184.216.34'];
            }

            protected function request(string $url, string $host, string $ip, ?string &$error): ?array
            {
                $this->seen[] = $url;

                return $this->n++ === 0
                    ? ['body' => null, 'location' => '/final.jpg']
                    : ['body' => 'DATA', 'location' => null];
            }
        };

        $this->assertSame('DATA', $fetcher->fetch('https://ok.example/a/b.jpg'));
        $this->assertSame(['https://ok.example/a/b.jpg', 'https://ok.example/final.jpg'], $fetcher->seen);
    }

    // ---- OpcionalImagenService::upload_from_url ----------------------------------

    private function stubFetcher(?string $body, ?string $error = null): RemoteImageFetcher
    {
        return new class($body, $error) extends RemoteImageFetcher {
            public function __construct(private ?string $body, private ?string $err)
            {
            }

            public function fetch(string $url, ?string &$error = null): ?string
            {
                $error = $this->err;

                return $this->body;
            }
        };
    }

    public function testUploadFromUrlStoresARealImage(): void
    {
        $dir = sys_get_temp_dir() . '/opc_url_' . bin2hex(random_bytes(5)) . '/';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $service = new OpcionalImagenService($dir, $this->stubFetcher($png));
        $name = $service->upload_from_url('X1', 'https://x.test/a.php', $error);

        $this->assertNotNull($name, (string) $error);
        $this->assertStringEndsWith('.png', $name, 'la extensión sale del MIME real, no del URL');
        $this->assertFileExists($dir . $name);

        $service->delete_file($name);
        @rmdir($dir);
    }

    public function testUploadFromUrlRejectsNonImagesAndDownloadErrors(): void
    {
        $dir = sys_get_temp_dir() . '/opc_url_' . bin2hex(random_bytes(5)) . '/';

        $service = new OpcionalImagenService($dir, $this->stubFetcher('<?php echo 1;'));
        $this->assertNull($service->upload_from_url('X1', 'https://x.test/a.jpg', $error));
        $this->assertStringContainsString('imagen', (string) $error);
        $this->assertDirectoryDoesNotExist($dir);

        $service = new OpcionalImagenService($dir, $this->stubFetcher(null, 'HTTP 404'));
        $this->assertNull($service->upload_from_url('X1', 'https://x.test/a.jpg', $error));
        $this->assertSame('HTTP 404', $error);
    }
}
