<?php

// Offline fixed-profile verifier. No application boot, DB, private key, Provider IPC or network.
require dirname(__DIR__, 3).'/vendor/autoload.php';
use App\Domains\CredentialProviderFixture\{FixtureAuthorizationCodec, FixtureAuthorizationVerifier, FixtureLeaseClock};

if ($argc!==3 || !is_file($argv[2]) || is_link($argv[2]) || filesize($argv[2])>FixtureAuthorizationCodec::MAX_BYTES) {
    fwrite(STDERR,"AUTHORIZATION_FIXTURE_INPUT_INVALID\n"); exit(2);
}
try {
    $raw=file_get_contents($argv[2]); $verifier=new FixtureAuthorizationVerifier(new FixtureLeaseClock(),$argv[1]);
    $r=$verifier->verifySignature($raw);
    echo json_encode(['signature_verified'=>$r['signature_verified'],'key_revoked'=>$r['key_revoked'],
        'snapshot_ref'=>$r['payload']['snapshot_ref'],'lease_expires_at'=>$r['payload']['lease_expires_at'],
        'window'=>'not_evaluated','live_core_eligibility'=>'not_evaluated','admission_granted'=>false],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) { fwrite(STDERR,"AUTHORIZATION_SIGNATURE_INVALID\n"); exit(1); }
