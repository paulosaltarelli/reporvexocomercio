<?php

namespace App\Http\Controllers;

final class LicenseHelpController extends Controller
{
    public function __invoke()
    {
        return view('license.help');
    }
}
