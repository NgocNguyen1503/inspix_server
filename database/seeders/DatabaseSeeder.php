<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UnsplashTopicsSeeder::class,
            AppDemoSeeder::class,
        ]);

        $this->normalizeAvatarUrls();
    }

    private function normalizeAvatarUrls(): void
    {
        $avatarFiles = $this->getAvailableAvatarFiles();
        if (count($avatarFiles) === 0) {
            return;
        }

        $users = DB::table('users')
            ->select('uuid')
            ->orderBy('created_at')
            ->get();

        foreach ($users as $user) {
            $index = abs((int) crc32((string) $user->uuid)) % count($avatarFiles);

            DB::table('users')
                ->where('uuid', $user->uuid)
                ->update([
                    'avatar_url' => '/uploads/avatars/' . $avatarFiles[$index],
                ]);
        }
    }

    /**
     * @return list<string>
     */
    private function getAvailableAvatarFiles(): array
    {
        $avatarDirectory = public_path('uploads/avatars');
        if (!File::exists($avatarDirectory)) {
            return [];
        }

        return collect(File::files($avatarDirectory))
            ->map(fn($file) => $file->getFilename())
            ->sort()
            ->values()
            ->all();
    }
}
