<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Contracts\AdminOrderListQuery;
use App\Domains\Billing\NativeOrderQueries;
use App\Domains\Subscription\Services\PublishedOrderService;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeAdminOrderFiltersTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;
    protected array $planPrices = ['monthly' => '10.00', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';

    public function test_order_rules_and_sort_use_frozen_purchase_amounts_before_pagination(): void
    {
        $this->activate(); $orders = [];
        foreach (['monthly', 'monthly', 'onetime'] as $index => $period) {
            $user = $this->workflowActor(); $offer = collect($this->offers)->firstWhere('offer_code', $period);
            $orders[] = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], $this->workflowKey());
        }
        $reader = app(NativeOrderQueries::class);
        $rules = [['field' => 'currency', 'operator' => 'eq', 'value' => 'CNY'], ['field' => 'amount_minor', 'operator' => 'gte', 'value' => '1000']];
        $first = $reader->adminListing(new AdminOrderListQuery(perPage: 1, filters: $rules, sortBy: 'id', sortDirection: 'asc'));
        $second = $reader->adminListing(new AdminOrderListQuery(page: 2, perPage: 1, filters: $rules, sortBy: 'id', sortDirection: 'asc'));
        $this->assertSame(2, $first->meta['total']);
        $this->assertSame((int) $orders[0]->id, $first->items[0]['id']); $this->assertSame('1000', $first->items[0]['amount_minor']);
        $this->assertSame((int) $orders[1]->id, $second->items[0]['id']);
        $sorted = $reader->adminListing(new AdminOrderListQuery(perPage: 1, filters: [['field' => 'currency', 'operator' => 'eq', 'value' => 'CNY']], sortBy: 'amount_minor', sortDirection: 'asc'));
        $this->assertSame((int) $orders[2]->id, $sorted->items[0]['id']);
        $period = $reader->adminListing(new AdminOrderListQuery(filters: [['field' => 'period', 'operator' => 'eq', 'value' => 'onetime'], ['field' => 'paid_at', 'operator' => 'is_null', 'value' => '']]));
        $this->assertSame(1, $period->meta['total']);
    }
}
