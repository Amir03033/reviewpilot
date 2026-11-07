<?php

namespace App\Http\Controllers;

use App\Support\LegalDocuments;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    private const TITLES = [
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of service',
        'dpa' => 'Data processing agreement',
    ];

    public function show(string $document): Response
    {
        abort_unless(in_array($document, LegalDocuments::DOCUMENTS, true), 404);

        return Inertia::render('legal/Show', [
            'document' => $document,
            'title' => __(self::TITLES[$document]),
            'html' => LegalDocuments::render($document, app()->getLocale()),
            'documents' => collect(self::TITLES)->map(fn ($t, $k) => ['key' => $k, 'title' => __($t)])->values(),
        ]);
    }
}
