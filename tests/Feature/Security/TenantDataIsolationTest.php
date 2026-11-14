<?php

use Crater\Http\Requests\DeleteCustomersRequest;
use Crater\Http\Requests\DeleteEstimatesRequest;
use Crater\Http\Requests\DeleteExpensesRequest;
use Crater\Http\Requests\DeleteInvoiceRequest;
use Crater\Http\Requests\DeleteItemsRequest;
use Crater\Http\Requests\DeletePaymentsRequest;
use Crater\Http\Requests\DeleteUserRequest;
use Crater\Http\Requests\EstimatesRequest;
use Crater\Http\Requests\InvoicesRequest;
use Crater\Models\Company;
use Crater\Models\Customer;
use Crater\Models\Estimate;
use Crater\Models\Expense;
use Crater\Models\Invoice;
use Crater\Models\Item;
use Crater\Models\Note;
use Crater\Models\Payment;
use Crater\Models\User;
use Crater\Policies\CustomerPolicy;
use Crater\Policies\NotePolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('bulk deletion requests reject foreign company identifiers', function () {
    $company = User::find(1)->companies()->first();
    $foreignCompany = Company::create([
        'name' => 'Foreign Corp',
        'owner_id' => User::find(1)->id,
        'slug' => 'foreign-corp-delete',
        'unique_hash' => 'foreign-corp-hash-delete',
    ]);

    $foreignInvoice = Invoice::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignEstimate = Estimate::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignExpense = Expense::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignItem = Item::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignPayment = Payment::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignUser = User::factory()->create();

    // 1. DeleteInvoiceRequest
    $reqInvoice = DeleteInvoiceRequest::create('/api/v1/invoices/delete', 'POST', ['ids' => [$foreignInvoice->id]]);
    $reqInvoice->headers->set('company', $company->id);
    expect(Validator::make($reqInvoice->all(), $reqInvoice->rules())->fails())->toBeTrue();

    // 2. DeleteEstimatesRequest
    $reqEstimate = DeleteEstimatesRequest::create('/api/v1/estimates/delete', 'POST', ['ids' => [$foreignEstimate->id]]);
    $reqEstimate->headers->set('company', $company->id);
    expect(Validator::make($reqEstimate->all(), $reqEstimate->rules())->fails())->toBeTrue();

    // 3. DeleteExpensesRequest
    $reqExpense = DeleteExpensesRequest::create('/api/v1/expenses/delete', 'POST', ['ids' => [$foreignExpense->id]]);
    $reqExpense->headers->set('company', $company->id);
    expect(Validator::make($reqExpense->all(), $reqExpense->rules())->fails())->toBeTrue();

    // 4. DeleteItemsRequest
    $reqItem = DeleteItemsRequest::create('/api/v1/items/delete', 'POST', ['ids' => [$foreignItem->id]]);
    $reqItem->headers->set('company', $company->id);
    expect(Validator::make($reqItem->all(), $reqItem->rules())->fails())->toBeTrue();

    // 5. DeletePaymentsRequest
    $reqPayment = DeletePaymentsRequest::create('/api/v1/payments/delete', 'POST', ['ids' => [$foreignPayment->id]]);
    $reqPayment->headers->set('company', $company->id);
    expect(Validator::make($reqPayment->all(), $reqPayment->rules())->fails())->toBeTrue();

    // 6. DeleteUserRequest
    $reqUser = DeleteUserRequest::create('/api/v1/users/delete', 'POST', ['users' => [$foreignUser->id]]);
    $reqUser->headers->set('company', $company->id);
    expect(Validator::make($reqUser->all(), $reqUser->rules())->fails())->toBeTrue();
});

