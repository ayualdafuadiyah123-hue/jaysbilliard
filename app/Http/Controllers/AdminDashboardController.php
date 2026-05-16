<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Table;
use App\Models\Menu;
use Barryvdh\DomPDF\Facade\Pdf;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();
        
        // Eager load only confirmed bookings for today to determine table status
        $tables = Table::with(['bookings' => function($query) use ($today) {
            $query->where('booking_date', $today);
        }])->get();

        $menus = Menu::latest()->get();

        // Calculate dynamic stats for today
        $pendapatanHariIni = \App\Models\Booking::where('booking_date', $today)
                                ->whereIn('status', ['booked', 'confirmed', 'completed'])
                                ->sum('total_price');
                                
        $totalPemesanan = \App\Models\Booking::where('booking_date', $today)->count();

        // Calculate hourly revenue for chart (10:00 to 22:00)
        $hourlyRevenue = [];
        $maxRevenue = 0;
        
        for ($i = 10; $i <= 22; $i++) {
            $startHour = sprintf('%02d:00:00', $i);
            $endHour = sprintf('%02d:59:59', $i);
            
            $revenue = \App\Models\Booking::where('booking_date', $today)
                        ->whereIn('status', ['booked', 'confirmed', 'completed'])
                        ->whereBetween('start_time', [$startHour, $endHour])
                        ->sum('total_price');
                        
            $hourlyRevenue[$i] = $revenue;
            if ($revenue > $maxRevenue) {
                $maxRevenue = $revenue;
            }
        }
        
        // Calculate percentage for each hour
        $chartData = [];
        foreach ($hourlyRevenue as $hour => $revenue) {
            $percentage = $maxRevenue > 0 ? ($revenue / $maxRevenue) * 100 : 0;
            // Minimum height for visibility if there's revenue, otherwise 10%
            $chartData[$hour] = [
                'revenue' => $revenue,
                'percentage' => $revenue > 0 ? max(20, $percentage) : 10
            ];
        }

        return view('dashboard_admin.dashboard', compact('tables', 'menus', 'pendapatanHariIni', 'totalPemesanan', 'chartData'));

    }

    public function history()
    {
        // Get all bookings with nested F&B relations
        $bookings = \App\Models\Booking::with(['table', 'user', 'orders.details.menu'])->latest()->get();
        
        // Basic stats calculations
        $totalBookings = $bookings->count();
        // Since F&B items details are not fully relational yet, we'll just mock it or count orders.
        $totalOrders = $bookings->sum(function ($booking) {
            return $booking->orders->count(); 
        });

        return view('dashboard_admin.pemesanan', compact('bookings', 'totalBookings', 'totalOrders'));
    }

    public function exportPdf()
    {
        $bookings = \App\Models\Booking::with(['table', 'user', 'orders.details.menu'])->latest()->get();
        
        $pdf = Pdf::loadView('dashboard_admin.history_pdf', compact('bookings'))
                  ->setPaper('a4', 'landscape');
                  
        return $pdf->download('history_pemesanan_' . date('Y-m-d') . '.pdf');
    }

    public function checkNotifications()
    {
        // Get 5 latest pending bookings
        $latestBookings = \App\Models\Booking::with('table')
                                          ->where('status', 'pending')
                                          ->latest()
                                          ->take(5)
                                          ->get()
                                          ->map(function($b) {
                                              $b->type = 'booking';
                                              return $b;
                                          });

        // Get 5 latest pending F&B orders with details
        $latestOrders = \App\Models\Order::with(['booking.table', 'details.menu'])
                                      ->where('status', 'pending')
                                      ->latest()
                                      ->take(5)
                                      ->get()
                                      ->map(function($o) {
                                          $o->type = 'order';
                                          // Format items string
                                          $o->items_summary = $o->details->map(function($d) {
                                              return ($d->menu->name ?? 'Menu') . ' (x' . $d->quantity . ')';
                                          })->implode(', ');
                                          return $o;
                                      });

        // Combine and Sort by created_at desc
        $combined = $latestBookings->concat($latestOrders)->sortByDesc('created_at')->values();

        $newBookingsCount = \App\Models\Booking::where('created_at', '>=', now()->subSeconds(60))
                                          ->where('status', 'pending')
                                          ->count();

        $newOrdersCount = \App\Models\Order::where('created_at', '>=', now()->subSeconds(60))
                                      ->where('status', 'pending')
                                      ->count();

        return response()->json([
            'new_bookings' => $newBookingsCount,
            'new_orders' => $newOrdersCount,
            'total_new' => $newBookingsCount + $newOrdersCount,
            'notifications' => $combined
        ]);
    }

    public function transaksi()
    {
        $transactions = \App\Models\Booking::with(['table', 'user', 'orders'])->latest()->get();
        return view('dashboard_admin.transaksi', compact('transactions'));
    }

    public function confirmBooking($id)
    {
        $booking = \App\Models\Booking::findOrFail($id);
        // Only confirm if status is 'booked' (paid, awaiting admin confirmation)
        if ($booking->status === 'booked') {
            try {
                $start = \Carbon\Carbon::parse($booking->start_time);
                $end = \Carbon\Carbon::parse($booking->end_time);
                $durationInMinutes = $start->diffInMinutes($end);
                
                $now = now();
                $booking->status = 'confirmed';
                $booking->start_time = $now->format('H:i:s');
                $booking->end_time = $now->addMinutes($durationInMinutes)->format('H:i:s');
                $booking->save();
            } catch (\Exception $e) {
                // Fallback if parsing fails
                $booking->status = 'confirmed';
                $booking->save();
            }
        }

        return back()->with('success', 'Booking berhasil dikonfirmasi! Sesi permainan dimulai.');
    }

    public function cancelBooking($id)
    {
        $booking = \App\Models\Booking::findOrFail($id);
        // Only cancel if status is 'booked' or 'pending'
        if (in_array($booking->status, ['booked', 'pending'])) {
            $booking->status = 'cancelled';
            $booking->save();
        }

        return back()->with('success', 'Booking berhasil dibatalkan. Meja kini tersedia kembali.');
    }

    public function endSession($id)
    {
        $booking = \App\Models\Booking::findOrFail($id);
        $booking->status = 'completed';
        $booking->save();

        return back()->with('success', 'Sesi permainan telah diakhiri.');
    }

    public function deleteBooking($id)
    {
        $booking = \App\Models\Booking::findOrFail($id);
        $booking->delete();

        return back()->with('success', 'Riwayat pemesanan berhasil dihapus secara permanen.');
    }

    public function completeBooking($id)
    {
        $booking = \App\Models\Booking::findOrFail($id);
        $booking->status = 'completed';
        $booking->save();

        return back()->with('success', 'Pesanan telah diselesaikan.');
    }

    public function updateStatus(Request $request, $id)
    {
        $booking = \App\Models\Booking::findOrFail($id);
        $booking->status = $request->status;
        $booking->save();

        return back()->with('success', 'Status pemesanan berhasil diperbarui menjadi ' . $request->status);
    }

    public function profile()
    {
        // Get authenticated user or redirect to login if not authenticated
        $user = auth()->user();
        
        if (!$user) {
            return redirect()->route('admin.login')->with('error', 'Silakan login terlebih dahulu.');
        }
        
        return view('dashboard_admin.profile_settings', compact('user'));
    }
}
