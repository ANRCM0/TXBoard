<?php

namespace App\Domains\CredentialProviderFixture;

use App\Domains\UsageV2\{UsageError, UsagePolicy};
use DomainException;
use Illuminate\Support\Facades\{DB, Schema};

/** Isolated initial Core binding plus offline statement. Never a Node grant or secret consumer. */
final class FixtureAuthorizationService
{
    public const TABLE = 'tx_credential_provider_shadow_authorization';
    private const IMMUTABLE = ['binding_id','registration_id','provider_instance_epoch','issue_operation_id','issue_input_hash',
        'issue_result_hash','source_id','node_id','source_epoch','core_instance_id','receipt_generation','user_id',
        'subscription_id','terms_id','usage_period_id','tariff_revision_id','revocation_version','terms_hash','period_quota_bytes','tariff_hash'];
    public function __construct(private UsagePolicy $policy, private FixtureLeaseCurrentBoundary $boundary,
        private FixtureAuthorizationSigner $signer, private FixtureAuthorizationVerifier $verifier, private FixtureLeaseClock $clock) {}

    public function commit(FixtureScope $scope, string $candidateRef, string $key): array
    {
        $this->guard(); $request=FixtureAuthorizationCodec::request($scope,$candidateRef,$key);
        if ($existing=$this->existing($scope,$key)) { return $this->replay($existing,$scope,$request); }
        $hint=$this->candidate($scope,$candidateRef); FixtureLeaseCandidateCodec::retained($hint,$scope);
        $original=FixtureLeaseCandidateCodec::decode($hint->request_snapshot);
        return DB::transaction(function () use ($scope,$candidateRef,$key,$request,$original) {
            try { [$current,$mono]=$this->boundary->forCandidate($scope,$original['issue_operation_ref'],$original['input']); }
            catch (DomainException|UsageError $error) {
                if ($existing=$this->existing($scope,$key,true)) { return $this->replay($existing,$scope,$request); }
                // A successful competing bind makes the original candidate version stale.
                if ($this->consumed($scope)) { throw new DomainException('AUTHORIZATION_ALREADY_COMMITTED'); }
                throw $error;
            }
            $candidate=$this->candidate($scope,$candidateRef,true); $old=FixtureLeaseCandidateCodec::retained($candidate,$scope);
            if ($existing=$this->existing($scope,$key,true)) { return $this->replay($existing,$scope,$request); }
            if ($this->consumed($scope)) { throw new DomainException('AUTHORIZATION_ALREADY_COMMITTED'); }
            if ($candidate->state!=='prepared') { throw new DomainException('AUTHORIZATION_CANDIDATE_CLOSED'); }
            $this->sameCurrent($old,$current);
            // Registration/core rows, candidate and competing authorization checks are now locked.
            // Preserve elapsed/regression checks across those waits, then sample the binding time here.
            $now=$this->finalTime($current['prepared_at'],max((int)$candidate->last_observed_at,$old['prepared_at']),$old['candidate_expires_at'],$mono);
            $mono=$this->clock->monotonic();
            // These rows are already locked by the common boundary; no additional reverse-order lock.
            $subject=DB::table('tx_access_subject')->where('id',$scope->subjectId)->lockForUpdate()->first();
            if ($subject->status!=='pending' || $subject->provider_subject_ref!=='core_pending_fixture'
                || (string)$subject->auth_version!==$old['auth_version'] || (string)$subject->revocation_fact_version!=='0'
                || $subject->revoked_at!==null || $old['subject_core_ref']!=='core_pending_fixture') {
                throw new DomainException('AUTHORIZATION_INITIAL_BINDING_REQUIRED');
            }
            $source=DB::table('tx_node_usage_source')->where('id',$old['source_id'])->lockForUpdate()->first();
            $period=DB::table('tx_usage_period')->where('id',$old['usage_period_id'])->lockForUpdate()->first();
            $context=$this->signer->prepare($now); $keyData=$context['key'];
            $expiry=min($old['candidate_expires_at'],$current['candidate_expires_at'],$keyData['not_after']);
            if ($expiry<=$now) { throw new DomainException('AUTHORIZATION_EXPIRED'); }
            $transition=['original_subject_ref'=>$subject->provider_subject_ref,'original_auth_version'=>(string)$subject->auth_version,
                'original_row_version'=>(string)$subject->row_version,'subject_ref'=>$old['issue_result']['provider_subject_ref'],
                'auth_version'=>FixtureAuthorizationCodec::increment((string)$subject->auth_version),
                'row_version'=>FixtureAuthorizationCodec::increment((string)$subject->row_version),
                'credential_ref'=>$old['issue_result']['credential_ref'],'credential_generation'=>'1','committed_at'=>$now];
            $payload=['v'=>FixtureAuthorizationCodec::VERSION,'mode'=>'local_synthetic_authorization_snapshot',
                'iss'=>FixtureAuthorizationCodec::ISSUER,'aud'=>FixtureAuthorizationCodec::AUDIENCE,'snapshot_ref'=>'asf_'.bin2hex(random_bytes(16)),
                'provider_id'=>$scope->providerId,'access_subject_id'=>$scope->subjectId,'actor_ref'=>$scope->actorRef,'authorization_ref'=>$scope->authorizationRef,
                'candidate_id'=>(string)$candidate->id,'candidate_ref'=>$candidateRef,'candidate_request'=>$original,
                'candidate_evidence'=>$old,'candidate_evidence_hash'=>$candidate->evidence_hash,'binding_commit'=>$transition,
                'source'=>['source_id'=>$old['source_id'],'node_id'=>$old['node_id'],'source_epoch'=>$old['source_epoch'],
                    'installation_ref'=>$source->installation_ref,'core_instance_id'=>$old['core_instance_id'],'receipt_generation'=>$old['receipt_generation']],
                'policy'=>['revision'=>FixtureAuthorizationCodec::POLICY,'digest'=>FixtureAuthorizationCodec::policyDigest(),
                    'quota_bytes'=>$old['period_quota_bytes'],'settled_billable_bytes'=>FixtureLeaseCandidateCodec::number((string)$period->billable_bytes),
                    'subject_revocation_fact_version'=>'0','provider_fact_domain'=>'provider-subject/v1','provider_subject_revoked'=>false,'node_revocation_version'=>null],
                'issued_at'=>$now,'lease_expires_at'=>$expiry,'key_epoch'=>$keyData['epoch'],'key_spki_sha256'=>$keyData['spki_sha256'],
                'key_not_before'=>$keyData['not_before'],'key_not_after'=>$keyData['not_after'],
                'admission_granted'=>false,'delivery_status'=>'not_implemented','node_convergence'=>'not_implemented'];
            FixtureAuthorizationCodec::payload($payload);
            $changed=DB::table('tx_access_subject')->where('id',$scope->subjectId)->where('status','pending')
                ->where('provider_subject_ref','core_pending_fixture')->where('auth_version',$transition['original_auth_version'])
                ->where('row_version',$transition['original_row_version'])->where('revocation_fact_version',0)->whereNull('revoked_at')
                ->update(['provider_subject_ref'=>$transition['subject_ref'],'status'=>'active','auth_version'=>$transition['auth_version'],
                    'row_version'=>$transition['row_version'],'updated_at'=>$now]);
            if ($changed!==1) { throw new DomainException('AUTHORIZATION_BINDING_CHANGED'); }
            $token=$this->signer->sign($payload,$context);
            $id=DB::table(self::TABLE)->insertGetId(['snapshot_ref'=>$payload['snapshot_ref'],'provider_id'=>$scope->providerId,
                'access_subject_id'=>$scope->subjectId,'candidate_id'=>$candidate->id,'business_key'=>$key,
                'request_hash'=>FixtureCodec::hash($request),'request_snapshot'=>FixtureCodec::json($request),
                'payload_snapshot'=>FixtureCodec::json($payload),'payload_hash'=>FixtureCodec::hash($payload),
                'signed_snapshot'=>$token,'signature_hash'=>hash('sha256',$token),'key_id'=>$keyData['kid'],'key_epoch'=>$keyData['epoch'],
                'key_spki_sha256'=>$keyData['spki_sha256'],'issued_at'=>$now,'lease_expires_at'=>$expiry,
                'state'=>'valid','reason_code'=>null,'last_observed_at'=>$now,'created_at'=>$now]
                +array_intersect_key($old,array_flip(['binding_id','registration_id','issue_operation_id','source_id','subscription_id','terms_id','usage_period_id','tariff_revision_id'])));
            $row=DB::table(self::TABLE)->where('id',$id)->lockForUpdate()->first(); $receipt=$this->receipt($row,$scope,false);
            // Do not return a half-valid binding: every initial late failure throws and rolls back BOTH rows.
            $wall=FixtureCodec::time($this->clock->wall()); $this->signer->assertUnchanged($context,$wall);
            $this->finalTime($now,$now,$expiry,$mono);
            return $receipt;
        },3);
    }

