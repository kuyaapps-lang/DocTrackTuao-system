<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class SpaViewportTest extends TestCase
{
    public function test_login_response_has_one_correct_viewport_and_the_application_entry(): void
    {
        $response = $this->get('/login');

        $response->assertOk();

        $document = new DOMDocument();
        $document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);

        $viewportDeclarations = $xpath->query(
            '//meta[@name="viewport"]'
        );

        $this->assertCount(1, $viewportDeclarations);
        $this->assertSame(
            'width=device-width, initial-scale=1.0',
            $viewportDeclarations->item(0)->getAttribute('content')
        );

        $headViewportDeclarations = $xpath->query(
            '/html/head/meta[@name="viewport"]'
        );

        $this->assertCount(1, $headViewportDeclarations);
        $this->assertSame(
            'width=device-width, initial-scale=1.0',
            $headViewportDeclarations->item(0)->getAttribute('content')
        );

        $applicationEntries = array_filter(
            iterator_to_array($xpath->query('//script[@src]')),
            static function ($script): bool {
                $source = $script->getAttribute('src');

                return str_contains($source, 'resources/js/app.js') ||
                    preg_match('/\/build\/assets\/app-[^\/]+\.js$/', $source) === 1;
            }
        );

        $this->assertNotEmpty($applicationEntries);
    }
}
