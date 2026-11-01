<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;

it('requests immutable ids on every graph call', function () {
    fakeGraph(['GET /me' => Http::response()]);

    app(GraphClient::class)->request('odata.maxpagesize=10')->get('/me');

    Http::assertSent(fn (Request $request) => $request->header('Prefer') === ['IdType="ImmutableId", odata.maxpagesize=10']
        && $request->hasHeader('Authorization', 'Bearer token'));
});
