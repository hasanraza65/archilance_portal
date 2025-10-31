<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\PaymentMethod;

class PaymentMethodController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }

    // Attach a new payment method to the user
    public function attach(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string'
        ]);

        $user = Auth::user();

        // Find user's subscription
        $subscription = Subscription::where('user_id', $user->id)->first();
        if (!$subscription || !$subscription->stripe_customer_id) {
            return response()->json(['error' => 'Subscription or customer not found'], 404);
        }

        try {
            // Attach the payment method
            PaymentMethod::retrieve($request->payment_method_id)->attach([
                'customer' => $subscription->stripe_customer_id,
            ]);

            // Set as default payment method
            Customer::update($subscription->stripe_customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $request->payment_method_id,
                ],
            ]);

            return response()->json(['message' => 'Payment method attached and set as default']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to attach payment method', 'details' => $e->getMessage()], 500);
        }
    }

    // List all saved payment methods
    public function list()
    {
        $user = Auth::user();
        $subscription = Subscription::where('user_id', $user->id)->first();

        if (!$subscription || !$subscription->stripe_customer_id) {
            return response()->json(['error' => 'Subscription or customer not found'], 404);
        }

        try {
            // Step 1: Get all attached cards
            $methods = \Stripe\PaymentMethod::all([
                'customer' => $subscription->stripe_customer_id,
                'type' => 'card',
            ]);

            // Step 2: Get Stripe Customer info
            $customer = \Stripe\Customer::retrieve($subscription->stripe_customer_id);
            $defaultId = $customer->invoice_settings->default_payment_method;

            // Step 3: Mark default manually
            $data = collect($methods->data)->map(function ($method) use ($defaultId) {
                return [
                    'id' => $method->id,
                    'brand' => $method->card->brand,
                    'last4' => $method->card->last4,
                    'exp_month' => $method->card->exp_month,
                    'exp_year' => $method->card->exp_year,
                    'is_default' => $method->id === $defaultId
                ];
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve payment methods', 'details' => $e->getMessage()], 500);
        }
    }


    // Set default payment method
    public function setDefault(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string'
        ]);

        $user = Auth::user();
        $subscription = Subscription::where('user_id', $user->id)->first();

        if (!$subscription || !$subscription->stripe_customer_id) {
            return response()->json(['error' => 'Subscription or customer not found'], 404);
        }

        try {
            Customer::update($subscription->stripe_customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $request->payment_method_id,
                ],
            ]);

            return response()->json(['message' => 'Default payment method updated']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update payment method', 'details' => $e->getMessage()], 500);
        }
    }

    public function delete($paymentMethodId)
    {
        $user = Auth::user();
        $subscription = Subscription::where('user_id', $user->id)->first();

        if (!$subscription || !$subscription->stripe_customer_id) {
            return response()->json(['error' => 'Subscription or customer not found'], 404);
        }

        try {
            // Get all saved payment methods BEFORE deleting
            $methods = \Stripe\PaymentMethod::all([
                'customer' => $subscription->stripe_customer_id,
                'type' => 'card',
            ]);

            // Prevent deletion if only one method exists
            if (count($methods->data) <= 1) {
                return response()->json([
                    'error' => 'At least one payment method must remain on file.'
                ], 403);
            }

            // Detach the selected payment method
            \Stripe\PaymentMethod::retrieve($paymentMethodId)->detach();

            // Re-fetch remaining methods AFTER deletion
            $remainingMethods = \Stripe\PaymentMethod::all([
                'customer' => $subscription->stripe_customer_id,
                'type' => 'card',
            ]);

            // If only 1 method remains, make it the default
            if (count($remainingMethods->data) === 1) {
                $remainingMethod = $remainingMethods->data[0]->id;

                \Stripe\Customer::update($subscription->stripe_customer_id, [
                    'invoice_settings' => [
                        'default_payment_method' => $remainingMethod
                    ]
                ]);
            }

            return response()->json(['message' => 'Payment method deleted successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete payment method',
                'details' => $e->getMessage()
            ], 500);
        }
    }


}