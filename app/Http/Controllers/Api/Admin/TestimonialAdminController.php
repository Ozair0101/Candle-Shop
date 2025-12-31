<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;

class TestimonialAdminController extends Controller
{
    public function index()
    {
        $testimonials = Testimonial::with('user')->latest()->get();

        return response()->json(
            $testimonials->map(function (Testimonial $t) {
                return [
                    'id'        => $t->id,
                    'user_name' => $t->user?->name ?? 'Customer',
                    'rating'    => (int) $t->rating,
                    'message'   => $t->message,
                    'created_at'=> $t->created_at,
                    'status'    => $t->status,
                ];
            })
        );
    }

    public function approve(Testimonial $testimonial)
    {
        $testimonial->update(['status' => 'approved']);

        return response()->json(['status' => 'ok']);
    }

    public function reject(Testimonial $testimonial)
    {
        $testimonial->update(['status' => 'rejected']);

        return response()->json(['status' => 'ok']);
    }
}
