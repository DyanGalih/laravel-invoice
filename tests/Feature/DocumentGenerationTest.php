<?php

use Crater\Models\Address;
use Crater\Models\Estimate;
use Crater\Models\EstimateItem;
use Crater\Models\Invoice;
use Crater\Models\InvoiceItem;
use Crater\Models\Item;
use Crater\Models\Payment;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
    request()->merge(['preview' => true]);
});

test('invoice previews retain company, customer, line item, and total content', function () {
    $invoice = Invoice::factory()->create([
        'invoice_number' => 'INV-PDF-TEST',
        'sub_total' => 1234,
        'total' => 1234,
        'discount' => 0,
        'tax' => 0,
        'tax_per_item' => 'NO',
    ]);
    $invoice->company->update(['name' => 'PDF Company']);
    $invoice->customer->update(['name' => 'PDF Customer']);
    $invoice->load('company', 'customer');
    Address::factory()->create([
        'customer_id' => $invoice->customer_id,
        'company_id' => $invoice->company_id,
        'type' => Address::BILLING_TYPE,
        'name' => $invoice->customer->name,
    ]);
    $item = Item::factory()->create([
        'name' => 'PDF invoice line item',
        'price' => 1234,
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'item_id' => $item->id,
        'name' => $item->name,
        'description' => 'PDF invoice description',
        'price' => 1234,
        'quantity' => 1,
        'discount' => 0,
        'discount_val' => 0,
        'tax' => 0,
        'total' => 1234,
        'company_id' => $invoice->company_id,
    ]);

    $html = (string) $invoice->getPDFData();

    expect($html)
        ->toContain($invoice->company->name)
        ->toContain($invoice->customer->name)
        ->toContain('PDF invoice line item')
        ->toContain('12.34');
});

test('estimate previews retain company, customer, line item, and total content', function () {
    $estimate = Estimate::factory()->create([
        'estimate_number' => 'EST-PDF-TEST',
        'sub_total' => 1234,
        'total' => 1234,
        'discount' => 0,
        'tax' => 0,
        'tax_per_item' => 'NO',
    ]);
    $estimate->company->update(['name' => 'PDF Company']);
    $estimate->customer->update(['name' => 'PDF Customer']);
    $estimate->load('company', 'customer');
    Address::factory()->create([
        'customer_id' => $estimate->customer_id,
        'company_id' => $estimate->company_id,
        'type' => Address::BILLING_TYPE,
        'name' => $estimate->customer->name,
    ]);
    $item = Item::factory()->create([
        'name' => 'PDF estimate line item',
        'price' => 1234,
    ]);

    EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'item_id' => $item->id,
        'name' => $item->name,
        'description' => 'PDF estimate description',
        'price' => 1234,
        'quantity' => 1,
        'discount' => 0,
        'discount_val' => 0,
        'tax' => 0,
        'total' => 1234,
        'company_id' => $estimate->company_id,
    ]);

    $html = (string) $estimate->getPDFData();

    expect($html)
        ->toContain($estimate->company->name)
        ->toContain($estimate->customer->name)
        ->toContain('PDF estimate line item')
        ->toContain('12.34');
});

test('payment previews retain company, customer, and total content', function () {
    $payment = Payment::factory()->create(['amount' => 1234]);
    $payment->company->update(['name' => 'PDF Company']);
    $payment->customer->update(['name' => 'PDF Customer']);
    $payment->load('company', 'customer');
    Address::factory()->create([
        'customer_id' => $payment->customer_id,
        'company_id' => $payment->company_id,
        'type' => Address::BILLING_TYPE,
        'name' => $payment->customer->name,
    ]);

    $html = (string) $payment->getPDFData();

    expect($html)
        ->toContain($payment->company->name)
        ->toContain($payment->customer->name)
        ->toContain('12.34');
});
