<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Support\PayloadFingerprint;
use Illuminate\Http\Request;

it('generates consistent fingerprints for same request', function () {
    $request = Request::create('/api/payments', 'POST', ['amount' => 100]);

    $fp1 = PayloadFingerprint::generate($request);
    $fp2 = PayloadFingerprint::generate($request);

    expect($fp1)->toBe($fp2);
});

it('generates different fingerprints for different data', function () {
    $request1 = Request::create('/api/payments', 'POST', ['amount' => 100]);
    $request2 = Request::create('/api/payments', 'POST', ['amount' => 200]);

    $fp1 = PayloadFingerprint::generate($request1);
    $fp2 = PayloadFingerprint::generate($request2);

    expect($fp1)->not->toBe($fp2);
});

it('generates different fingerprints for different paths', function () {
    $request1 = Request::create('/api/payments', 'POST', ['amount' => 100]);
    $request2 = Request::create('/api/orders', 'POST', ['amount' => 100]);

    $fp1 = PayloadFingerprint::generate($request1);
    $fp2 = PayloadFingerprint::generate($request2);

    expect($fp1)->not->toBe($fp2);
});

it('generates different fingerprints for different methods', function () {
    $request1 = Request::create('/api/payments', 'POST', ['amount' => 100]);
    $request2 = Request::create('/api/payments', 'PUT', ['amount' => 100]);

    $fp1 = PayloadFingerprint::generate($request1);
    $fp2 = PayloadFingerprint::generate($request2);

    expect($fp1)->not->toBe($fp2);
});

it('compares fingerprints correctly', function () {
    $fp1 = 'abc123';
    $fp2 = 'abc123';
    $fp3 = 'xyz789';

    expect(PayloadFingerprint::compare($fp1, $fp2))->toBeTrue();
    expect(PayloadFingerprint::compare($fp1, $fp3))->toBeFalse();
});
