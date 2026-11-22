<?php

namespace App\Http\Controllers;

use App\Services\ProductCsvImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class ProductImportController extends Controller
{
    public function index(): Response
    {
        return response()->view('products.import', ['preview' => null])->header('Cache-Control', 'private, no-store');
    }

    public function template(ProductCsvImport $import): Response
    {
        return response($import->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modelo-produtos-vendra.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function preview(Request $request, string $company, ProductCsvImport $import): Response|RedirectResponse
    {
        try {
            $data = $request->validate(['file' => 'required|file|max:256']);
            $preview = $import->preview($data['file'], $request->attributes->get('currentUser')->id);
        } catch (ValidationException $exception) {
            return $this->invalid($request, $company, $exception);
        }

        return response()->view('products.import', compact('preview'))->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, string $company, ProductCsvImport $import): RedirectResponse
    {
        try {
            $data = $request->validate(['token' => 'required|string|max:1572864']);
            $result = $import->confirm($data['token'], $request->attributes->get('currentUser')->id);
        } catch (ValidationException $exception) {
            return $this->invalid($request, $company, $exception);
        }

        return redirect()->route('product.import', ['company' => $company])->with('product_import_result', $result);
    }

    private function invalid(Request $request, string $company, ValidationException $exception): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw $exception;
        }
        // Return to the upload form without flashing the file or signed preview into the session.
        return redirect()->route('product.import', ['company' => $company])->withErrors($exception->errors());
    }
}
