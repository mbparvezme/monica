<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Console\Command;

class GenerateTestToken extends Command
{
    // Purpose of this file is to get the Sanctum token and
    // Vault IDs with name for the import request testing.
    protected $signature = 'auth:token {email? : User email (defaults to first user)}';

    protected $description = 'Generate a Sanctum token and show vault IDs for import testing';

    public function handle(): void
    {
        $user = $this->argument('email')
            ? User::where('email', $this->argument('email'))->firstOrFail()
            : User::first();

        if (! $user) {
            $this->error('No users found. Register an account first.');
            return;
        }

        $token = $user->createToken('import-test', ['read', 'write'])->plainTextToken;

        $this->info("User: {$user->email}");
        $this->newLine();
        $this->info("Token: {$token}");
        $this->newLine();

        $vaults = Vault::where('account_id', $user->account_id)->get(['id', 'name']);
        $this->info('Vaults:');
        foreach ($vaults as $vault) {
            $this->line("  {$vault->id}  {$vault->name}");
        }
    }
}
