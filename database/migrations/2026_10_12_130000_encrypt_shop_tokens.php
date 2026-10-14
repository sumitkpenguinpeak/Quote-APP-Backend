<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Shop::$casts now encrypts access_token / refresh_token. Encrypt the values that
 * are still stored in plain text (rows already encrypted are left untouched).
 * Columns are TEXT, so the longer ciphertext fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shops')->select('id', 'access_token', 'refresh_token')->orderBy('id')->each(function ($row) {
            $update = [];

            foreach (['access_token', 'refresh_token'] as $column) {
                $value = $row->{$column};

                if ($value === null || $value === '' || $this->isEncrypted($value)) {
                    continue;
                }

                // Same format as the `encrypted` cast (serialize = false).
                $update[$column] = Crypt::encryptString($value);
            }

            if ($update) {
                DB::table('shops')->where('id', $row->id)->update($update);
            }
        });
    }

    public function down(): void
    {
        // Intentionally not reversible: never write tokens back to plain text.
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
