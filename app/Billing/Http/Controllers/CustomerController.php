<?php

declare(strict_types=1);

namespace App\Billing\Http\Controllers;

use App\Billing\Http\Requests\StoreCustomerRequest;
use App\Billing\Http\Resources\CustomerResource;
use App\Billing\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerController
{
    public function index(): AnonymousResourceCollection
    {
        return CustomerResource::collection(Customer::query()->latest('id')->paginate(25));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::query()->create($request->validated());

        return CustomerResource::make($customer)->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        return CustomerResource::make($customer);
    }
}
