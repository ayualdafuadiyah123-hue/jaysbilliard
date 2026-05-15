<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Order;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function midtransHandler(Request $request)
    {
        // Setup Midtrans config
        \Midtrans\Config::$serverKey = trim(config('services.midtrans.server_key'));
        \Midtrans\Config::$isProduction = (bool) config('services.midtrans.is_production');

        try {
            $notification = new \Midtrans\Notification();
        } catch (\Exception $e) {
            Log::error('Midtrans Webhook Error: ' . $e->getMessage());
            return response()->json(['message' => 'Error processing webhook'], 500);
        }

        $transactionStatus = $notification->transaction_status;
        $orderId = $notification->order_id;
        $customField1 = $notification->custom_field1 ?? '';

        Log::info("Midtrans Webhook: Order ID {$orderId} status is {$transactionStatus}");

        // Only handle table bookings (which have custom_field1 as booking IDs)
        if (strpos($orderId, 'ORDER-') === 0 && !empty($customField1)) {
            $bookingIds = explode(',', $customField1);
            
            if (in_array($transactionStatus, ['capture', 'settlement'])) {
                Booking::whereIn('id', $bookingIds)->update(['status' => 'confirmed']);
            } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
                Booking::whereIn('id', $bookingIds)->update(['status' => 'cancelled']);
            } elseif ($transactionStatus == 'pending') {
                Booking::whereIn('id', $bookingIds)->update(['status' => 'pending']);
            }
        }
        
        // For FnB (FNB-)
        if (strpos($orderId, 'FNB-') === 0) {
            $order = Order::with('details.menu')->where('order_id', $orderId)->first();
            if ($order && in_array($transactionStatus, ['capture', 'settlement'])) {
                if ($order->status !== 'paid') {
                    $order->update(['status' => 'paid']);
                    foreach ($order->details as $detail) {
                        $menu = $detail->menu;
                        if ($menu) {
                            $menu->decrement('stock', $detail->quantity);
                            // Record in stock transactions history
                            StockTransaction::create([
                                'menu_id' => $menu->id,
                                'type' => 'out',
                                'quantity' => $detail->quantity,
                                'note' => 'Penjualan Otomatis (Order: ' . $orderId . ')',
                            ]);
                        }
                    }
                }
            }
        }

        return response()->json(['message' => 'Webhook processed successfully'], 200);
    }
}
