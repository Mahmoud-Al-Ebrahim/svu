<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Lists are paginated as `{ data, links, meta }`; `?perPage=` (1–200, default 50) and `?page=`. */
    protected function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $request->validate(['perPage' => ['nullable', 'integer', 'between:1,200'], 'page' => ['nullable', 'integer', 'min:1']]);

        return $query->paginate((int) $request->query('perPage', 50))->withQueryString();
    }
}
