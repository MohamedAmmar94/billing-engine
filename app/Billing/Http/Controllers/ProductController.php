<?php

declare(strict_types=1);

namespace App\Billing\Http\Controllers;

use App\Billing\Http\Requests\StoreProductRequest;
use App\Billing\Http\Requests\UpdateProductRequest;
use App\Billing\Http\Resources\ProductResource;
use App\Billing\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductController
{
    public function index(): AnonymousResourceCollection
    {
        return ProductResource::collection(Product::query()->latest('id')->paginate(25));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::query()->create([
            ...$request->validated(),
            'currency' => config()->string('billing.currency'),
        ]);

        return ProductResource::make($product)->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return ProductResource::make($product);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return ProductResource::make($product);
    }
}
