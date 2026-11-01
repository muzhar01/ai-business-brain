<?php

namespace App\Console\Commands;

use App\Security\Sealed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptExisting extends Command
{
    protected $signature = 'brain:encrypt-existing';

    protected $description = 'Seal legacy plaintext message content and lead notes (safe to re-run)';

    public function handle(): int
    {
        foreach (['brain_messages' => 'content', 'brain_leads' => 'notes'] as $table => $column) {
            $n = 0;
            DB::table($table)->whereNotNull($column)->where($column, '!=', '')->where($column, 'not like', Sealed::PREFIX.'%')
                ->orderBy('id')->chunkById(500, function ($rows) use ($table, $column, &$n) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([$column => Sealed::PREFIX.Crypt::encryptString($row->{$column})]);
                        $n++;
                    }
                });
            $this->info("{$table}.{$column}: sealed {$n} rows");
        }

        return self::SUCCESS;
    }
}
