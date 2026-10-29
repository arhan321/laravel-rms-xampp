<?php

declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Deliberately CLI-only; prevent operators self-promoting via public API. */
final class GrantRmsRole extends Command
{
    protected $signature='rms:grant-role {email} {role : collector|technician|admin}';
    protected $description='Assign RMS privilege to an existing user (trusted server shell only)';
    public function handle():int
    {
        $role=(string)$this->argument('role');
        if(!in_array($role,['collector','technician','admin'],true)){
            $this->error('Use collector, technician, or admin.');return self::FAILURE;
        }
        $u=User::query()->where('email',$this->argument('email'))->first();
        if(!$u){$this->error('User not found; create staff account through trusted admin process.');return self::FAILURE;}
        DB::table('rms_access_grants')->updateOrInsert(['user_id'=>$u->id],
            ['role'=>$role,'enabled'=>true,'updated_at'=>now(),'created_at'=>now()]);
        $this->info('Granted '.$role.' to existing user '.$u->email);
        return self::SUCCESS;
    }
}