    public function inspect(FixtureScope $scope, string $ref): array
    {
        $this->guard(); return $this->receipt($this->find($scope,$ref),$scope,true);
    }

    public function checkCurrent(FixtureScope $scope, string $ref): array
    {
        $this->guard(); $hint=$this->find($scope,$ref); $p=FixtureAuthorizationCodec::retained($hint,$scope);
        return DB::transaction(function () use ($scope,$ref,$hint,$p) {
            $failure=null; $current=null; $mono=$this->clock->monotonic();
            if ($hint->state==='valid') {
                try {
                    [$current,$mono]=$this->boundary->forCommitted($scope,$p['candidate_request']['issue_operation_ref'],
                        $p['candidate_request']['input'],$p['binding_commit']);
                    $this->sameCurrent($p['candidate_evidence'],$current,$p['binding_commit']);
                    if ($p['lease_expires_at']>$current['candidate_expires_at']) { throw new DomainException('AUTHORIZATION_INTERVAL_CHANGED'); }
                    // Re-lock the SAME source already held by the boundary, for a current read under MariaDB MVCC.
                    $source=DB::table('tx_node_usage_source')->where('id',$p['source']['source_id'])->lockForUpdate()->first();
                    if (!$source || $source->installation_ref!==$p['source']['installation_ref']) { throw new DomainException('AUTHORIZATION_BINDING_CHANGED'); }
                    // Candidate immutable bytes remain authoritative; its post-bind mutable status does not grant eligibility.
                    $candidate=$this->candidate($scope,$p['candidate_ref'],true); $e=FixtureLeaseCandidateCodec::retained($candidate,$scope);
                    if ((string)$candidate->id!==$p['candidate_id'] || FixtureCodec::hash($e)!==$p['candidate_evidence_hash']) {
                        throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID');
                    }
                    $this->verifier->verifyWindow($hint->signed_snapshot);
                } catch (DomainException|UsageError $error) { $failure=$error instanceof UsageError?$error->errorCode:$error->getMessage(); }
            }
            $row=$this->find($scope,$ref,true); FixtureAuthorizationCodec::retained($row,$scope);
            if ($row->state!=='valid') { return $this->receipt($row,$scope,false); }
            $receipt=$this->receipt($row,$scope,false); // Bounded serialization precedes the final time/trust check.
            $now=$current['prepared_at']??FixtureCodec::time($this->clock->wall());
            try { $wall=$this->finalTime($now,(int)$row->last_observed_at,(int)$row->lease_expires_at,$mono); }
            catch (DomainException $error) { $failure=$error->getMessage(); $wall=max($now,(int)$row->last_observed_at); }
            if ($failure===null) {
                // Fresh trust read at return boundary; unchanged receipt is verified, never re-signed.
                try { $this->verifier->verifyWindow($row->signed_snapshot); $wall=$this->finalTime($now,(int)$row->last_observed_at,(int)$row->lease_expires_at,$mono); }
                catch (DomainException $error) { $failure=$error->getMessage(); }
            }
            if ($failure!==null) {
                $this->close($row,$failure==='AUTHORIZATION_EXPIRED'?'expired':'refused',$this->safe($failure),$wall);
            } else {
                DB::table(self::TABLE)->where('id',$row->id)->update(['last_observed_at'=>$wall,'row_version'=>FixtureAuthorizationCodec::increment((string)$row->row_version)]);
            }
            $receipt['local_validation']=['state'=>$row->state,'reason_code'=>$row->reason_code];
            return $receipt; // Terminal writes commit by RETURN; no live refusal exception rollback.
        },3);
    }
    private function sameCurrent(array $old, array $current, ?array $transition=null): void
    {
        foreach (self::IMMUTABLE as $field) { if ($old[$field]!==$current[$field]) { throw new DomainException('AUTHORIZATION_BINDING_CHANGED'); } }
        if ($current['auth_version']!==($transition['auth_version']??$old['auth_version'])
            || $current['subject_core_ref']!==($transition['subject_ref']??$old['subject_core_ref'])) { throw new DomainException('AUTHORIZATION_BINDING_CHANGED'); }
    }
    private function finalTime(int $sample, int $last, int $expiry, float $mono): int
    {
        $wall=FixtureCodec::time($this->clock->wall()); $elapsed=$this->clock->monotonic()-$mono;
        if ($sample<$last || $wall<$sample || $elapsed<0) { throw new DomainException('AUTHORIZATION_CLOCK_REGRESSION'); }
        if ($wall>=$expiry || $sample+$elapsed>=$expiry) { throw new DomainException('AUTHORIZATION_EXPIRED'); }
        return max($wall,$last);
    }
    private function close(object $row, string $state, string $reason, int $now): void
    {
        DB::table(self::TABLE)->where('id',$row->id)->update(['state'=>$state,'reason_code'=>$reason,'last_observed_at'=>max((int)$row->last_observed_at,$now),
            'row_version'=>FixtureAuthorizationCodec::increment((string)$row->row_version)]);
        $row->state=$state; $row->reason_code=$reason; $row->last_observed_at=max((int)$row->last_observed_at,$now);
    }
    private function receipt(object $row, FixtureScope $scope, bool $history): array
    {
        $p=FixtureAuthorizationCodec::retained($row,$scope);
        return ['mode'=>'local_synthetic_authorization_snapshot','snapshot_ref'=>$row->snapshot_ref,'payload'=>$p,
            'payload_hash'=>$row->payload_hash,'signed_snapshot'=>$row->signed_snapshot,'signature_hash'=>$row->signature_hash,
            'historical_only'=>$history,'signature_trust'=>'not_evaluated','local_validation'=>['state'=>$row->state,'reason_code'=>$row->reason_code],
            'admission_granted'=>false,'auth_snapshot_id'=>null,'delivery_status'=>'not_implemented','node_convergence'=>'not_implemented'];
    }
    private function replay(object $row, FixtureScope $scope, array $request): array
    {
        if (!hash_equals($row->request_hash,FixtureCodec::hash($request))) { throw new DomainException('IDEMPOTENCY_CONFLICT'); }
        return $this->receipt($row,$scope,true);
    }
    private function existing(FixtureScope $scope, string $key, bool $lock=false): ?object
    {
        $query=DB::table(self::TABLE)->where('provider_id',$scope->providerId)->where('business_key',$key);
        return ($lock?$query->lockForUpdate():$query)->first();
    }
    private function consumed(FixtureScope $scope): bool
    {
        return DB::table(self::TABLE)->where('access_subject_id',$scope->subjectId)->lockForUpdate()->first()!==null;
    }
    private function candidate(FixtureScope $scope, string $ref, bool $lock=false): object
    {
        $query=DB::table(FixtureLeaseCandidateService::TABLE)->where('candidate_ref',$ref)->where('provider_id',$scope->providerId)->where('access_subject_id',$scope->subjectId);
        $row=($lock?$query->lockForUpdate():$query)->first(); if (!$row) { throw new DomainException('UNAUTHORIZED_SCOPE'); } return $row;
    }
    private function find(FixtureScope $scope, string $ref, bool $lock=false): object
    {
        if (!preg_match('/\Aasf_[a-f0-9]{32}\z/',$ref)) { throw new DomainException('PROVIDER_INPUT_INVALID'); }
        $query=DB::table(self::TABLE)->where('snapshot_ref',$ref)->where('provider_id',$scope->providerId)->where('access_subject_id',$scope->subjectId);
        $row=($lock?$query->lockForUpdate():$query)->first(); if (!$row) { throw new DomainException('UNAUTHORIZED_SCOPE'); } return $row;
    }
    private function safe(string $reason): string
    {
        return preg_match('/\A[A-Z][A-Z0-9_]{2,79}\z/',$reason)?$reason:'AUTHORIZATION_CURRENT_REFUSED';
    }
    private function guard(): void
    {
        if (config('credential_provider_authorization_fixture.enabled')!==true || config('credential_provider_lease_fixture.enabled')!==true
            || config('credential_provider_fixture.enabled')!==true || config('credential_provider_fixture.plugin_bridge_enabled')!==true
            || config('core_shadow.isolated_copy')!==true || !app()->environment('local','testing')) { throw new DomainException('CAPABILITY_UNAVAILABLE'); }
        $this->policy->requireIsolated(); if (DB::transactionLevel()!==0) { throw new DomainException('PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
        $columns=Schema::getColumnListing(self::TABLE); sort($columns);
        $expected=['id','snapshot_ref','provider_id','access_subject_id','candidate_id','issue_operation_id','binding_id','registration_id',
            'source_id','subscription_id','terms_id','usage_period_id','tariff_revision_id','business_key','request_hash','request_snapshot',
            'payload_snapshot','payload_hash','signed_snapshot','signature_hash','key_id','key_epoch','key_spki_sha256','issued_at','lease_expires_at',
            'state','reason_code','last_observed_at','row_version','created_at']; sort($expected);
        if ($columns!==$expected) { throw new DomainException('AUTHORIZATION_SCHEMA_UNKNOWN'); }
        foreach ([['snapshot_ref'],['access_subject_id'],['candidate_id'],['issue_operation_id'],['provider_id','business_key']] as $unique) {
            if (!Schema::hasIndex(self::TABLE,$unique,'unique')) { throw new DomainException('AUTHORIZATION_SCHEMA_UNKNOWN'); }
        }
        $foreign=Schema::getForeignKeys(self::TABLE);
        $refs=['candidate_id'=>FixtureLeaseCandidateService::TABLE,'access_subject_id'=>'tx_access_subject',
            'issue_operation_id'=>'tx_credential_provider_shadow_operation','binding_id'=>FixturePluginBinding::TABLE,
            'registration_id'=>'tx_credential_provider_shadow_registration','source_id'=>'tx_node_usage_source',
            'subscription_id'=>'tx_subscription','terms_id'=>'tx_subscription_terms','usage_period_id'=>'tx_usage_period','tariff_revision_id'=>'tx_tariff_revision'];
        if (count($foreign)!==count($refs)) { throw new DomainException('AUTHORIZATION_SCHEMA_UNKNOWN'); }
        foreach ($foreign as $fk) {
            $column=count($fk['columns'])===1?$fk['columns'][0]:null;
            if (!isset($refs[$column]) || $fk['foreign_table']!==$refs[$column] || $fk['foreign_columns']!==['id']
                || !in_array($fk['on_delete'],['restrict','no action'],true) || !in_array($fk['on_update'],['restrict','no action'],true)) {
                throw new DomainException('AUTHORIZATION_SCHEMA_UNKNOWN');
            } unset($refs[$column]);
        }
        if (DB::getDriverName()==='sqlite') {
            $sql=DB::table('sqlite_master')->where('type','table')->where('name',self::TABLE)->value('sql');
            if (!is_string($sql) || !preg_match('/check\s*\(\s*["`]?state["`]?\s+in\s*\(\s*\x27valid\x27\s*,\s*\x27expired\x27\s*,\s*\x27refused\x27\s*\)\s*\)/i',$sql)) {
                throw new DomainException('AUTHORIZATION_SCHEMA_UNKNOWN');
            }
        } else {
            $types=array_column(Schema::getColumns(self::TABLE),null,'name');
            if (($types['state']['type']??'')!=="enum('valid','expired','refused')") { throw new DomainException('AUTHORIZATION_SCHEMA_UNKNOWN'); }
        }
    }
}
