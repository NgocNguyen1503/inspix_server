<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UnsplashTopicsSeeder extends Seeder
{
    /**
     * Seed topics from Unsplash API.
     */
    public function run(): void
    {
        $accessKey = config('services.unsplash.access_key');
        $apiUrl = rtrim((string) config('services.unsplash.api_url', 'https://api.unsplash.com'), '/');

        if (empty($accessKey)) {
            throw new RuntimeException('UNSPLASH_ACCESS_KEY is missing. Set it in .env before running db:seed.');
        }

        $page = 1;
        $perPage = 30;
        $maxPages = 100;
        $topicNames = [];

        while ($page <= $maxPages) {
            $response = Http::retry(3, 500)
                ->timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => 'Client-ID '.$accessKey,
                ])
                ->get($apiUrl.'/topics', [
                    'page' => $page,
                    'per_page' => $perPage,
                    'order_by' => 'featured',
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Failed to fetch Unsplash topics at page '.$page.'. HTTP '.$response->status());
            }

            $rows = $response->json();
            if (!is_array($rows) || count($rows) === 0) {
                break;
            }

            foreach ($rows as $row) {
                $name = trim((string) ($row['title'] ?? $row['slug'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $topicNames[strtolower($name)] = $name;
            }

            if (count($rows) < $perPage) {
                break;
            }

            $page++;
        }

        if (count($topicNames) === 0) {
            throw new RuntimeException('Unsplash topics result is empty. Check API key and rate limits.');
        }

        DB::table('topics')->delete();

        $payload = [];
        foreach (array_values($topicNames) as $name) {
            $payload[] = ['name' => $name];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('topics')->insert($chunk);
        }

        $this->command?->info('Seeded '.count($payload).' topics from Unsplash API.');
    }
}
