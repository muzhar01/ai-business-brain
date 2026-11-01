<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Saas\Plan;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index()
    {
        $ws = Tenant::current();

        return view('app.billing', [
            'plans' => Plan::all(),
            'quota' => Quota::summary($ws),
            'subscription' => $ws->subscription('default'),
            'stripeReady' => filled(config('cashier.secret')),
        ]);
    }

    public function checkout(Request $request, string $plan)
    {
        $ws = Tenant::current();
        $plan = Plan::find($plan);
        abort_unless($price = $plan->config['stripe_price'], 404, 'This plan has no Stripe price configured.');

        if ($ws->subscribed('default')) {
            $ws->subscription('default')->swap($price);

            return redirect()->route('billing')->with('status', __('Switched to :plan.', ['plan' => $plan->name()]));
        }

        return $ws->newSubscription('default', $price)
            ->allowPromotionCodes()
            ->checkout([
                'success_url' => route('billing').'?checkout=success',
                'cancel_url' => route('billing'),
            ]);
    }

    public function portal()
    {
        return Tenant::current()->redirectToBillingPortal(route('billing'));
    }
}
