<?php

namespace App\Http\Middleware;

use App\Services\CurrentWebsite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * İsteğin Host'unu CurrentWebsite'e verir; çözümleme tembeldir (ilk get()'te).
 * Web grubuna eklidir: vitrin de panel de aynı çözümlemeyi görür.
 */
class ResolveWebsite
{
    public function __construct(private readonly CurrentWebsite $website) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->website->setHost($request->getHost());

        return $next($request);
    }
}
