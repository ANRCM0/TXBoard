<?php
namespace App\Domains\LegacyLinks;
use App\Models\User;
/** Request-local nonsecret identity observation, never a credential or grant. */
final class LinkAssociation implements \JsonSerializable
{
    public function __construct(public readonly string $linkId, public readonly string $userId,
        public readonly string $subscriptionId, public readonly string $mapId,
        public readonly string $keyId, public readonly string $generation)
    {
        foreach ([$linkId,$userId,$subscriptionId,$mapId] as $id) Canonical::id($id);
    }
    public function forNativeUser(User $user): ?self
    { return (string) $user->id === $this->userId ? $this : null; }
    public function jsonSerialize(): array
    {
        return ['version'=>'legacy-subscription-link/v1','authority'=>'legacy','authorizes'=>false,'status'=>'verified',
            'link_id'=>$this->linkId,'legacy_user_id'=>$this->userId,'subscription_id'=>$this->subscriptionId,
            'map_id'=>$this->mapId,'key_id'=>$this->keyId,'generation'=>$this->generation];
    }
}
