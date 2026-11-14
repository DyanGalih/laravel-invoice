<?php

namespace Tests\Feature\Customer;

use Crater\Models\Customer;
use Crater\Models\EmailLog;
use Crater\Models\Estimate;
use Crater\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $customer = Customer::factory()->create();

    Sanctum::actingAs(
        $customer,
        ['*'],
        'customer'
    );
});

test('get customer estimates', function () {
    $customer = Auth::guard('customer')->user();

    getJson("api/v1/{$customer->company->slug}/customer/estimates?page=1")->assertOk();
});

test('get customer estimate', function () {
    $customer = Auth::guard('customer')->user();

    $estimate = Estimate::factory()->create([
        'customer_id' => $customer->id
    ]);

    getJson("/api/v1/{$customer->company->slug}/customer/estimates/{$estimate->id}")
        ->assertOk();
});

test('rejects an invoice token on the estimate endpoint', function () {
    $estimate = Estimate::factory()->create([
        'customer_id' => Auth::guard('customer')->id(),
    ]);

    $emailLog = EmailLog::factory()->create([
        'mailable_type' => Invoice::class,
        'mailable_id' => $estimate->id,
        'token' => 'invoice-token-for-estimate',
    ]);

    getJson("/customer/estimates/{$emailLog->token}")->assertForbidden();
});

test('customer estimate mark as accepted', function () {
    $customer = Auth::guard('customer')->user();

    $estimate = Estimate::factory()->create([
        'estimate_date' => '1988-07-18',
        'expiry_date' => '1988-08-18',
        'customer_id' => $customer->id
    ]);

    $status = [
        'status' => Estimate::STATUS_ACCEPTED
    ];

    $response = postJson("api/v1/{$customer->company->slug}/customer/estimate/{$estimate->id}/status", $status)
        ->assertOk();

    $this->assertEquals($response->json()['data']['status'], Estimate::STATUS_ACCEPTED);
});

test('customer estimate mark as rejected', function () {
    $customer = Auth::guard('customer')->user();

    $estimate = Estimate::factory()->create([
        'estimate_date' => '1988-07-18',
        'expiry_date' => '1988-08-18',
        'customer_id' => $customer->id
    ]);

    $status = [
        'status' => Estimate::STATUS_REJECTED
    ];

    $response = postJson("api/v1/{$customer->company->slug}/customer/estimate/{$estimate->id}/status", $status)
        ->assertOk();

    $this->assertEquals($response->json()['data']['status'], Estimate::STATUS_REJECTED);
});
