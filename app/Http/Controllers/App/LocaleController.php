<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The one write path for the interface language (sidebar, profile, guest pages). ADR 0001. */
class LocaleController extends Controller
{
    public function update(Request $request)
    {
        $locale = $request->validate([
            'locale' => ['nullable', 'string', Rule::in(array_keys(Locale::supported()))],
        ])['locale'] ?? null;

        $locale ? $request->session()->put('locale', $locale) : $request->session()->forget('locale');
        $request->user()?->forceFill(['locale' => $locale])->save();

        // Same-host only: url()->previous() trusts the Referer header.
        $back = url()->previous();
        if (parse_url($back, PHP_URL_HOST) !== $request->getHost()) {
            $back = url('/');
        }

        return redirect()->to($back);
    }
}
