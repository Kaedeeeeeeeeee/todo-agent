<?php

namespace App\Console\Commands;

use App\Services\GmailAutomation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasklist:process-mail')]
#[Description('Run due Gmail reads and persist completed task candidates without a browser')]
class ProcessGmailAutomation extends Command
{
    public function handle(GmailAutomation $automation): int
    {
        $automation->tick();

        return self::SUCCESS;
    }
}
