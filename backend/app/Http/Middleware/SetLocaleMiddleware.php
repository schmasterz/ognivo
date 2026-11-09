<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware dynamically determining and setting the application locale from HTTP headers or query parameter.
 */
final class SetLocaleMiddleware
{
    /**
     * Supported application locales.
     *
     * @var list<string>
     */
    private const array SUPPORTED_LOCALES = ['en', 'bg'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->determineLocale($request);

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /**
     * Determine preferred locale from request sources.
     */
    private function determineLocale(Request $request): string
    {
        // 1. Explicit query parameter
        $paramLocale = $request->query('lang') ?? $request->query('locale');
        if (is_string($paramLocale) && in_array($paramLocale, self::SUPPORTED_LOCALES, true)) {
            return $paramLocale;
        }

        // 2. Custom header (e.g. X-Locale: bg)
        $headerLocale = $request->header('X-Locale');
        if (is_string($headerLocale) && in_array(strtolower($headerLocale), self::SUPPORTED_LOCALES, true)) {
            return strtolower($headerLocale);
        }

        // 3. Standard Accept-Language header
        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);
        if ($preferred !== null && in_array($preferred, self::SUPPORTED_LOCALES, true)) {
            return $preferred;
        }

        return 'en';
    }
}
