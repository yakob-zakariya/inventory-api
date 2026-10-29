<?php

namespace App\Console\Commands;

use App\Services\LedgerVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:verify')]
#[Description('Recalculate stock, payments and balances from the ledgers and report any inconsistency')]
class VerifyInventory extends Command
{
    /**
     * Execute the console command. Exits with 1 when any check finds a problem.
     */
    public function handle(LedgerVerifier $verifier): int
    {
        $failed = false;

        foreach ($verifier->run() as $check => $problems) {
            if ($problems === []) {
                $this->components->twoColumnDetail($check, '<fg=green>OK</>');

                continue;
            }

            $failed = true;
            $this->components->twoColumnDetail($check, '<fg=red>'.count($problems).' problem(s)</>');
            $this->components->bulletList($problems);
        }

        if ($failed) {
            $this->components->error('The data is inconsistent. Fix the cause before relying on the numbers.');

            return self::FAILURE;
        }

        $this->components->info('Everything matches the ledgers.');

        return self::SUCCESS;
    }
}
