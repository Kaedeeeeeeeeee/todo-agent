<?php

namespace App\Console\Commands;

use App\Models\Folder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PrepareLocalLearningAccount extends Command
{
    protected $signature = 'tasklist:prepare-local';

    protected $description = 'Create a local learning account and attach pre-authentication folders without resetting data';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is only available in the local environment.');

            return self::FAILURE;
        }

        $path = storage_path('app/private/local-learning-account.json');
        $email = 'learner@tasklist.test';
        $password = null;
        $count = DB::transaction(function () use ($email, $path, &$password): int {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $password = Str::random(24);
                $user = User::create(['name' => '学習者', 'email' => $email, 'password' => $password]);
                File::ensureDirectoryExists(dirname($path), 0700);
                File::put($path, json_encode(['login_url' => url('/login'), 'email' => $email, 'password' => $password], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                chmod($path, 0600);
            }

            return Folder::whereNull('user_id')->update(['user_id' => $user->id]);
        });
        $this->info("Local learning account ready. Attached {$count} existing folders.");
        $this->line('Credentials: '.$path);

        return self::SUCCESS;
    }
}
