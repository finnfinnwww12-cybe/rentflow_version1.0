<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::orderBy('created_at', 'desc');

        $notifications = $query->paginate($request->query('limit', 30));

        $notifications->getCollection()->transform(fn($n) => [
            'id'               => $n->id,
            'title'            => $n->title,
            'message'          => $n->message,
            'type'             => $n->type,
            'read'             => $n->read,
            'created_at'       => $n->created_at,
            'metadata'         => $n->metadata,
        ]);

        return $this->paginated($notifications);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'nullable|string',
            'recipients' => 'nullable|string',
        ]);

        $notification = Notification::create([
            'user_id' => $request->user()->id,
            'title' => $v['title'],
            'message' => $v['message'],
            'type' => $v['type'] ?? 'broadcast',
            'read' => false,
        ]);

        // Send actual emails to targeted or all tenants
        try {
            $recipientsType = $v['type'] ?? 'broadcast';
            $tenantsQuery = \App\Models\Tenant::whereNotNull('email')->where('email', '!=', '');

            if ($recipientsType === 'targeted' && !empty($v['recipients'])) {
                // Parse "Rooms: 101, 102"
                $roomsText = str_replace('Rooms: ', '', $v['recipients']);
                $roomsArray = array_map('trim', explode(',', $roomsText));
                
                // Fetch active rooms and their assigned tenants
                $roomIds = \App\Models\Room::whereIn('room_number', $roomsArray)->pluck('id');
                $tenantsQuery->whereIn('room_id', $roomIds);
            }

            $activeTenants = $tenantsQuery->get();

            foreach ($activeTenants as $tenant) {
                Mail::raw($v['message'], function($message) use ($tenant, $v) {
                    $message->to($tenant->email)
                            ->subject($v['title']);
                });
            }
        } catch (\Exception $e) {
            // Log the mail failure but don't disrupt the user request
            Log::error('Failed to dispatch announcement emails: ' . $e->getMessage());
        }

        return $this->success($notification, 'Announcement created and email dispatch triggered', 201);
    }

    public function markRead(string $id, Request $request): JsonResponse
    {
        $n = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)->first();
        if (!$n) return $this->error('Notification not found', 'not_found', null, 404);

        $n->update(['read' => true]);
        return $this->success(null, 'Notification marked as read');
    }

    public function destroy(string $id, Request $request): JsonResponse
    {
        $n = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)->first();
        if (!$n) return $this->error('Notification not found', 'not_found', null, 404);

        $n->delete();
        return $this->success(null, 'Notification deleted');
    }

    public function sendSms(Request $request): JsonResponse
    {
        $v = $request->validate([
            'roomIds' => 'required|array',
            'roomIds.*' => 'string',
            'message' => 'required|string',
            'type' => 'sometimes|in:payment-reminder,general',
        ]);

        // In production, integrate with an SMS provider. For now, log it.
        $rooms = \App\Models\Room::whereIn('id', $v['roomIds'])
            ->with('tenant')->get();

        $sent = 0;
        foreach ($rooms as $room) {
            if ($room->tenant && $room->tenant->phone) {
                // Create notification record
                Notification::create([
                    'user_id' => $request->user()->id,
                    'title' => 'SMS sent to ' . $room->tenant->name,
                    'message' => $v['message'],
                    'type' => $v['type'] ?? 'general',
                    'read' => false,
                ]);
                $sent++;
            }
        }

        return $this->success([
            'sent' => $sent,
            'total' => count($v['roomIds']),
        ], "SMS notification queued for {$sent} tenants");
    }

    /**
     * Store a demo booking request from the public website landing page.
     */
    public function storeDemoBooking(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'company' => 'nullable|string|max:255',
            'message' => 'nullable|string',
        ]);

        $admin = \App\Models\User::first();
        if (!$admin) {
            return $this->error('No administrator found to receive the booking.', 'admin_not_found', null, 500);
        }

        $companyText = $v['company'] ? " (Company: {$v['company']})" : "";
        $messageText = $v['message'] ? "\n\nMessage: {$v['message']}" : "";

        $notification = Notification::create([
            'user_id' => $admin->id,
            'title' => "Booking Request: " . $v['name'],
            'message' => "Email: {$v['email']}{$companyText}{$messageText}",
            'type' => 'booking',
            'read' => false,
        ]);

        return $this->success($notification, 'Booking request received successfully!', 201);
    }

}
