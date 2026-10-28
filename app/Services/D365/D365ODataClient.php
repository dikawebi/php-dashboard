<?php

namespace App\Services\D365;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Klien OData untuk Microsoft Dynamics 365 Finance & Operations.
 *
 * - Autentikasi: client credentials (Azure AD) dengan token yang di-cache.
 * - Endpoint data: {environmentUrl}/data/{EntityName}
 * - Paging: mengikuti @odata.nextLink dari server.
 */
class D365ODataClient
{
    private const TOKEN_CACHE_KEY = 'd365_access_token';

    public function __construct(private readonly array $config) {}

    /** Buat client dari config aplikasi (dashboard_settings -> config.d365Config). */
    public static function fromSettings(array $settings): self
    {
        return new self($settings['d365Config'] ?? []);
    }

    public function enabled(): bool
    {
        $required = ['environmentUrl', 'tenantId', 'clientId', 'clientSecret'];

        return ($this->config['enabled'] ?? false) && collect($required)->every(
            fn ($key) => filled($this->config[$key] ?? null)
        );
    }

    public function environmentUrl(): string
    {
        return rtrim((string) $this->config['environmentUrl'], '/');
    }

    /** Ambil access token dari Azure AD, dipakai ulang selama masih berlaku. */
    public function accessToken(): string
    {
        $ttl = (int) ($this->config['tokenCacheSeconds'] ?? 3000);
        $token = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $response = Http::asForm()->timeout(30)->post($this->authUrl(), [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $this->config['clientId'],
            'client_secret' => (string) $this->config['clientSecret'],
            'scope' => $this->environmentUrl().'/.default',
        ]);

        if ($response->failed()) {
            Log::error('D365 token request failed', ['status' => $response->status()]);
            $bodySnippet = Str::limit($response->body(), 300);

            throw new D365Exception('Autentikasi Azure AD gagal: HTTP '.$response->status().' — '.$bodySnippet);
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new D365Exception('Response Azure AD tidak berisi access_token.');
        }
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $ttl));

        return $token;
    }

    private function authUrl(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', (string) $this->config['tenantId']);
    }

    /**
     * Stream baris entity OData dengan paginasi $top + $skip per halaman.
     * Catatan: F&O tidak selalu menyediakan @odata.nextLink untuk kalau
     * kita memberi $top eksplisit — jadi halaman berikutnya dibangkitkan
     * manual dengan $skip, berhenti ketika jumlah baris < ukuran halaman.
     *
     * @param  array<int, string>  $select  kolom yang diambil (hemat bandwidth saat null)
     * @param  string|null  $filter  filter OData, mis. "SourceKey gt 123"
     * @param  string|null  $orderBy  mis. "SourceKey asc"
     * @param  int  $maxPages  batas aman jumlah halaman (0 = tanpa batas)
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamEntity(
        string $entityName,
        ?array $select = null,
        ?string $filter = null,
        ?string $orderBy = null,
        int $top = 2000,
        int $maxPages = 0,
    ): \Generator {
        $base = sprintf('%s/data/%s', $this->environmentUrl(), $entityName);
        $token = $this->accessToken();
        $offset = 0;
        $page = 0;

        while (true) {
            if ($maxPages > 0 && $page >= $maxPages) {
                return;
            }

            $query = ['$top' => (string) $top, '$skip' => (string) $offset];
            if ($select !== null && count($select) > 0) {
                $query['$select'] = implode(',', $select);
            }
            if ($filter !== null && $filter !== '') {
                $query['$filter'] = $filter;
            }
            if ($orderBy !== null && $orderBy !== '') {
                $query['$orderby'] = $orderBy;
            }

            $response = Http::acceptJson()->timeout(120)->withToken($token)
                ->get($base, $query);

            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                throw new D365Exception("Token ditolak oleh F&O untuk entity {$entityName} (HTTP 401). Cek role/konfigurasi App Registration.");
            }
            if ($response->failed()) {
                $message = $response->json('Message') ?? (string) Str::limit($response->body(), 300);
                throw new D365Exception("GET {$entityName} gagal: HTTP {$response->status()} — {$message}");
            }

            $rows = $response->json('value') ?? [];
            foreach ($rows as $row) {
                yield $row;
            }

            $page++;

            if (count($rows) < $top) {
                return;
            }
            $offset += $top;
        }
    }

    /** hitung jumlah record entity ($count=true) tanpa mengambil datanya. */
    public function countEntity(string $entityName): int
    {
        $response = Http::acceptJson()->timeout(120)->withToken($this->accessToken())
            ->get(sprintf('%s/data/%s?$count=true&$top=1', $this->environmentUrl(), $entityName));

        if ($response->failed()) {
            throw new D365Exception("COUNT {$entityName} gagal: HTTP ".$response->status());
        }

        return (int) ($response->json('@odata.count') ?? count($response->json('value') ?? []));
    }
}
