<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:backfill-company-memberships')]
#[Description('Backfill company_user memberships from users.company_id')]
class BackfillCompanyMemberships extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();

        $count = DB::table('users')
            ->whereNotNull('company_id')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('company_user')
                    ->whereColumn('company_user.user_id', 'users.id')
                    ->whereColumn('company_user.company_id', 'users.company_id');
            })
            ->count();

        if ($count === 0) {
            $this->info('No memberships to backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling {$count} memberships...");

        $inserted = DB::table('company_user')->insertUsing(
            ['company_id', 'user_id', 'status', 'created_at', 'updated_at'],
            DB::table('users')
                ->select([
                    'company_id',
                    'id as user_id',
                    DB::raw("'active' as status"),
                    DB::raw("'{$now}' as created_at"),
                    DB::raw("'{$now}' as updated_at"),
                ])
                ->whereNotNull('company_id')
                ->whereNull('deleted_at')
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->whereColumn('company_user.company_id', 'users.company_id');
                })
        );

        $this->info("Done. Inserted {$count} memberships.");

        return $inserted ? self::SUCCESS : self::FAILURE;
    }
}
