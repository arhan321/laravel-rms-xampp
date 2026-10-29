<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RmsCollectorTokenSeeder extends Seeder
{
    private const TOKEN_ID = 1;

    private const COLLECTOR_EMAIL = 'collector@rms.local';

    private const TOKEN_NAME = 'rms-cpp-collector';

    public function run(): void
    {
        // Token disimpan di file lokal, bukan source code GitHub.
        $tokenPath = storage_path(
            'app/private/rms_collector.token'
        );

        if (!is_file($tokenPath) || !is_readable($tokenPath)) {
            throw new RuntimeException(
                'File token tidak ditemukan: ' . $tokenPath
            );
        }

        $fullToken = trim(
            (string) file_get_contents($tokenPath)
        );

        // Format wajib: 1|RAHASIA_TOKEN
        if (!preg_match('/^1\|([A-Za-z0-9]+)$/D', $fullToken, $matches)) {
            throw new RuntimeException(
                'Format token harus 1|RAHASIA_TOKEN'
            );
        }

        $plainSecret = $matches[1];

        if (strlen($plainSecret) < 32) {
            throw new RuntimeException(
                'Token terlalu pendek.'
            );
        }

        // Laravel Sanctum menyimpan SHA256 bagian setelah "|".
        $tokenHash = hash('sha256', $plainSecret);

        DB::transaction(function () use ($tokenHash): void {

            // 1. Buat akun khusus C++ Collector.
            $user = User::query()->firstOrCreate(
                [
                    'email' => self::COLLECTOR_EMAIL,
                ],
                [
                    'name' => 'RMS Yaskawa C++ Collector',
                    'password' => Str::random(64),
                ]
            );

            // 2. Berikan role collector.
            DB::table('rms_access_grants')->updateOrInsert(
                [
                    'user_id' => $user->id,
                ],
                [
                    'role' => 'collector',
                    'enabled' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // 3. Pastikan token ID 1 tidak dimiliki akun lain.
            $existing = DB::table('personal_access_tokens')
                ->where('id', self::TOKEN_ID)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $isOurToken =
                    $existing->tokenable_type === $user->getMorphClass()
                    && (int) $existing->tokenable_id === (int) $user->id
                    && $existing->name === self::TOKEN_NAME;

                if (!$isOurToken) {
                    throw new RuntimeException(
                        'Token ID 1 sudah dimiliki akun/token lain. '
                        . 'Seeder tidak akan menimpanya.'
                    );
                }
            }

            // Cegah hash yang sama digunakan token ID lain.
            $duplicate = DB::table('personal_access_tokens')
                ->where('token', $tokenHash)
                ->where('id', '!=', self::TOKEN_ID)
                ->exists();

            if ($duplicate) {
                throw new RuntimeException(
                    'Token sudah digunakan pada ID lain.'
                );
            }

            // 4. Data Sanctum.
            $tokenData = [
                'tokenable_type' => $user->getMorphClass(),
                'tokenable_id' => $user->id,
                'name' => self::TOKEN_NAME,
                'token' => $tokenHash,
                'abilities' => json_encode(
                    ['rms:ingest'],
                    JSON_THROW_ON_ERROR
                ),
                'expires_at' => null,
                'updated_at' => now(),
            ];

            // 5. Insert atau update token yang sama.
            if ($existing !== null) {
                DB::table('personal_access_tokens')
                    ->where('id', self::TOKEN_ID)
                    ->update($tokenData);
            } else {
                // Biarkan database menghasilkan ID identity/sequence.
                // Database lab baru diharapkan memberikan ID 1 untuk token pertama.
                $insertedId = DB::table('personal_access_tokens')->insertGetId(
                    array_merge(
                        $tokenData,
                        [
                            'last_used_at' => null,
                            'created_at' => now(),
                        ]
                    )
                );

                if ((int) $insertedId !== self::TOKEN_ID) {
                    throw new RuntimeException(
                        'Token collector seharusnya mendapat ID '
                        . self::TOKEN_ID
                        . ', tetapi database memberikan ID '
                        . $insertedId
                        . '.'
                    );
                }
            }

            if (DB::connection()->getDriverName() === 'pgsql') {
                // 6. Sinkronisasi sequence ID PostgreSQL.
                DB::selectOne("
                    SELECT setval(
                        pg_get_serial_sequence(
                            'personal_access_tokens', 'id'
                        ),
                        GREATEST(
                            (SELECT COALESCE(MAX(id), 0)
                             FROM personal_access_tokens),
                            1
                        ),
                        true
                    )
                ");
            }
        });

        $this->command?->info(
            'RMS Collector Token berhasil disiapkan. ID = 1'
        );

        $this->command?->info(
            'Abilities: rms:ingest'
        );
    }
}

