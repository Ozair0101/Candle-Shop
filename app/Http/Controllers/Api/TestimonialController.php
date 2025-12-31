<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\Request;

class TestimonialController extends Controller
{
    public function index(Request $request)
    {
        $query = Testimonial::with('user');

        if ($request->get('status') === 'approved') {
            $query->where('status', 'approved');
        }

        $testimonials = $query->latest()->limit(12)->get();

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

    public function store(Request $request)
    {
        $data = $request->validate([
            'rating'  => ['required', 'integer', 'between:1,5'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $testimonial = Testimonial::create([
            'user_id' => $request->user()->id,
            'rating'  => $data['rating'],
            'message' => $data['message'],
            'status'  => 'pending',
        ]);

        return response()->json($testimonial, 201);
    }
}