test('model query scopes do not bypass company predicates with orWhere', function () {
    $company = User::find(1)->companies()->first();
    $foreignCompany = Company::create([
        'name' => 'Foreign Corp Scopes',
        'owner_id' => User::find(1)->id,
        'slug' => 'foreign-corp-scopes',
        'unique_hash' => 'foreign-corp-hash-scopes',
    ]);

    $foreignInvoice = Invoice::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignEstimate = Estimate::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignExpense = Expense::factory()->create([
        'company_id' => $foreignCompany->id,
        'notes' => 'Secret foreign expense note',
    ]);
    $foreignItem = Item::factory()->create(['company_id' => $foreignCompany->id]);

    request()->headers->set('company', $company->id);

    expect(Invoice::whereCompany()->whereInvoice($foreignInvoice->id)->exists())->toBeFalse()
        ->and(Estimate::whereCompany()->whereEstimate($foreignEstimate->id)->exists())->toBeFalse()
        ->and(Expense::whereCompany()->whereExpense($foreignExpense->id)->exists())->toBeFalse()
        ->and(Expense::whereCompany()->whereSearch('Secret foreign')->exists())->toBeFalse()
        ->and(Item::whereCompany()->whereItem($foreignItem->id)->exists())->toBeFalse();
});

test('customer and note policies deny access to records from other companies', function () {
    $user = User::find(1);
    $foreignUser = User::factory()->create();
    $foreignCompany = Company::create([
        'name' => 'Foreign Policy Corp',
        'owner_id' => $foreignUser->id,
        'slug' => 'foreign-policy-corp',
        'unique_hash' => 'foreign-policy-hash',
    ]);

    $foreignCustomer = Customer::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignNote = Note::factory()->create(['company_id' => $foreignCompany->id]);

    $customerPolicy = new CustomerPolicy();
    expect($customerPolicy->view($user, $foreignCustomer))->toBeFalse()
        ->and($customerPolicy->update($user, $foreignCustomer))->toBeFalse()
        ->and($customerPolicy->delete($user, $foreignCustomer))->toBeFalse()
        ->and($customerPolicy->restore($user, $foreignCustomer))->toBeFalse()
        ->and($customerPolicy->forceDelete($user, $foreignCustomer))->toBeFalse();

    $notePolicy = new NotePolicy();
    expect($notePolicy->manageNotes($user, $foreignNote))->toBeFalse()
        ->and($notePolicy->viewNotes($user, $foreignNote))->toBeFalse();
});

test('financial requests recalculate totals and strip mass assignment overrides', function () {
    $user = User::find(1);
    $company = $user->companies()->first();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $request = InvoicesRequest::create('/api/v1/invoices', 'POST', [
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-TEST-999',
        'invoice_date' => now()->format('Y-m-d'),
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'items' => [
            [
                'name' => 'Consulting',
                'quantity' => 2,
                'price' => 5000, // 50.00 each => subtotal 10000 (100.00)
            ],
        ],
        // Client attempt to forge total and due_amount to 1
        'sub_total' => 1,
        'total' => 1,
        'due_amount' => 1,
        'company_id' => 99999, // Attempt to forge company_id
    ]);
    $request->setUserResolver(fn () => $user);
    $request->headers->set('company', $company->id);

    $payload = $request->getInvoicePayload();

    expect($payload['sub_total'])->toBe(10000)
        ->and($payload['total'])->toBe(10000)
        ->and($payload['due_amount'])->toBe(10000)
        ->and((int) $payload['company_id'])->toBe($company->id);
});

test('estimate state machine rejects invalid status transitions', function () {
    $estimate = new Estimate(['status' => Estimate::STATUS_DRAFT]);

    expect($estimate->canTransitionTo(Estimate::STATUS_SENT))->toBeTrue()
        ->and($estimate->canTransitionTo(Estimate::STATUS_ACCEPTED))->toBeFalse();

    $sentEstimate = new Estimate(['status' => Estimate::STATUS_SENT]);
    expect($sentEstimate->canTransitionTo(Estimate::STATUS_VIEWED))->toBeTrue()
        ->and($sentEstimate->canTransitionTo(Estimate::STATUS_ACCEPTED))->toBeTrue()
        ->and($sentEstimate->canTransitionTo(Estimate::STATUS_DRAFT))->toBeFalse();

    $acceptedEstimate = new Estimate(['status' => Estimate::STATUS_ACCEPTED]);
    expect($acceptedEstimate->canTransitionTo(Estimate::STATUS_DRAFT))->toBeFalse()
        ->and($acceptedEstimate->canTransitionTo(Estimate::STATUS_SENT))->toBeFalse();
});
