<?php

use Crater\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('sanctum configuration enforces finite token expiration', function () {
    expect(config('sanctum.expiration'))->not->toBeNull()
        ->and(config('sanctum.expiration'))->toBeGreaterThan(0);
});

test('tokens are revoked when admin password is reset', function () {
    $user = User::find(1);
    $token = $user->createToken('test-token')->plainTextToken;

    expect($user->tokens()->count())->toBeGreaterThan(0);

    // Call resetPassword method directly or through the controller
    $controller = new class extends \Crater\Http\Controllers\V1\Admin\Auth\ResetPasswordController {
        public function triggerReset($user, $password)
        {
            $this->resetPassword($user, $password);
        }
    };

    $controller->triggerReset($user, 'new-secure-password');

    $user->refresh();
    expect($user->tokens()->count())->toBe(0);
});

test('login endpoints have rate limiting middleware applied', function () {
    $routes = Route::getRoutes();

    $adminWebLogin = $routes->get('POST')['login'] ?? null;
    expect($adminWebLogin)->not->toBeNull();
    expect($adminWebLogin->gatherMiddleware())->toContain('throttle:auth-login');

    $apiAuthLogin = $routes->get('POST')['api/v1/auth/login'] ?? null;
    expect($apiAuthLogin)->not->toBeNull();
    expect($apiAuthLogin->gatherMiddleware())->toContain('throttle:auth-login');

    $installerLogin = $routes->get('POST')['api/v1/installation/login'] ?? null;
    expect($installerLogin)->not->toBeNull();
    expect($installerLogin->gatherMiddleware())->toContain('throttle:auth-login');
});

test('deployment environment example contains no hardcoded app key or debug enabled', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('APP_DEBUG=false')
        ->and($envExample)->toMatch('/APP_KEY=\s*$/m');
});

test('docker compose database service does not expose ports publicly', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->not->toContain('33006:3306');
});
