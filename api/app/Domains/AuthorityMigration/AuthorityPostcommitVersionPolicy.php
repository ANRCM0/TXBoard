<?php

namespace App\Domains\AuthorityMigration;

final class AuthorityPostcommitVersionPolicy
{
    public static function declared():bool{return AuthorityNativeInventory::declared()||AuthorityIdentityInventory::declared()||AuthorityTicketInventory::declared();}
    public static function valid(string $scope,string $version,string $epoch,string $writer):bool
    {
        return ($scope===AuthorityNativeInventory::SCOPE&&$version===AuthorityNativeInventory::VERSION&&$epoch==='4'&&in_array($writer,AuthorityNativeInventory::WRITERS,true))
            ||($scope===AuthorityIdentityInventory::SCOPE&&$version===AuthorityIdentityInventory::VERSION&&$epoch==='5'&&in_array($writer,AuthorityIdentityInventory::WRITERS,true))
            ||($scope===AuthorityTicketInventory::SCOPE&&$version===AuthorityTicketInventory::VERSION&&$epoch==='6'&&in_array($writer,AuthorityTicketInventory::WRITERS,true));
    }
}
