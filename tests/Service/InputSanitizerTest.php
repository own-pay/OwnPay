<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\System\InputSanitizer;
use PHPUnit\Framework\TestCase;

final class InputSanitizerTest extends TestCase
{
    public function testHtmlStripsScriptTags(): void
    {
        $result = InputSanitizer::html('<script>alert("x")</script>');
        $this->assertSame('alert("x")', $result);
    }

    public function testHtmlStripsTagsAndTrimsWhitespace(): void
    {
        $result = InputSanitizer::html('  <p>hello</p>  ');
        $this->assertSame('hello', $result);
    }

    public function testHtmlPreservesEntitiesInPlainText(): void
    {
        $result = InputSanitizer::html("a&b'c\"d");
        $this->assertSame("a&b'c\"d", $result);
    }

    public function testHtmlMapsRecursivelyOverArrays(): void
    {
        $input = [
            'name' => '<b>Alice</b>',
            'nested' => ['<i>Bob</i>', 'plain'],
        ];
        $result = InputSanitizer::html($input);
        $this->assertSame('Alice', $result['name']);
        $this->assertSame('Bob', $result['nested'][0]);
        $this->assertSame('plain', $result['nested'][1]);
    }

    public function testHtmlPassesThroughNonStringScalars(): void
    {
        $this->assertSame(42, InputSanitizer::html(42));
        $this->assertSame(true, InputSanitizer::html(true));
        $this->assertNull(InputSanitizer::html(null));
    }

    public function testTrimRemovesSurroundingWhitespace(): void
    {
        $this->assertSame('hello', InputSanitizer::trim("  hello\t\n"));
    }

    public function testTrimDoesNotTouchInternalContent(): void
    {
        $this->assertSame('<b>raw</b>', InputSanitizer::trim('<b>raw</b>'));
    }

    public function testTrimMapsRecursivelyOverArrays(): void
    {
        $input = [
            'a' => '  one  ',
            'b' => ['  two  ', '  three  '],
        ];
        $result = InputSanitizer::trim($input);
        $this->assertSame('one', $result['a']);
        $this->assertSame('two', $result['b'][0]);
        $this->assertSame('three', $result['b'][1]);
    }

    public function testTrimPassesThroughNonStringScalars(): void
    {
        $this->assertSame(7, InputSanitizer::trim(7));
        $this->assertSame(false, InputSanitizer::trim(false));
        $this->assertNull(InputSanitizer::trim(null));
    }

    public function testAlphanumericAcceptsValidSlug(): void
    {
        $this->assertSame('my-plugin_v2', InputSanitizer::alphanumeric('my-plugin_v2'));
    }

    public function testAlphanumericReturnsNullForEmptyString(): void
    {
        $this->assertNull(InputSanitizer::alphanumeric(''));
        $this->assertNull(InputSanitizer::alphanumeric('   '));
    }

    public function testAlphanumericRejectsSpecialCharacters(): void
    {
        $this->assertNull(InputSanitizer::alphanumeric('foo bar'));
        $this->assertNull(InputSanitizer::alphanumeric('foo!bar'));
        $this->assertNull(InputSanitizer::alphanumeric('foo/bar'));
        $this->assertNull(InputSanitizer::alphanumeric('foo<bar>'));
    }

    public function testAlphanumericTrimsBeforeValidation(): void
    {
        $this->assertSame('valid-slug', InputSanitizer::alphanumeric('  valid-slug  '));
    }
}
