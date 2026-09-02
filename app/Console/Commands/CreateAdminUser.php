<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:create-admin {email : Administrator email} {--name=Administrator : Administrator name}')]
#[Description('Create or promote a settings administrator')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $password = $this->secret('Administrator password');

        if (! is_string($password) || mb_strlen($password) < 12) {
            $this->error('Password must contain at least 12 characters.');

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => strtolower((string) $this->argument('email'))],
            ['name' => (string) $this->option('name'), 'password' => $password, 'is_admin' => true],
        );
        $this->info('Administrator account is ready.');

        return self::SUCCESS;
    }
}
