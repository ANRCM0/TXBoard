<?php
namespace App\Http\Middleware;
use App\Domains\LegacyLinks\LinkAssociation;
use App\Domains\LegacyLinks\LinkFailure;
use App\Domains\LegacyLinks\LinkService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
/** Optional non-authorizing observation after native Client authentication. */
final class LegacySubscriptionLinkShadow
{
    public function handle(Request $request,Closure $next)
    {
        if(config('legacy_subscription_links.enabled')!==false){
            try{
                $user=$request->user();
                if(!$user instanceof User)throw new LinkFailure('LINK_AUTHENTICATED_USER_MISSING');
                $association=app(LinkService::class)->observe($user);
                $request->attributes->set(LinkService::ATTRIBUTE,$association);
            }catch(\Throwable$error){
                $request->attributes->set(LinkService::ATTRIBUTE,['version'=>'legacy-subscription-link/v1','authority'=>'legacy','authorizes'=>false,
                    'status'=>'blocked','error_code'=>$error instanceof LinkFailure?$error->getMessage():'LINK_STORAGE_OR_EVIDENCE_FAILED']);
            }
        }
        return $next($request);
    }
}
