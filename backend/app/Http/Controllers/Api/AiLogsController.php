<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeminiRequestLog;
use Illuminate\Http\Request;

class AiLogsController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'account_id' => 'nullable|integer|exists:meta_accounts,id',
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date',
            'per_page'   => 'nullable|integer|min:10|max:200',
        ]);

        $query = GeminiRequestLog::where('user_id', $request->user()->id)
            ->with([
                'metaAccount:id,label,account_name',
                'report:id,period',
            ])
            ->orderByDesc('created_at');

        if ($request->account_id) {
            $query->where('meta_account_id', $request->account_id);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->paginate($request->per_page ?? 50);

        return response()->json($logs);
    }
}
