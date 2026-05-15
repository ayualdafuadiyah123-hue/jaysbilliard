<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Table;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\Log;
use Midtrans\Config;
use Midtrans\Transaction;
use Midtrans\Snap;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $today = \Carbon\Carbon::now()->toDateString();

        // Get total bookings (all time) for the logged-in user
        $totalBookings = Booking::where('customer_name', $user->name)->count();
        
        // Calculate total hours played from DB
        $dbBookings = Booking::where('customer_name', $user->name)->get();
        $totalHours = 0;
        foreach ($dbBookings as $booking) {
            $start = \Carbon\Carbon::parse($booking->start_time);
            $end = \Carbon\Carbon::parse($booking->end_time);
            $totalHours += $end->diffInHours($start);
        }
        
        $tables = Table::with(['bookings' => function($query) use ($today) {
            $query->where('booking_date', $today)
                  ->where('status', 'confirmed');
        }])->get();

        $activeBookingsCount = Booking::where('booking_date', $today)
                                ->where('customer_name', $user->name)
                                ->count();

        // Get recent activities from DB (last 5 bookings)
        $recentActivities = Booking::where('customer_name', $user->name)
                                ->orderBy('created_at', 'desc')
                                ->take(5)
                                ->get();

        $topbar_title = "User Dashboard";
        $topbar_sub = "Selamat datang kembali, " . $user->name . ". Pantau pesanan dan poin Anda di sini.";
        
        return view('dashboard_user.dashboard', compact('user', 'totalBookings', 'totalHours', 'tables', 'topbar_title', 'topbar_sub', 'activeBookingsCount', 'recentActivities'));
    }

    public function meja()
    {
        $user = Auth::user();
        $today = \Carbon\Carbon::now()->toDateString();
        $tables = Table::with(['bookings' => function($query) use ($today) {
            $query->where('booking_date', $today);
        }])->get();
        
        $topbar_title = "Meja";
        $topbar_sub = "Pilih meja favorit Anda dan tentukan waktu bermain";

        return view('dashboard_user.meja', compact('user', 'tables', 'topbar_title', 'topbar_sub'));
    }

    public function konfirmasi()
    {
        $user = Auth::user();
        $topbar_title = "Konfirmasi Pembayaran Meja";
        $topbar_sub = "Selesaikan pembayaran untuk mengamankan pesanan Anda";

        return view('dashboard_user.konfirmasi_pembayaran', compact('user', 'topbar_title', 'topbar_sub'));
    }

    public function fnb()
    {
        $user = Auth::user();
        $menus = Menu::all();
        $tables = Table::all();
        
        $topbar_title = "Makanan dan Minuman";
        $topbar_sub = "Pilih menu favorit Anda dan nikmati saat bermain";

        return view('dashboard_user.fnb', compact('user', 'menus', 'tables', 'topbar_title', 'topbar_sub'));
    }

    public function fnbKonfirmasi()
    {
        $user = Auth::user();
        $topbar_title = "Konfirmasi Pembayaran Makanan & Minuman";
        $topbar_sub = "Selesaikan pesanan untuk makanan & minuman Anda";

        return view('dashboard_user.fnb_konfirmasi', compact('user', 'topbar_title', 'topbar_sub'));
    }

    public function fnbCheckout(Request $request)
    {
        $validated = $request->validate([
            'table_id' => 'nullable|integer|exists:tables,id',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:menus,id',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $orderId = 'FNB-' . uniqid() . '-' . time();
        $user = Auth::user();
        $menuIds = collect($validated['items'])->pluck('id')->all();
        $menus = Menu::whereIn('id', $menuIds)->get()->keyBy('id');
        $subtotal = 0;
        $itemDetails = [];

        // Calculate subtotal and prepare items
        foreach ($validated['items'] as $item) {
            $menu = $menus->get($item['id']);
            $quantity = (int) $item['quantity'];
            $price = (int) $menu->price;
            $subtotal += $price * $quantity;

            $itemDetails[] = [
                'id' => (string) $menu->id,
                'price' => $price,
                'quantity' => $quantity,
                'name' => substr($menu->name, 0, 50),
            ];
        }

        $tax = (int) round($subtotal * 0.1);
        $total = $subtotal + $tax;

        // Find active booking for the table (if any)
        $bookingId = null;
        if ($validated['table_id']) {
            $activeBooking = Booking::where('table_id', $validated['table_id'])
                                    ->where('booking_date', now()->toDateString())
                                    ->where('status', 'confirmed')
                                    ->first();
            $bookingId = $activeBooking ? $activeBooking->id : null;
        }

        // Save Order to DB
        $order = \App\Models\Order::create([
            'booking_id' => $bookingId, // Will be null if no active booking
            'order_id' => $orderId,
            'total_price_fnb' => $total,
            'status' => 'pending',
        ]);

        // Save Order Details
        foreach ($validated['items'] as $item) {
            $menu = $menus->get($item['id']);
            \App\Models\OrderDetail::create([
                'order_id' => $order->id,
                'menu_id' => $menu->id,
                'quantity' => $item['quantity'],
                'price' => $menu->price,
            ]);
        }

        // Setup Midtrans
        \Midtrans\Config::$serverKey = trim(config('services.midtrans.server_key'));
        \Midtrans\Config::$isProduction = (bool) config('services.midtrans.is_production');
        \Midtrans\Config::$isSanitized = true;
        \Midtrans\Config::$is3ds = true;

        if ($tax > 0) {
            $itemDetails[] = [
                'id' => 'SERVICE-TAX',
                'price' => $tax,
                'quantity' => 1,
                'name' => 'Service & Tax',
            ];
        }

        $params = array(
            'transaction_details' => array(
                'order_id' => $orderId,
                'gross_amount' => $total,
            ),
            'item_details' => $itemDetails,
            'customer_details' => array(
                'first_name' => $user->name,
                'phone' => $user->phone ?? '-',
            ),
        );

        $snapToken = \Midtrans\Snap::getSnapToken($params);

        return response()->json([
            'success' => true,
            'snap_token' => $snapToken,
            'order_id' => $orderId,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ]);
    }

    public function fnbSuccess(Request $request)
    {
        $orderId = $request->order_id;
        
        // Setup Midtrans
        Config::$serverKey = trim(config('services.midtrans.server_key'));
        Config::$isProduction = (bool) config('services.midtrans.is_production');

        try {
            $status = Transaction::status($orderId);
            $transactionStatus = $status->transaction_status;

            if (in_array($transactionStatus, ['settlement', 'capture'])) {
                $order = Order::with('details.menu')->where('order_id', $orderId)->first();
                
                if ($order && $order->status !== 'paid') {
                    $order->update(['status' => 'paid']);
                    
                    foreach ($order->details as $detail) {
                        $menu = $detail->menu;
                        if ($menu) {
                            $menu->decrement('stock', $detail->quantity);
                            
                            StockTransaction::create([
                                'menu_id' => $menu->id,
                                'type' => 'out',
                                'quantity' => $detail->quantity,
                                'note' => 'Penjualan (Order: ' . $orderId . ')',
                            ]);
                        }
                    }
                    return response()->json(['success' => true, 'message' => 'Stok berhasil dikurangi']);
                }
            }
            
            return response()->json(['success' => false, 'message' => 'Pembayaran belum lunas atau sudah diproses']);
        } catch (\Exception $e) {
            Log::error('FnB Success Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function history()
    {
        $user = Auth::user();
        $bookings = Booking::with('table')
                            ->where('customer_name', $user->name)
                            ->orderBy('booking_date', 'desc')
                            ->orderBy('start_time', 'desc')
                            ->get();
        
        $topbar_title = "Riwayat Pesanan";
        $topbar_sub = "Lihat daftar pesanan dan riwayat bermain Anda";

        return view('dashboard_user.history', compact('user', 'bookings', 'topbar_title', 'topbar_sub'));
    }
}
