<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Security\TimeBasedOneTimePassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private readonly TimeBasedOneTimePassword $totp) {}

    public function edit(Request $request): View
    {
        $user = $request->user();

        $otpauthUri = null;
        $secretFormatted = null;

        if ($user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled()) {
            $otpauthUri = $this->totp->otpauthUri('Müller FLOW', $user->email, $user->two_factor_secret);
            $secretFormatted = $this->totp->formatSecret($user->two_factor_secret);
        }

        return view('account.edit', [
            'user' => $user,
            'otpauthUri' => $otpauthUri,
            'secretFormatted' => $secretFormatted,
        ]);
    }
}
