<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminProductDashboard extends Controller
{
    public function index(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        // Note: If frontend sends 'offset' as (page-1)*limit, we use it directly as skip
        $offset = (int) $request->input('offset', 0); 
        $search = trim((string) $request->input('search', ''));

        // 1. Build the base query for both counting and fetching
        $query = Product::query();

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        // 2. Get the TOTAL count before applying limits
        $total = $query->count();

        // 3. Get the paginated data
        $products = $query->skip($offset)
                        ->take($limit)
                        ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data fetched',
            'data' => [
                'products' => $products,
                'total' => $total // This is the crucial part for your frontend
            ]
        ]);
    }
    public function show($id)
    {
        Log::info("In the show");
        $product = Product::findorFail($id);
        return response()->json([
            'status' => true,
            'message' => 'Data fetched.',
            'data' =>[
                'product' => $product
            ]
        ]);
    }
    public function store(Request $request)
    {
        try
        {
            $valData = $request->validate([
                'name' => 'required|string',
                'risk_level' => 'required|integer',
                'is_active' => 'required|boolean',
                'description' => 'required|string'
            ]);
            $product = Product::create([
                'name' => $valData['name'],
                'risk_level' => $valData['risk_level'],
                'is_active' => $valData['is_active'],
                'description' => $valData['description']
            ]);
            if($product->id){
                return response()->json([
                    'status' => true,
                    'message' => 'Product created successfully',
                    'data' => [
                        'product' => $product
                    ]
                ]);
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to create product',
                'data' => []
            ]);
        }
        catch(\Exception $e)
        {
            Log::error('Product store error: ' . $e->getMessage() );
            return response()->json([
                'status' => false,
                'message' => 'Failed to store product.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function update(Request $request, $id)
    {
        Log::info("Updating product ID: " . $id);
        try {
            $valData = $request->validate([
                'name' => 'required|string',
                'risk_level' => 'required|integer',
                'is_active' => 'required|boolean',
                'description' => 'required|string'
            ]);

            // 1. Find the product first
            $product = Product::find($id);

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            // 2. Perform the update on the instance
            $product->update([
                'name' => $valData['name'],
                'risk_level' => $valData['risk_level'],
                'is_active' => $valData['is_active'],
                'description' => $valData['description']
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully',
                'data' => [
                    'product' => $product
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Product update error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update product.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function destroy($id)
    {
        try {
            $product = Product::find($id);
            if(!$product){
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found or unauthorized access',
                ], 404);
            }
            $product->delete();
            return response()->json([
                'status' => true,
                'message' => 'Product deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Product delete error: ' . $e->getMessage() );
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete product.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
