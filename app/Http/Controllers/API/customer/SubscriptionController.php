<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\BillingDetail;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription as StripeSubscription;
use Carbon\Carbon;
use Stripe\PaymentIntent;

class SubscriptionController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }

    public function createPaymentIntent(Request $request)
    {
        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $request->amount, // Amount in cents
                'currency' => 'usd',
                'payment_method' => $request->payment_method_id,
                'confirmation_method' => 'manual',
                'confirm' => true,
                'return_url' => url('/payment/complete'), // Optional return URL
            ]);

            return response()->json([
                'client_secret' => $paymentIntent->client_secret,
                'status' => $paymentIntent->status,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index()
    {
        $subscriptions = Subscription::where('user_id', Auth::id())->get();
        return response()->json($subscriptions);
    }


    public function getActive()
    {
        $user = Auth::user();

        $subscription = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found'], 404);
        }

        return response()->json([
            'subscription' => $subscription
        ]);
    }



    public function store(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|in:1,2,3',
            'payment_method_id' => 'required',
        ]);

        $user = Auth::user();
        $planId = $request->plan_id;

        // ⛔ Check for existing active subscription
        $existing = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'You already have an active subscription.',
                'subscription_id' => $existing->id
            ], 409);
        }

        $prices = [
            1 => 'price_1QexsHRpKuqY4dV3aHTkRWS1', // Replace with actual Stripe price ID
            2 => 'price_1QexsHRpKuqY4dV3aHTkRWS1',
            3 => 'price_1QexsHRpKuqY4dV3aHTkRWS1',
        ];

        try {
            $customer = \Stripe\Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'payment_method' => $request->payment_method_id,
                'invoice_settings' => ['default_payment_method' => $request->payment_method_id],
            ]);

            $stripeSubscription = \Stripe\Subscription::create([
                'customer' => $customer->id,
                'items' => [['price' => $prices[$planId]]],
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $planId,
                'stripe_customer_id' => $customer->id,
                'stripe_subscription_id' => $stripeSubscription->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => Carbon::now()->addMonth(), // ✅ Add 1 month
            ]);

            $billing = BillingDetail::where('user_id', Auth::id())->first();

            if (!$billing) {
                $billing = new BillingDetail();
                $billing->user_id = Auth::id();
            }

            // Fill or update the fields
            $billing->first_name = $request->first_name;
            $billing->last_name = $request->last_name;
            $billing->email = $request->email;
            $billing->phone = $request->phone;
            $billing->company = $request->company;
            $billing->address = $request->address;
            $billing->city = $request->city;
            $billing->state = $request->state;
            $billing->zip = $request->zip;
            $billing->country = $request->country;

            $billing->save();


            return response()->json([
                'message' => 'Subscription created successfully',
                'subscription' => $subscription
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create subscription',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    public function getBillingDetail()
    {

        $user_id = Auth::user()->id;

        $data = BillingDetail::where('user_id', $user_id)->first();

        return response()->json([
            'billing_detail' => $data
        ]);

    }

    public function updateBillingDetail(Request $request)
    {

        $billing = BillingDetail::where('user_id', Auth::id())->first();

        if (!$billing) {
            $billing = new BillingDetail();
            $billing->user_id = Auth::id();
        }

        // Fill or update the fields
        $billing->first_name = $request->first_name;
        $billing->last_name = $request->last_name;
        $billing->email = $request->email;
        $billing->phone = $request->phone;
        $billing->company = $request->company;
        $billing->address = $request->address;
        $billing->city = $request->city;
        $billing->state = $request->state;
        $billing->zip = $request->zip;
        $billing->country = $request->country;

        $billing->save();


        return response()->json([
            'message' => 'Billing details updated successfully',
            'subscription' => $billing
        ]);

    }


    public function cancel($id)
    {
        $subscription = Subscription::where('id', $id)->where('user_id', Auth::id())->firstOrFail();

        try {
            StripeSubscription::update($subscription->stripe_subscription_id, ['cancel_at_period_end' => true]);

            $subscription->status = 'canceled';
            $subscription->ends_at = now();
            $subscription->save();

            return response()->json(['message' => 'Subscription canceled']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Cancellation failed', 'details' => $e->getMessage()], 500);
        }
    }

    public function adminAllSubscriptions()
    {
        $subscriptions = Subscription::with(['user', 'billingDetail'])
            ->latest()
            ->paginate(10); // Pagination added

        return response()->json([
            'subscriptions' => $subscriptions
        ]);
    }

}
