<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MethodologyRegistry;
use Illuminate\Http\Request;

class MethodologyController extends Controller
{
    public function index(MethodologyRegistry $registry)
    {
        return $registry->all();
    }

    public function show(string $key, MethodologyRegistry $registry)
    {
        return $registry->snapshot($key);
    }

    public function compare(Request $request, string $key, MethodologyRegistry $registry)
    {
        $data = $request->validate(['from' => 'required|string|max:24']);

        return $registry->compare($key, $data['from']);
    }
}
