<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Flowfact\Client\CognitoTokenCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Test-Fixture für den Cognito-Tausch (App\Flowfact\Client\CognitoTokenCache):
 * ein strukturell gültiges JWT sowie zwei Wege, den Tausch für einen Test
 * unschädlich zu machen. vorgewaermtesCognitoToken() legt das Token direkt
 * in den Zwischenspeicher, ohne HTTP-Aufruf, für Tests, denen der Tausch
 * selbst gleichgültig ist. fakeCognitoAustausch() fakt stattdessen die
 * echte Tauschantwort, wenn ein Test den Tausch selbst durchspielen soll.
 */
trait FakesCognitoToken
{
    protected static function gueltigesCognitoToken(int $gueltigSekunden = 1800): string
    {
        $kopf = self::base64UrlJson(['alg' => 'none', 'typ' => 'JWT']);
        $nutzlast = self::base64UrlJson(['exp' => time() + $gueltigSekunden, 'sub' => 'test-fixture']);

        return $kopf.'.'.$nutzlast.'.testsignatur';
    }

    protected function vorgewaermtesCognitoToken(string $zugangsschluessel, ?string $cognitoToken = null): string
    {
        $cognitoToken ??= self::gueltigesCognitoToken();

        Cache::put(CognitoTokenCache::cacheKey($zugangsschluessel), $cognitoToken, 1800);

        return $cognitoToken;
    }

    protected function fakeCognitoAustausch(?string $cognitoToken = null): string
    {
        $cognitoToken ??= self::gueltigesCognitoToken();

        Http::fake(['*/admin-token-service/public/adminUser/authenticate' => Http::response($cognitoToken)]);

        return $cognitoToken;
    }

    private static function base64UrlJson(array $daten): string
    {
        return rtrim(strtr(base64_encode(json_encode($daten, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
