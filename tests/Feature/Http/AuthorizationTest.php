<?php

use Crater\Models\Company;
use Crater\Models\Customer;
use Crater\Models\Invoice;
use Crater\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('unauthenticated admin requests remain unauthorized', function () {
    getJson('api/v1/invoices')->assertUnauthorized();
});

test('a user without the invoice ability remains forbidden', function () {
    $company = User::find(1)->companies()->first();
    $user = User::factory()->create(['role' => 'user']);
    $user->companies()->attach($company->id);

    Sanctum::actingAs($user, ['*']);

    $this->withHeaders(['company' => $company->id]);

    getJson('api/v1/invoices')->assertForbidden();
});

test('cross-company invoice access remains forbidden', function () {
    $user = User::find(1);
    $company = $user->companies()->first();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    Sanctum::actingAs($user, ['*']);

    $this->withHeaders(['company' => $company->id]);

    getJson("api/v1/invoices/{$invoice->id}")->assertForbidden();
});

test('disabled customer portal access remains unauthorized', function () {
    $customer = Customer::factory()->create(['enable_portal' => false]);

    Sanctum::actingAs($customer, ['*'], 'customer');

    getJson("api/v1/{$customer->company->slug}/customer/dashboard")
        ->assertUnauthorized();
});

test('unauthenticated PDF access still redirects to login', function () {
    $invoice = Invoice::factory()->create();

    get("/invoices/pdf/{$invoice->unique_hash}")->assertRedirect('/login');
});

test('cron requests without the configured token remain unauthorized', function () {
    config(['services.cron_job.auth_token' => 'test-cron-token']);

    getJson('api/cron')->assertUnauthorized();
});
