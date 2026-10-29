<?php

declare(strict_types=1);
namespace App\Providers;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/** Grant no privileges by default. Operator roles are server-side ACL, not request fields. */
final class RmsAuthorizationServiceProvider extends ServiceProvider
{
    public function boot():void
    {
        $role=static function(User $user):?string{
            return DB::table('rms_access_grants')->where('user_id',$user->id)
                ->where('enabled',true)->value('role');
        };
        $tokenAllows=static function(User $user,string $ability):bool{
            $token=$user->currentAccessToken();
            // SPA authenticated session (TransientToken) may use Gate; never allow it for ingestion.
            return !($token instanceof PersonalAccessToken)||$token->can($ability);
        };
        Gate::define('rms.manage',static fn(User $u)=>$role($u)==='admin' && $tokenAllows($u,'rms:manage'));
        Gate::define('rms.maintain',static fn(User $u)=>in_array($role($u),['technician','admin'],true)
            && $tokenAllows($u,'rms:maintain'));
        Gate::define('rms.ingest',static fn(User $u)=>in_array($role($u),['collector','admin'],true)
            && $u->currentAccessToken() instanceof PersonalAccessToken
            && $u->currentAccessToken()->can('rms:ingest'));
    }
}
