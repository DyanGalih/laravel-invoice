<?php

use Crater\Services\SerialNumberFormatter;
use Crater\Space\FilePermissionChecker;
use Crater\Space\ModuleInstaller;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

it('keeps module upload paths unique and scoped to temporary storage', function () {
    $request = Request::create('/', 'POST', ['module' => 'sample']);
    $request->files->set('avatar', UploadedFile::fake()->create('module.zip', 1, 'application/zip'));
    $before = glob(storage_path('app/working-*')) ?: [];

    try {
        $path = ModuleInstaller::upload($request);

        expect($path)->toMatch('/^working-[0-9a-f-]+\/sample\.zip$/');
    } finally {
        foreach (array_diff(glob(storage_path('app/working-*')) ?: [], $before) as $directory) {
            File::deleteDirectory($directory);
        }
    }
});

it('keeps random serial fragments at their configured length', function () {
    $formatter = new SerialNumberFormatter();
    $generate = new ReflectionMethod($formatter, 'generateSerialNumber');
    $generate->setAccessible(true);

    expect($generate->invoke($formatter, '{{RANDOM_SEQUENCE:12}}'))->toHaveLength(12);
});

it('reports file permissions as four octal digits', function () {
    $checker = new FilePermissionChecker();
    $getPermission = new ReflectionMethod($checker, 'getPermission');
    $getPermission->setAccessible(true);

    expect($getPermission->invoke($checker, 'storage'))->toMatch('/^[0-7]{4}$/');
});
