<?php

namespace App\Domains\InternalApi;

use App\Domains\CredentialProvider\ProviderError;
use App\Domains\Subscription\Contracts\BrowserDeliveries;
use App\Models\User;
use DomainException;

/** Only public metadata and recipient-bound signed grants cross the Core transport. */
final class BrowserDeliveryReadOperations
{
    public function __construct(private readonly BrowserDeliveries $deliveries) {}

    public function options(User $actor, array $input): ReadOperationResult
    {
        return $this->read(fn (): array => $this->deliveries->browserOptions((int) $actor->id));
    }

    public function authorize(User $actor, array $input): ReadOperationResult
    {
        return $this->read(fn (): array => $this->deliveries->browserAuthorize((int) $actor->id, $input));
    }

    private function read(\Closure $run): ReadOperationResult
    {
        try { return new ReadOperationResult($run()); }
        catch (ProviderError $error) {
            throw new OperationFailure($error->codeName, $error->retryable ? 503 : 409);
        } catch (DomainException $error) {
            $code = preg_match('/\ANATIVE_SUBSCRIPTION_DELIVERY_[A-Z0-9_]{1,64}\z/D', $error->getMessage())
                ? $error->getMessage() : 'SUBSCRIPTION_DELIVERY_UNAVAILABLE';
            throw new OperationFailure($code, str_ends_with($code, '_MISSING') ? 404
                : (str_ends_with($code, '_INPUT_INVALID') ? 422 : 409));
        }
    }
}
