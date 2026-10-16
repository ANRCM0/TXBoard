<?php

namespace App\Console\Commands;

use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\Subscription\Services\PlanPublicationService;
use Illuminate\Console\Command;
use Throwable;

final class CorePlanPublish extends Command
{
    protected $signature = 'core:plan-publish {plan} {--key=} {--currency=CNY} {--timezone=UTC}';
    protected $description = 'Prepare an immutable native catalog revision; does not enable native business runtime';

    public function handle(PlanPublicationService $publication): int
    {
        try {
            $plan = $this->argument('plan');
            $key = $this->option('key');
            if (!is_string($plan) || !preg_match('/^[1-9][0-9]*$/D', $plan)
                || (string) (int) $plan !== $plan || !is_string($key)) {
                $this->line('{"error_code":"PLAN_PUBLICATION_COMMAND_INVALID"}');
                return self::FAILURE;
            }
            $result = $publication->publish((int) $plan, $key,
                (string) $this->option('currency'), (string) $this->option('timezone'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = $error->getMessage();
            if (!$error instanceof InstallationAuthorityException
                && !preg_match('/^PUBLICATION_[A-Z_]{1,64}$/D', $code)) {
                $code = 'PLAN_PUBLICATION_FAILED';
            }
            $this->line(json_encode(['error_code' => $code]));
            return self::FAILURE;
        }
    }
}
